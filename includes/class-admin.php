<?php
/**
 * Administração do plugin: tela de configuração + página "Sobre".
 *
 * Tela principal: WooCommerce → Vavi (credenciais + um clique para registrar o
 *        webhook via API).
 * Página About: o que o plugin faz, como criar o webhook manualmente no portal,
 *        e o endereço do endpoint receptor.
 */
class VAVI_Admin {

	const MENU_SLUG = 'vavi-woocommerce';
	const ABOUT_SLUG = 'vavi-woocommerce-about';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		// Trata o formulário e as ações de registrar/testar webhook.
		add_action( 'admin_post_vavi_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_vavi_register_webhook', array( __CLASS__, 'handle_register_webhook' ) );
		add_action( 'admin_post_vavi_test_credentials', array( __CLASS__, 'handle_test_credentials' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Vavi / PrintBee', 'vavi-woocommerce' ),
			__( 'Vavi / PrintBee', 'vavi-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings' )
		);
		add_submenu_page(
			'woocommerce',
			__( 'Sobre Vavi WooCommerce', 'vavi-woocommerce' ),
			__( 'Sobre Vavi', 'vavi-woocommerce' ),
			'manage_woocommerce',
			self::ABOUT_SLUG,
			array( __CLASS__, 'render_about' )
		);
	}

	public static function enqueue_admin( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) && false === strpos( $hook, self::ABOUT_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'vavi-admin', VAVI_URL . 'assets/css/admin.css', array(), VAVI_VERSION );
	}

	/* ---------------------------------------------------------- *
	 * Formulário de configuração
	 * ---------------------------------------------------------- */

	public static function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$client_id     = VAVI_Config::client_id();
		$client_secret = VAVI_Config::client_secret();
		$webhook_secret = VAVI_Config::webhook_secret();
		$customer_id   = VAVI_Config::customer_id();

		echo '<div class="wrap vavi-admin">';
		echo '<h1>' . esc_html__( 'Integração Vavi / PrintBee', 'vavi-woocommerce' ) . '</h1>';

		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configurações salvas.', 'vavi-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['vavi_msg'] ) && 'registered' === $_GET['vavi_msg'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Webhook registrado na Vavi.', 'vavi-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['vavi_msg'] ) && 'authed' === $_GET['vavi_msg'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Credenciais válidas — conexão com a Vavi OK.', 'vavi-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['vavi_msg'] ) && 'failing' === $_GET['vavi_msg'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Não foi possível autenticar com as credenciais informadas.', 'vavi-woocommerce' ) . '</p></div>';
		}

		// Aviso se credenciais vêm de constante/env (não editáveis aqui).
		if ( defined( 'VAVI_CLIENT_ID' ) || getenv( 'VAVI_CLIENT_ID' ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'As credenciais estão definidas por constante/env e serão usadas com prioridade. Os campos abaixo não sobrescrevem enquanto a constante existir.', 'vavi-woocommerce' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="vavi_save_settings" />';
		wp_nonce_field( 'vavi_save_settings', 'vavi_nonce' );

		echo '<table class="form-table" role="presentation">';
		self::text_row( 'client_id', __( 'Client ID', 'vavi-woocommerce' ), $client_id, __( 'Encontrado no portal Vavi (começa com "pb_").', 'vavi-woocommerce' ) );
		self::text_row( 'client_secret', __( 'Client Secret', 'vavi-woocommerce' ), $client_secret, __( 'Segredo do cliente Vavi.', 'vavi-woocommerce' ), 'password' );
		self::text_row( 'customer_id', __( 'Customer ID', 'vavi-woocommerce' ), $customer_id, __( 'ID do cliente na Vavi — usado para registrar o webhook via API.', 'vavi-woocommerce' ) );
		self::text_row( 'webhook_secret', __( 'Webhook Secret', 'vavi-woocommerce' ), $webhook_secret, __( 'Segredo gerado pelo portal ao criar o webhook (começa com "whsec_").', 'vavi-woocommerce' ), 'password' );
		echo '</table>';

		echo '<p class="submit">';
		submit_button( __( 'Salvar configurações', 'vavi-woocommerce' ), 'primary', 'submit', false );
		echo '</p>';
		echo '</form>';

		// Ações de conexão e webhook.
		echo '<hr /><h2>' . esc_html__( 'Conexão e Webhook', 'vavi-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Endereço do seu endpoint receptor de webhooks:', 'vavi-woocommerce' ) . '</p>';
		echo '<p><code>' . esc_html( rest_url( 'vavi/v1/printbee-webhook' ) ) . '</code></p>';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Testar credenciais', 'vavi-woocommerce' ) . '</th><td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="vavi_test_credentials" />';
		wp_nonce_field( 'vavi_test_credentials', 'vavi_nonce2' );
		submit_button( __( 'Testar conexão', 'vavi-woocommerce' ), 'secondary', 'submit', false );
		echo '</form></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Registrar webhook', 'vavi-woocommerce' ) . '</th><td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="vavi_register_webhook" />';
		wp_nonce_field( 'vavi_register_webhook', 'vavi_nonce3' );
		submit_button( __( 'Registrar webhook na Vavi', 'vavi-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Cria/atualiza o webhook apontando para o endpoint acima (requer Client ID/Secret e Customer ID).', 'vavi-woocommerce' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';

		echo '</div>';
	}

	private static function text_row( $name, $label, $value, $desc = '', $type = 'text' ) {
		echo '<tr><th scope="row"><label for="vavi_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="' . esc_attr( $type ) . '" id="vavi_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/* ---------------------------------------------------------- *
	 * Handlers
	 * ---------------------------------------------------------- */

	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['vavi_nonce'] ) || ! wp_verify_nonce( $_POST['vavi_nonce'], 'vavi_save_settings' ) ) {
			wp_die( 'Nonce inválido' );
		}
		VAVI_Config::save_options(
			array(
				'client_id'      => isset( $_POST['client_id'] ) ? $_POST['client_id'] : '',
				'client_secret'  => isset( $_POST['client_secret'] ) ? $_POST['client_secret'] : '',
				'customer_id'    => isset( $_POST['customer_id'] ) ? $_POST['customer_id'] : '',
				'webhook_secret' => isset( $_POST['webhook_secret'] ) ? $_POST['webhook_secret'] : '',
			)
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_test_credentials() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['vavi_nonce2'] ) || ! wp_verify_nonce( $_POST['vavi_nonce2'], 'vavi_test_credentials' ) ) {
			wp_die( 'Nonce inválido' );
		}
		$ok = VAVI_Api_Client::get_token() ? 'authed' : 'failing';
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'vavi_msg' => $ok ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_register_webhook() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['vavi_nonce3'] ) || ! wp_verify_nonce( $_POST['vavi_nonce3'], 'vavi_register_webhook' ) ) {
			wp_die( 'Nonce inválido' );
		}
		$ok = VAVI_Webhook_Registrar::register();
		$msg = $ok ? 'registered' : 'failing';
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'vavi_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------- *
	 * Página "Sobre"
	 * ---------------------------------------------------------- */

	public static function render_about() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap vavi-admin">';
		echo '<h1>' . esc_html__( 'Sobre o Vavi WooCommerce', 'vavi-woocommerce' ) . '</h1>';

		echo '<h2>' . esc_html__( 'O que este plugin faz', 'vavi-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Integra sua loja WooCommerce com a API da Vavi Estampas/PrintBee em três frentes:', 'vavi-woocommerce' ) . '</p>';
		echo '<ul>';
		echo '<li><strong>' . esc_html__( 'Frete real por CEP', 'vavi-woocommerce' ) . '</strong> — ' . esc_html__( 'cotações reais das transportadoras no checkout e no widget da página do produto.', 'vavi-woocommerce' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Rascunho de pedido', 'vavi-woocommerce' ) . '</strong> — ' . esc_html__( 'o pedido pago é enviado à Vavi automaticamente, sem digitar manualmente no portal.', 'vavi-woocommerce' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Webhooks inbound', 'vavi-woocommerce' ) . '</strong> — ' . esc_html__( 'status de produção/entrega e rastreio chegam do portal e atualizam o pedido e o cliente.', 'vavi-woocommerce' ) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Como criar o webhook na Vavi', 'vavi-woocommerce' ) . '</h2>';

		// <options> de exemplo; as instruções estão num partial em HTML sem echo,
		// com o endpoint dinâmico.
		self::render_webhook_instructions_html();

		echo '<h2>' . esc_html__( 'Apoiar / contribuir', 'vavi-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Projeto open source (GPL-2.0). Reporte bugs, abra issues ou envie pull requests no repositório:', 'vavi-woocommerce' ) . '</p>';
		echo '<p><a href="https://github.com/Mrpac6689/vavi-woocommerce" target="_blank" rel="noopener">https://github.com/Mrpac6689/vavi-woocommerce</a></p>';

		echo '<h2>' . esc_html__( 'Upgrade / reversão', 'vavi-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Desativar o plugin volta a loja ao comportamento anterior (frete grátis), sem perder pedidos já enviados.', 'vavi-woocommerce' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Passo-a-passo visual de criação do webhook no portal Vavi.
	 */
	private static function render_webhook_instructions_html() {
		$endpoint = rest_url( 'vavi/v1/printbee-webhook' );
		?>
		<ol class="vavi-steps">
			<li>
				<strong><?php esc_html_e( 'Entre no portal da Vavi', 'vavi-woocommerce' ); ?></strong>
				<?php esc_html_e( 'Acesse a área de Integrações / Webhooks do seu painel.', 'vavi-woocommerce' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Cadastre uma URL de destino', 'vavi-woocommerce' ); ?></strong>
				<?php esc_html_e( 'Use o endereço do receptor deste plugin:', 'vavi-woocommerce' ); ?>
				<code><?php echo esc_html( $endpoint ); ?></code>
			</li>
			<li>
				<strong><?php esc_html_e( 'Selecione os eventos', 'vavi-woocommerce' ); ?></strong>
				<?php esc_html_e( 'Marque os eventos de pedido: order.created, order.status_changed, order.tracking e os integration_order.*.', 'vavi-woocommerce' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Guarde o Webhook Secret', 'vavi-woocommerce' ); ?></strong>
				<?php esc_html_e( 'O portal gera um secret (whsec_...). Cole-o no campo "Webhook Secret" da aba de configuração.', 'vavi-woocommerce' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Ative o webhook', 'vavi-woocommerce' ); ?></strong>
				<?php esc_html_e( 'Após ativar, o plugin valida a assinatura (HMAC-SHA256) de cada envio automaticamente.', 'vavi-woocommerce' ); ?>
			</li>
		</ol>
		<p><?php esc_html_e( 'Alternativa automática:', 'vavi-woocommerce' ); ?>
			<?php esc_html_e( 'se já preencheu o Customer ID, use o botão "Registrar webhook na Vavi" na tela de configuração — o plugin cria o webhook via API para você.', 'vavi-woocommerce' ); ?>
		</p>
		<?php
	}
}
