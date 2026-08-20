<?php
/**
 * Cliente da API Vavi/PrintBee.
 *
 * Autenticação (client_credentials) + cache do token em transient.
 * O token da Vavi expira em ~1h; guardamos com 50min de TTL e renovamos
 * automaticamente quando expira (ou sob demanda, se 401 ao usar).
 *
 * Regra de ouro: NUNCA logar token nem segredo.
 */
class VAVI_Api_Client {

	const TOKEN_TRANSIENT = 'vavi_access_token';
	const AUTH_URL         = VAVI_API_BASE . '/api/Auth/Token';
	const SIMULATE_URL     = VAVI_API_BASE . '/api/Customers/Simulate';
	const CREATE_ORDER_URL = VAVI_API_BASE . '/api/Customers/Integrations/Orders';

	/**
	 * Obtém token Bearer válido (usa cache ou renova).
	 */
	public static function get_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached ) {
			return $cached;
		}

		if ( ! VAVI_Config::has_credentials() ) {
			return '';
		}

		$response = wp_remote_post(
			self::AUTH_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grantType'    => 'client_credentials',
						'clientId'     => VAVI_Config::client_id(),
						'clientSecret' => VAVI_Config::client_secret(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		// A resposta vem aninhada: { "data": { "access_token": ... } }
		$data  = isset( $body['data'] ) ? $body['data'] : array();
		$token = isset( $data['access_token'] ) ? $data['access_token'] : '';
		if ( ! $token ) {
			return '';
		}

		set_transient( self::TOKEN_TRANSIENT, $token, 50 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Força a renovação do token em cache (após 401, por exemplo).
	 */
	public static function refresh_token() {
		delete_transient( self::TOKEN_TRANSIENT );
		return self::get_token();
	}

	/**
	 * Simula o frete para um CEP.
	 *
	 * @param string $zip   CEP de destino (ex: 01310-100).
	 * @param array  $items Itens com peso/dimensões opcionais.
	 * @return array Lista de opções de frete (SimulationModel), ou [] em erro.
	 */
	public static function simulate( $zip, $items = array() ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array();
		}

		$zip = preg_replace( '/\D/', '', (string) $zip );
		if ( 8 !== strlen( $zip ) ) {
			return array();
		}

		$amount      = 0.0;
		$composicoes = array();
		$peso_total  = 0.0;
		$altura      = 0;
		$largura     = 0;
		$comprimento = 0;

		foreach ( (array) $items as $it ) {
			$qtd   = max( 1, (int) ( isset( $it['quantity'] ) ? $it['quantity'] : 1 ) );
			$preco = isset( $it['price'] ) ? (float) $it['price'] : 0.0;
			$amount += $preco * $qtd;

			$peso = isset( $it['weight'] ) ? (float) $it['weight'] : 0.3;
			$h    = isset( $it['height'] ) ? (float) $it['height'] : 10;
			$w    = isset( $it['width'] ) ? (float) $it['width'] : 20;
			$l    = isset( $it['length'] ) ? (float) $it['length'] : 30;

			$peso_total += $peso * $qtd;
			$altura      = max( $altura, $h );
			$largura     = max( $largura, $w );
			$comprimento = max( $comprimento, $l );

			// O campo `id` da composition é um GUID (System.Guid). O id numérico
			// do produto Woo NÃO é aceito. Gera UUID v4 se não for GUID válido.
			$compo_id = isset( $it['id'] ) ? (string) $it['id'] : '';
			if ( ! self::is_guid( $compo_id ) ) {
				$compo_id = wp_generate_uuid4();
			}

			$composicoes[] = array(
				'id'       => $compo_id,
				'sku'      => isset( $it['sku'] ) ? (string) $it['sku'] : '',
				'amount'   => $preco,
				'quantity' => $qtd,
				'weight'   => $peso,
				'height'   => $h,
				'width'    => $w,
				'length'   => $l,
			);
		}

		if ( $amount <= 0 ) {
			return array();
		}

		$payload = array(
			'zipCode' => $zip,
			'amount'  => round( $amount, 2 ),
		);
		if ( $peso_total > 0 ) {
			$payload['weight'] = round( $peso_total, 3 );
		}
		if ( $altura > 0 ) {
			$payload['height'] = $altura;
			$payload['width']  = $largura;
			$payload['length'] = $comprimento;
		}
		if ( $composicoes ) {
			$payload['compositions'] = $composicoes;
		}

		return self::post_json( self::SIMULATE_URL, $payload, $token, true );
	}

	/**
	 * POST JSON com retry em 401 (renova token), retornando o `data` (ou body).
	 *
	 * @param bool $retry Se true, renova token e tenta uma vez em 401.
	 */
	public static function post_json( $url, $payload, $token, $retry = false ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $retry ) {
			// Token expirado — tenta uma segunda vez com token renovado.
			self::refresh_token();
			$new_token = self::get_token();
			if ( $new_token ) {
				return self::post_json( $url, $payload, $new_token, false );
			}
			return array();
		}
		if ( ! in_array( $code, array( 200, 202 ), true ) ) {
			return array( 'http_code' => $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = ( is_array( $body ) && isset( $body['data'] ) ) ? $body['data'] : $body;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Verifica se uma string é um GUID/UUID válido.
	 */
	private static function is_guid( $value ) {
		return 1 === preg_match(
			'/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
			(string) $value
		);
	}
}
