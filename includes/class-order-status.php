<?php
/**
 * Status customizados do pedido, mapeados para o ciclo de produção/entrega
 * da Vavi (OrderStatus). Sempre com notificação ao cliente.
 *
 * Mapeamento OrderStatus (Vavi) → status Woo — ver statuses().
 */
class VAVI_Order_Status {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses_to_list' ) );
	}

	/**
	 * Tabela de mapeamento: status da API Vavi → dados do status Woo.
	 */
	public static function statuses() {
		return array(
			'vavi-production-queue'    => array(
				'label'        => 'Aguardando Produção',
				'public'       => false,
				'vavi'         => 'PRODUCTIONQUEUE',
				'email_subject' => 'Seu pedido {number} entrou na fila de produção',
				'email_body'    => 'Seu pedido {number} foi recebido e aguarda o início da produção. Assim que a produção começar, avisamos você.',
			),
			'vavi-production'          => array(
				'label'        => 'Em Produção',
				'public'       => true,
				'vavi'         => 'PRODUCTION',
				'email_subject' => 'Seu pedido {number} está em produção',
				'email_body'    => 'Boa notícia! Seu pedido {number} está sendo produzido. Vamos te avisar quando for despachado.',
			),
			'vavi-production-done'     => array(
				'label'        => 'Produção Concluída',
				'public'       => true,
				'vavi'         => 'PRODUCTIONCOMPLETED',
				'email_subject' => 'Produção do seu pedido {number} concluída',
				'email_body'    => 'Seu pedido {number} foi produzido e está sendo preparado para envio. Em breve você recebe o código de rastreio.',
			),
			'vavi-waiting-payment'     => array(
				'label'        => 'Aguardando Pagamento Final',
				'public'       => false,
				'vavi'         => 'WAITINGFINALPAYMENT',
				'email_subject' => 'Atualização do seu pedido {number}',
				'email_body'    => 'Seu pedido {number} está aguardando confirmação de pagamento.',
			),
			'vavi-dispatch'            => array(
				'label'        => 'Despachado para Transportadora',
				'public'       => true,
				'vavi'         => 'DISPATCH',
				'email_subject' => 'Seu pedido {number} foi despachado!',
				'email_body'    => 'Seu pedido {number} foi entregue à transportadora e está a caminho. Acompanhe o rastreio pela sua conta.',
			),
			'vavi-transit'             => array(
				'label'        => 'Em Trânsito',
				'public'       => true,
				'vavi'         => 'TRANSIT',
				'email_subject' => 'Seu pedido {number} está a caminho',
				'email_body'    => 'Seu pedido {number} está em trânsito. O código de rastreio está disponível na sua conta.',
			),
			'vavi-delivered'           => array(
				'label'        => 'Entregue',
				'public'       => true,
				'vavi'         => 'DELIVERED',
				'email_subject' => 'Seu pedido {number} foi entregue!',
				'email_body'    => 'Seu pedido {number} foi entregue. Esperamos que você ame sua peça. Agradecemos pela sua compra!',
			),
			'vavi-lost'                => array(
				'label'        => 'Pedido Extraviado',
				'public'       => false,
				'vavi'         => 'LOST',
				'email_subject' => 'Precisamos conversar sobre seu pedido {number}',
				'email_body'    => 'Identificamos um problema com a transportadora no seu pedido {number}. Nossa equipe vai entrar em contato para resolver.',
			),
			'vavi-returned'            => array(
				'label'        => 'Devolvido ao Remetente',
				'public'       => false,
				'vavi'         => 'RETURNED',
				'email_subject' => 'Seu pedido {number} voltou',
				'email_body'    => 'Seu pedido {number} retornou ao remetente. Nossa equipe vai entrar em contato para combinar o reenvio ou o reembolso.',
			),
		);
	}

	public static function register_statuses() {
		foreach ( self::statuses() as $slug => $cfg ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $cfg['label'],
					'public'                    => $cfg['public'],
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => _n_noop( $cfg['label'] . ' <span class="count">(%s)</span>', $cfg['label'] . ' <span class="count">(%s)</span>', 'woocommerce' ),
				)
			);
		}
	}

	public static function add_statuses_to_list( $statuses ) {
		foreach ( self::statuses() as $slug => $cfg ) {
			$statuses[ 'wc-' . $slug ] = $cfg['label'];
		}
		return $statuses;
	}

	/**
	 * Aplica um status Vavi (ex: 'PRODUCTION', 'TRANSIT') ao pedido.
	 */
	public static function apply_vavi_status( $order, $vavi_status, $extra = array() ) {
		$map = self::find_by_vavi( $vavi_status );
		if ( ! $map ) {
			return false;
		}
		$slug = $map['slug'];
		$cfg  = $map['cfg'];

		if ( 'DELIVERED' === $vavi_status ) {
			$order->update_status( 'completed', 'Segundo webhook Vavi: entregue.', true );
			return true;
		}
		if ( 'CANCELED' === $vavi_status ) {
			$order->update_status( 'cancelled', 'Segundo webhook Vavi: cancelado.', true );
			return true;
		}
		if ( 'ATTENDED' === $vavi_status ) {
			$order->update_status( 'completed', 'Segundo webhook Vavi: atendido.', true );
			return true;
		}

		$order->update_status( $slug );

		if ( ! empty( $extra['tracking_code'] ) ) {
			$order->update_meta_data( '_vavi_tracking_code', $extra['tracking_code'] );
		}
		if ( ! empty( $extra['tracking_url'] ) ) {
			$order->update_meta_data( '_vavi_tracking_url', $extra['tracking_url'] );
		}
		$order->save();

		self::notify( $order, $cfg );
		return true;
	}

	private static function find_by_vavi( $vavi_status ) {
		foreach ( self::statuses() as $slug => $cfg ) {
			if ( $cfg['vavi'] === $vavi_status ) {
				return array( 'slug' => $slug, 'cfg' => $cfg );
			}
		}
		return null;
	}

	/**
	 * Wrapper público usado pelo webhook handler para notificar envio.
	 */
	public static function notify_shipment( $order, $cfg ) {
		self::notify( $order, $cfg );
	}

	/**
	 * Envia e-mail ao cliente com o template da mudança.
	 */
	private static function notify( $order, $cfg ) {
		if ( ! $order || ! $order->get_billing_email() ) {
			return;
		}
		$number  = $order->get_order_number();
		$subject = str_replace( '{number}', $number, $cfg['email_subject'] );
		$body    = str_replace( '{number}', $number, $cfg['email_body'] );
		$body   .= "\n\nLink para acompanhar: " . wc_get_page_permalink( 'myaccount' );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$html    = '<p>' . nl2br( esc_html( $body ) ) . '</p>';
		wc_mail( $order->get_billing_email(), $subject, $html, $headers );

		$order->add_order_note( $cfg['label'] . ' — notificado por e-mail.', true, true );
		$order->save();
	}
}
