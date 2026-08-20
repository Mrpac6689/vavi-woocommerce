<?php
/**
 * Registra o webhook na Vavi via API (POST /api/v1/Customers/{customerId}/Webhooks).
 *
 * Usado pelo botão "Registrar webhook na Vavi" na tela de configuração.
 * Requer Client ID/Secret (para o token) e Customer ID.
 */
class VAVI_Webhook_Registrar {

	const REGISTER_URL = VAVI_API_BASE . '/api/v1/Customers/%s/Webhooks';

	/**
	 * Hooks internos (por enquanto nenhum; usado sob demanda pelo admin).
	 */
	public static function init() {
		// Vazio de propósito — registro ocorre via handle_register_webhook().
	}

	/**
	 * Registra (ou recria) o webhook apontando para o endpoint deste plugin.
	 *
	 * @return bool true em sucesso.
	 */
	public static function register() {
		$customer_id = VAVI_Config::customer_id();
		if ( ! $customer_id ) {
			return false;
		}
		$token = VAVI_Api_Client::get_token();
		if ( ! $token ) {
			return false;
		}

		$events = apply_filters(
			'vavi_webhook_events',
			array(
				'integration_order.received',
				'integration_order.converted',
				'integration_order.failed',
				'integration_order.discarded',
				'integration_order.reopened',
				'order.created',
				'order.status_changed',
				'order.tracking',
			)
		);

		$payload = array(
			'url'        => rest_url( 'vavi/v1/printbee-webhook' ),
			'eventTypes' => $events,
		);

		$url = sprintf( self::REGISTER_URL, rawurlencode( $customer_id ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return in_array( $code, array( 200, 201, 204 ), true );
	}
}
