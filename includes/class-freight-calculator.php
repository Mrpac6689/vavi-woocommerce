<?php
/**
 * Simulação de frete na PÁGINA DO PRODUTO.
 *
 * Widget "Calcule o frete para o seu CEP" exibido antes do "Adicionar ao
 * carrinho", apenas em produtos com _vavi_freight_enabled=yes. O cliente
 * digita o CEP, o JS chama o endpoint REST vavi/v1/simulate-freight, e as
 * opções aparecem sem sair da página.
 */
class VAVI_Freight_Calculator {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'render_widget' ), 20 );
	}

	public static function register_rest_route() {
		register_rest_route(
			'vavi/v1',
			'/simulate-freight',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_callback' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	public static function permission() {
		// Não envia X-WP-Nonce no JS: enviar esse header dispara a autenticação
		// por cookie do WP, que exige nonce wp_rest e falha com um nonce custom.
		// Validamos manualmente o nonce enviado no corpo (anti-abuso).
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vavi_freight' ) ) {
			return new WP_Error( 'bad_nonce', 'Nonce inválido.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_callback( WP_REST_Request $request ) {
		$zip = sanitize_text_field( $request->get_param( 'zip_code' ) );
		$pid = (int) $request->get_param( 'product_id' );

		if ( ! $pid || ! VAVI_Product_Meta::is_enabled( $pid ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Produto sem frete Vavi.' ), 200 );
		}

		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Produto não encontrado.' ), 404 );
		}

		// Se for variável, o preço default (mínimo) é a base da simulação.
		$price = (float) $product->get_price();
		$items = array(
			array(
				'id'       => $product->get_id(),
				'sku'      => $product->get_sku(),
				'price'    => $price,
				'quantity' => 1,
				'weight'   => $product->get_weight() ? (float) $product->get_weight() : 0.3,
			),
		);

		$options = VAVI_Api_Client::simulate( $zip, $items );
		if ( empty( $options ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Não foi possível calcular o frete para este CEP.' ),
				200
			);
		}

		// Filtra "Retirada na Fábrica" aqui também (não é o desejo no produto).
		$filtered = array();
		foreach ( $options as $o ) {
			if ( ! empty( $o['isActive'] ) && '0' !== (string) $o['id'] ) {
				$filtered[] = $o;
			}
		}

		return new WP_REST_Response( array( 'success' => true, 'options' => $filtered ), 200 );
	}

	public static function render_widget() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( ! VAVI_Product_Meta::is_enabled( $product->get_id() ) ) {
			return;
		}

		$currency = get_woocommerce_currency_symbol();
		?>
		<div class="vavi-freight" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<p class="vavi-freight__label">
				<strong><?php esc_html_e( 'Calcule o frete para o seu CEP', 'vavi-woocommerce' ); ?></strong>
			</p>
			<div class="vavi-freight__form">
				<input
					type="text"
					class="vavi-freight__cep"
					placeholder="00000-000"
					inputmode="numeric"
					maxlength="9"
					autocomplete="postal-code"
				/>
				<button type="button" class="vavi-freight__btn">
					<?php esc_html_e( 'Calcular', 'vavi-woocommerce' ); ?>
				</button>
			</div>
			<div class="vavi-freight__result" aria-live="polite"></div>
		</div>
		<?php
	}
}
