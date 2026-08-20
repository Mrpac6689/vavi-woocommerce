<?php
/**
 * Método de envio WooCommerce que consome POST /api/Customers/Simulate.
 *
 * Aparece no checkout como "vavi_freight". Se um produto tem frete Vavi
 * habilitado, o método oferece as cotações reais por CEP; caso contrário,
 * cai no free_shipping (ou outro método da zona).
 */
class VAVI_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id           = 'vavi_freight';
		$this->instance_id  = absint( $instance_id );
		$this->method_title = __( 'Vavi / PrintBee', 'vavi-woocommerce' );
		$this->title        = isset( $this->settings['title'] ) ? $this->settings['title'] : $this->method_title;
		$this->supports     = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();

		$this->enabled = $this->carrinho_tem_frete_vavi() ? 'yes' : 'no';
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'title'          => array(
				'title'       => __( 'Título', 'vavi-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Título exibido ao cliente no checkout.', 'vavi-woocommerce' ),
				'default'     => __( 'Frete real', 'vavi-woocommerce' ),
			),
			'freight_markup' => array(
				'title'       => __( 'Margem sobre o frete (R$)', 'vavi-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Valor a somar sobre o frete real da Vavi. Deixe 0 para repassar o custo real.', 'vavi-woocommerce' ),
				'default'     => 0,
			),
			'include_pickup' => array(
				'title'       => __( 'Incluir "Retirada na Fábrica"', 'vavi-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Mostra a opção de retirada na fábrica (R$ 0).', 'vavi-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	public function calculate_shipping( $package = array() ) {
		$zip = $this->get_destination_zip( $package );
		if ( ! $zip ) {
			return;
		}

		$items = array();
		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = array(
				'id'       => $product->get_id(),
				'sku'      => $product->get_sku(),
				'price'    => (float) $product->get_price(),
				'quantity' => (int) $item['quantity'],
				'weight'   => $product->get_weight() ? (float) $product->get_weight() : 0.3,
			);
		}

		$options = VAVI_Api_Client::simulate( $zip, $items );
		if ( empty( $options ) ) {
			return;
		}

		$markup        = (float) ( isset( $this->settings['freight_markup'] ) ? $this->settings['freight_markup'] : 0 );
		$include_pickup = 'yes' === ( isset( $this->settings['include_pickup'] ) ? $this->settings['include_pickup'] : 'no' );

		foreach ( $options as $opt ) {
			if ( isset( $opt['isActive'] ) && empty( $opt['isActive'] ) ) {
				continue;
			}

			$nome    = isset( $opt['name'] ) ? $opt['name'] : 'Frete';
			$empresa = isset( $opt['companyName'] ) ? $opt['companyName'] : '';
			$custo   = (float) ( isset( $opt['shippingCost'] ) ? $opt['shippingCost'] : 0 );
			$prazo   = isset( $opt['deliveryTime'] ) ? (int) $opt['deliveryTime'] : 0;
			$id      = isset( $opt['id'] ) ? (string) $opt['id'] : 'x';

			// "Retirada na Fábrica" só se o admin optou por exibir.
			if ( '0' === $id && ! $include_pickup ) {
				continue;
			}

			$custo_final = $custo + $markup;

			$label = $empresa ? "{$empresa} — {$nome}" : $nome;
			if ( $prazo > 0 ) {
				$label .= sprintf( ' · %d dia%s', $prazo, $prazo > 1 ? 's' : '' );
			}

			$this->add_rate(
				array(
					'id'        => $this->id . ':' . $id,
					'label'     => $label,
					'cost'      => round( $custo_final, 2 ),
					'meta_data' => array(
						'vavi_option_id'     => $id,
						'vavi_company'       => $empresa,
						'vavi_service'       => $nome,
						'vavi_delivery_time' => $prazo,
					),
				)
			);
		}
	}

	/**
	 * Há ao menos um item com frete Vavi habilitado no carrinho?
	 */
	private function carrinho_tem_frete_vavi() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return true;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( VAVI_Product_Meta::is_enabled( $item['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function get_destination_zip( $package ) {
		$zip = isset( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : '';
		if ( ! $zip ) {
			$zip = WC()->customer ? WC()->customer->get_shipping_postcode() : '';
		}
		return preg_replace( '/\D/', '', (string) $zip );
	}
}
