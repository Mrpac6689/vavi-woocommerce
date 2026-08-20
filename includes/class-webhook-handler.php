<?php
/**
 * Endpoint receptor dos webhooks outbound da PrintBee/Vavi.
 *
 * Rota: POST /wp-json/vavi/v1/printbee-webhook
 *
 * Valida HMAC-SHA256 (X-Webhook-Signature), deduplica por webhookSentId,
 * responde 200 imediatamente e processa em cron (assíncrono).
 */
class VAVI_Webhook_Handler {

	const META_LOCK = '_vavi_webhook_lock';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'vavi_process_webhook', array( __CLASS__, 'process_async' ), 10, 2 );
	}

	public static function register_route() {
		register_rest_route(
			'vavi/v1',
			'/printbee-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // validação via HMAC no handle.
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$headers   = $request->get_headers();
		$signature = isset( $headers['x_webhook_signature'][0] ) ? $headers['x_webhook_signature'][0] : '';
		$raw_body  = $request->get_body();

		list( $ts, $hash ) = self::parse_signature( $signature );
		if ( ! $ts || ! $hash || ! self::verify_hmac( $raw_body, $ts, $hash ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid signature' ),
				401
			);
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		// Idempotência: deduplica por webhookSentId.
		$sent_id = isset( $payload['webhookSentId'] ) ? (string) $payload['webhookSentId'] : '';
		if ( $sent_id && self::already_processed( $sent_id ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}

		if ( $sent_id ) {
			self::mark_processing( $sent_id );
		}
		wp_schedule_single_event( time() + 1, 'vavi_process_webhook', array( $raw_body, $sent_id ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function process_async( $raw_body, $sent_id ) {
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		$event_type = isset( $payload['eventType'] ) ? (string) $payload['eventType'] : '';
		$data       = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		self::route( $event_type, $data );

		if ( $sent_id ) {
			self::mark_processed( $sent_id );
		}
	}

	private static function route( $event_type, $data ) {
		switch ( $event_type ) {
			case 'integration_order.received':
			case 'integration_order.discarded':
			case 'integration_order.failed':
			case 'integration_order.reopened':
				self::handle_integration_event( $data );
				break;

			case 'integration_order.converted':
				self::handle_integration_converted( $data );
				break;

			case 'order.status_changed':
				self::handle_status_changed( $data );
				break;

			case 'order.tracking':
				self::handle_tracking( $data );
				break;

			case 'order.created':
				self::handle_order_created( $data );
				break;

			default:
				error_log( '[vavi-woocommerce] Webhook ignorado: ' . $event_type );
				break;
		}
	}

	/**
	 * Encontra o pedido Woo pelo identificador Vavi.
	 */
	private static function find_order( $data ) {
		$candidatos = array();
		if ( ! empty( $data['externalId'] ) ) {
			$candidatos[] = (string) $data['externalId'];
		}
		if ( ! empty( $data['externalNumber'] ) ) {
			$candidatos[] = (string) $data['externalNumber'];
		}

		foreach ( $candidatos as $cand ) {
			if ( is_numeric( $cand ) ) {
				$o = wc_get_order( (int) $cand );
				if ( $o ) {
					return $o;
				}
			}
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => VAVI_Order_Creator::META_REQUEST_CLIENT_ID,
					'meta_value' => $cand,
				)
			);
			if ( $orders ) {
				return $orders[0];
			}
		}

		if ( ! empty( $data['orderNSU'] ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => VAVI_Order_Creator::META_ORDER_NSU,
					'meta_value' => (string) $data['orderNSU'],
				)
			);
			if ( $orders ) {
				return $orders[0];
			}
		}

		if ( ! empty( $data['orderId'] ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => VAVI_Order_Creator::META_ORDER_ID,
					'meta_value' => (string) $data['orderId'],
				)
			);
			if ( $orders ) {
				return $orders[0];
			}
		}

		return null;
	}

	private static function handle_order_created( $data ) {
		$order = self::find_order( $data );
		if ( ! $order ) {
			return;
		}
		if ( ! empty( $data['orderId'] ) ) {
			$order->update_meta_data( VAVI_Order_Creator::META_ORDER_ID, (string) $data['orderId'] );
		}
		if ( ! empty( $data['orderNSU'] ) ) {
			$order->update_meta_data( VAVI_Order_Creator::META_ORDER_NSU, (string) $data['orderNSU'] );
		}
		$order->add_order_note( 'Pedido real criado na Vavi (order.created).', false, true );
		$order->save();
	}

	private static function handle_status_changed( $data ) {
		$order = self::find_order( $data );
		if ( ! $order ) {
			return;
		}
		$new_status = isset( $data['newStatus'] ) ? (string) $data['newStatus'] : '';
		if ( $new_status ) {
			VAVI_Order_Status::apply_vavi_status( $order, $new_status );
		}
	}

	private static function handle_tracking( $data ) {
		$order = self::find_order( $data );
		if ( ! $order ) {
			return;
		}
		$tracking = isset( $data['tracking'] ) && is_array( $data['tracking'] ) ? $data['tracking'] : array();

		$tracking_code   = isset( $tracking['trackingCode'] ) ? (string) $tracking['trackingCode'] : '';
		$tracking_url    = isset( $tracking['trackingUrl'] ) ? (string) $tracking['trackingUrl'] : '';
		$shipment_status = isset( $tracking['shipmentStatus'] ) ? (string) $tracking['shipmentStatus'] : '';

		if ( $tracking_code ) {
			$order->update_meta_data( '_vavi_tracking_code', $tracking_code );
		}
		if ( $tracking_url ) {
			$order->update_meta_data( '_vavi_tracking_url', $tracking_url );
		}
		$order->save();

		if ( $shipment_status ) {
			self::apply_shipment_status( $order, $shipment_status );
		} else {
			$order->add_order_note(
				sprintf( 'Rastreio: %s', $tracking_url ? $tracking_url : ( $tracking_code ? 'código ' . $tracking_code : '' ) ),
				false,
				true
			);
			$order->save();
		}
	}

	private static function apply_shipment_status( $order, $shipment_status ) {
		$map = array(
			'POSTED'         => 'vavi-dispatch',
			'INTRANSIT'      => 'vavi-transit',
			'OUTFORDELIVERY' => 'vavi-transit',
			'DELIVERED'      => 'completed',
			'LOST'           => 'vavi-lost',
			'RETURNING'      => 'vavi-returned',
			'RETURNED'       => 'vavi-returned',
		);
		if ( ! isset( $map[ $shipment_status ] ) ) {
			return;
		}
		if ( 'completed' === $map[ $shipment_status ] ) {
			$order->update_status( 'completed', 'Segundo webhook Vavi: entregue.', true );
			return;
		}
		$slug = $map[ $shipment_status ];
		$order->update_status( $slug );
		$cfg = VAVI_Order_Status::statuses();
		if ( isset( $cfg[ $slug ] ) ) {
			VAVI_Order_Status::notify_shipment( $order, $cfg[ $slug ] );
		}
	}

	private static function handle_integration_converted( $data ) {
		$order = self::find_order( $data );
		if ( ! $order ) {
			return;
		}
		if ( ! empty( $data['orderId'] ) ) {
			$order->update_meta_data( VAVI_Order_Creator::META_ORDER_ID, (string) $data['orderId'] );
		}
		if ( ! empty( $data['orderNSU'] ) ) {
			$order->update_meta_data( VAVI_Order_Creator::META_ORDER_NSU, (string) $data['orderNSU'] );
		}
		$order->add_order_note( 'Rascunho convertido em pedido real na Vavi (integration_order.converted).', false, true );
		$order->save();
	}

	private static function handle_integration_event( $data ) {
		$order = self::find_order( $data );
		if ( ! $order ) {
			return;
		}
		$status = isset( $data['status'] ) ? (string) $data['status'] : '';
		$reason = isset( $data['failureReason'] ) ? (string) $data['failureReason'] : '';
		$note   = $status ? "Status do rascunho Vavi: {$status}." : 'Evento Vavi recebido.';
		if ( $reason ) {
			$note .= ' Motivo: ' . $reason;
		}
		$order->add_order_note( $note, false, true );
		$order->save();
	}

	/* ---------- HMAC / deduplicação ---------- */

	private static function parse_signature( $signature ) {
		$ts   = '';
		$hash = '';
		foreach ( explode( ',', (string) $signature ) as $part ) {
			$part = trim( $part );
			if ( 0 === strpos( $part, 't=' ) ) {
				$ts = substr( $part, 2 );
			} elseif ( 0 === strpos( $part, 'v1=' ) ) {
				$hash = substr( $part, 3 );
			}
		}
		return array( $ts, $hash );
	}

	private static function verify_hmac( $raw_body, $ts, $hash ) {
		$secret = VAVI_Config::webhook_secret();
		if ( ! $secret ) {
			return false;
		}
		$signed = $ts . '.' . $raw_body;
		$calc   = hash_hmac( 'sha256', $signed, $secret );
		return hash_equals( strtolower( $calc ), strtolower( $hash ) );
	}

	private static function already_processed( $sent_id ) {
		return get_option( 'vavi_webhook_' . $sent_id, false );
	}

	private static function mark_processing( $sent_id ) {
		if ( ! self::already_processed( $sent_id ) ) {
			update_option( 'vavi_webhook_' . $sent_id, 'processing', false );
		}
	}

	private static function mark_processed( $sent_id ) {
		update_option( 'vavi_webhook_' . $sent_id, time(), false );
	}
}
