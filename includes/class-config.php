<?php
/**
 * Configuração central do plugin.
 *
 * Prioridade de leitura (maior primeiro):
 *   1. Constantes PHP (VAVI_CLIENT_ID, VAVI_CLIENT_SECRET, VAVI_WEBHOOK_SECRET)
 *   2. Variáveis de ambiente (mesmos nomes) — útil para WP-CLI / CI
 *   3. Opções salvas no admin (Settings API) — padrão para a maioria das lojas
 *
 * Além das credenciais, concentra os MAPEAMENTOS desacoplados:
 *   - meta keys de endereço brasileiro (number, neighborhood, cpf)
 *   - nomes das variações a enviar como "properties" (ex: Cor, Tamanho)
 */
class VAVI_Config {

	const OPTION_KEY = 'vavi_settings';

	/**
	 * Retorna o valor de uma config: constante → env → option.
	 *
	 * @param string $key        Chave da configuração.
	 * @param string $const_name Nome da constante PHP (maiúsculo).
	 * @param mixed  $default    Valor padrão.
	 */
	private static function get( $key, $const_name, $default = '' ) {
		if ( defined( $const_name ) && constant( $const_name ) ) {
			return (string) constant( $const_name );
		}
		$env = getenv( $const_name );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		$opts = get_option( self::OPTION_KEY, array() );
		if ( is_array( $opts ) && isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
			return (string) $opts[ $key ];
		}
		return $default;
	}

	public static function client_id() {
		return self::get( 'client_id', 'VAVI_CLIENT_ID' );
	}

	public static function client_secret() {
		return self::get( 'client_secret', 'VAVI_CLIENT_SECRET' );
	}

	public static function webhook_secret() {
		return self::get( 'webhook_secret', 'VAVI_WEBHOOK_SECRET' );
	}

	/**
	 * ID do cliente (customerId) na Vavi — usado no registro do webhook via API
	 * (POST /api/v1/Customers/{customerId}/Webhooks).
	 */
	public static function customer_id() {
		return self::get( 'customer_id', 'VAVI_CUSTOMER_ID' );
	}

	public static function has_credentials() {
		return self::client_id() && self::client_secret();
	}

	public static function has_webhook_secret() {
		return (bool) self::webhook_secret();
	}

	/**
	 * Salva as opções do admin (lastro principal para quem usa tela de config).
	 */
	public static function save_options( array $opts ) {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$allowed = array( 'client_id', 'client_secret', 'webhook_secret', 'customer_id' );
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $opts ) ) {
				$current[ $k ] = sanitize_text_field( (string) $opts[ $k ] );
			}
		}
		update_option( self::OPTION_KEY, $current );
	}

	/* ---------------------------------------------------------- *
	 * Mapeamentos de endereço (padrões brasileiros, configuráveis)
	 * ---------------------------------------------------------- */

	/**
	 * Meta keys WooCommerce usadas para montar o endereço de entrega enviado à
	 * Vavi. Padrões são os campos de checkout brasileiro (billing_*).
	 * Sobrescrevível por filtro vavi_address_meta_keys.
	 */
	public static function address_meta_keys() {
		$defaults = array(
			'number'       => '_billing_number',
			'neighborhood' => '_billing_neighborhood',
			'cpf'          => '_billing_cpf',
		);
		return apply_filters( 'vavi_address_meta_keys', $defaults );
	}

	/**
	 * Nomes das variações (properties) enviadas no item do pedido.
	 * Padrão brasileiro: Cor / Tamanho. Configurável por filtro
	 * vavi_variation_meta_keys.
	 */
	public static function variation_props() {
		$defaults = array( 'Cor', 'Tamanho' );
		return apply_filters( 'vavi_variation_meta_keys', $defaults );
	}

	/**
	 * Prefixo dos metas salvos no pedido Woo (mantém compat. com legado).
	 */
	public static function meta_prefix() {
		return apply_filters( 'vavi_meta_prefix', '_vavi_' );
	}
}
