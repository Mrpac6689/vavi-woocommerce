<?php
/**
 * Plugin Name: Vavi WooCommerce
 * Description: Integra sua loja WooCommerce com a API da Vavi Estampas/PrintBee: frete real por CEP (Simulate), criação de rascunho de pedido, status customizados e webhooks inbound com notificação ao cliente. Genérico — funciona em qualquer loja WooCommerce.
 * Version:     1.0.0
 * Author:      WooCommerce Dev
 * Author URI:  https://github.com/Mrpac6689
 * Text Domain: vavi-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 11.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VAVI_VERSION', '1.0.0' );
define( 'VAVI_FILE', __FILE__ );
define( 'VAVI_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAVI_URL', plugin_dir_url( __FILE__ ) );
define( 'VAVI_API_BASE', 'https://api.printbee.com.br' );

/**
 * Autoloader enxuto das classes do plugin.
 * Prefixo VAVI_ → includes/class-{slug}.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'VAVI_';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
		$file = VAVI_DIR . 'includes/class-' . $slug . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return; // WooCommerce necessário.
		}
		// O método de envio não é inicializado aqui: o Woo instancia via
		// woocommerce_shipping_methods quando calcula o frete.
		VAVI_Admin::init();
		VAVI_Order_Creator::init();
		VAVI_Order_Status::init();
		VAVI_Webhook_Handler::init();
		VAVI_Product_Meta::init();
		VAVI_Freight_Calculator::init();
		VAVI_Webhook_Registrar::init();
	}
);

/**
 * Registra o método de envio do WooCommerce.
 */
add_filter( 'woocommerce_shipping_methods', 'vavi_register_shipping_method' );
function vavi_register_shipping_method( $methods ) {
	$methods['vavi_freight'] = 'VAVI_Shipping_Method';
	return $methods;
}

/**
 * Remove o "Frete grátis" quando HÁ QUALQUER item de frete pago (Vavi) no
 * carrinho. Assim o frete pago sobrepõe o grátis em carrinho misto — o cliente
 * não consegue "burlar" o frete levando um produto grátis junto.
 */
add_filter( 'woocommerce_package_rates', 'vavi_restringir_frete_gratis', 10, 2 );
function vavi_restringir_frete_gratis( $rates, $package ) {
	if ( empty( $rates ) ) {
		return $rates;
	}
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return $rates;
	}
	$tem_vavi = false;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( VAVI_Product_Meta::is_enabled( $item['product_id'] ) ) {
			$tem_vavi = true;
			break;
		}
	}
	if ( ! $tem_vavi ) {
		return $rates; // só produtos grátis: mantém free_shipping
	}
	foreach ( $rates as $rate_key => $rate ) {
		if ( $rate instanceof WC_Shipping_Rate && 'free_shipping' === $rate->get_method_id() ) {
			unset( $rates[ $rate_key ] );
		}
	}
	return $rates;
}

/**
 * Enfileira o JS/CSS da simulação de frete na página do produto.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_product() ) {
			return;
		}
		wp_enqueue_script(
			'vavi-freight',
			VAVI_URL . 'assets/js/product-freight-calculator.js',
			array( 'jquery' ),
			filemtime( VAVI_DIR . 'assets/js/product-freight-calculator.js' ),
			true
		);
		wp_localize_script(
			'vavi-freight',
			'VAVI_WC',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'rest_url'   => rest_url( 'vavi/v1/simulate-freight' ),
				'nonce'      => wp_create_nonce( 'vavi_freight' ),
				'loading'    => __( 'Calculando...', 'vavi-woocommerce' ),
				'calculate'  => __( 'Calcular', 'vavi-woocommerce' ),
				'invalid_cep' => __( 'Digite um CEP válido (8 dígitos).', 'vavi-woocommerce' ),
				'no_options' => __( 'Nenhuma opção de frete disponível para este CEP.', 'vavi-woocommerce' ),
				'error'      => __( 'Não foi possível calcular o frete. Tente novamente.', 'vavi-woocommerce' ),
			)
		);
		wp_enqueue_style( 'vavi-freight', VAVI_URL . 'assets/css/product-freight-calculator.css', array(), filemtime( VAVI_DIR . 'assets/css/product-freight-calculator.css' ) );
	}
);
