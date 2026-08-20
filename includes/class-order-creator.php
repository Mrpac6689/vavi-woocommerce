<?php
/**
 * Cria o rascunho de pedido na Vavi via POST /api/Customers/Integrations/Orders
 * quando o pagamento é confirmado no WooCommerce.
 *
 * Envia o frete que o cliente PAGOU (freightAmount + externalShippingName) e o
 * total (paidAmount). Guarda o draftId no meta do pedido para rastreabilidade.
 * Idempotente: se o draftId já existir, não recria.
 *
 * Os campos de endereço e as variações são configuráveis (VAVI_Config).
 */
class VAVI_Order_Creator {

	const META_DRAFT_ID         = '_vavi_draft_id';
	const META_ORDER_ID         = '_vavi_order_id';
	const META_ORDER_NSU        = '_vavi_order_nsu';
	const META_REQUEST_CLIENT_ID = '_vavi_order_request_client_id';

	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_processing' ), 20, 1 );
	}

	public static function on_payment_complete( $order_id ) {
		self::maybe_create( $order_id );
	}

	public static function on_order_processing( $order_id ) {
		self::maybe_create( $order_id );
	}

	/**
	 * Decide se o pedido deve criar rascunho: só se TODOS os itens têm frete Vavi.
	 */
	public static function should_create( $order ) {
		if ( $order->get_meta( self::META_DRAFT_ID ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! VAVI_Product_Meta::is_enabled( $item->get_product_id() ) ) {
				return false;
			}
		}
		return true;
	}

	public static function maybe_create( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! VAVI_Config::has_credentials() ) {
			return;
		}
		if ( ! self::should_create( $order ) ) {
			return;
		}

		$result = self::create( $order );

		if ( ! empty( $result['draftId'] ) ) {
			$order->update_meta_data( self::META_DRAFT_ID, $result['draftId'] );
			$order->update_meta_data( self::META_REQUEST_CLIENT_ID, (string) $order->get_id() );
			$order->add_order_note(
				sprintf( 'Rascunho enviado à Vavi (draftId %s).', $result['draftId'] ),
				false,
				true
			);
			$order->save();
			return $result['draftId'];
		}

		$order->add_order_note( '⚠️ Falha ao enviar rascunho à Vavi — verificar manualmente no portal.', false, true );
		$order->save();
		return null;
	}

	/**
	 * Monta e envia o payload. Retorna array( 'draftId', 'status', 'deduplicated' ) ou [].
	 */
	public static function create( $order ) {
		$token = VAVI_Api_Client::get_token();
		if ( ! $token ) {
			return array();
		}

		$buyer = array(
			'name'         => (string) $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			'email'        => (string) $order->get_billing_email(),
			'phone'        => preg_replace( '/\D/', '', (string) $order->get_billing_phone() ),
			'zipCode'      => preg_replace( '/\D/', '', (string) $order->get_shipping_postcode() ),
			'street'       => (string) $order->get_shipping_address_1(),
			'city'         => (string) $order->get_shipping_city(),
			'state'        => (string) $order->get_shipping_state(),
			'country'      => $order->get_shipping_country() ?: 'BR',
		);

		// Campos de endereço configuráveis (number/neighborhood/cpf).
		$keys = VAVI_Config::address_meta_keys();
		if ( isset( $keys['number'] ) ) {
			$buyer['number'] = (string) $order->get_meta( $keys['number'] );
		}
		if ( isset( $keys['complement'] ) ) {
			$buyer['complement'] = (string) $order->get_meta( $keys['complement'] );
		} else {
			$buyer['complement'] = (string) $order->get_shipping_address_2();
		}
		if ( isset( $keys['neighborhood'] ) ) {
			$buyer['neighborhood'] = (string) $order->get_meta( $keys['neighborhood'] );
		}
		if ( isset( $keys['cpf'] ) ) {
			$buyer['taxId'] = preg_replace( '/\D/', '', (string) $order->get_meta( $keys['cpf'] ) );
		}

		if ( trim( $buyer['name'] ) === ' ' ) {
			$buyer['name'] = (string) $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		}

		// Variações configuráveis (ex: Cor, Tamanho).
		$props_config = VAVI_Config::variation_props();

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			$props = array();
			foreach ( $props_config as $prop_name ) {
				$val = $item->get_meta( (string) $prop_name );
				if ( $val ) {
					$props[] = array( 'key' => (string) $prop_name, 'value' => (string) $val );
				}
			}

			$items[] = array(
				'name'       => (string) ( $product ? $product->get_name() : $item->get_name() ),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => (int) $item->get_quantity(),
				'unitPrice'  => (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() ),
				'properties' => $props,
			);
		}

		$payload = array(
			'code'            => (string) $order->get_order_number(),
			'requestClientId' => (string) $order->get_id(),
			'buyer'           => $buyer,
			'status'          => 'pago',
			'freightAmount'   => (float) $order->get_shipping_total(),
			'paidAmount'      => (float) $order->get_total(),
			'items'           => $items,
			'properties'      => array(
				array( 'key' => 'shipping_method', 'value' => self::shipping_method_label( $order ) ),
			),
		);

		$data = VAVI_Api_Client::post_json( VAVI_Api_Client::CREATE_ORDER_URL, $payload, $token, true );

		if ( isset( $data['http_code'] ) ) {
			return array();
		}

		return array(
			'draftId'      => isset( $data['draftId'] ) ? (string) $data['draftId'] : '',
			'status'       => isset( $data['status'] ) ? (string) $data['status'] : '',
			'deduplicated' => ! empty( $data['deduplicated'] ),
		);
	}

	private static function shipping_method_label( $order ) {
		$labels = array();
		foreach ( $order->get_shipping_methods() as $sm ) {
			$labels[] = (string) $sm->get_method_title();
		}
		return implode( ', ', $labels );
	}
}
