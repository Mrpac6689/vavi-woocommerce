<?php
/**
 * Meta box no admin do produto: "Usar frete real Vavi".
 *
 * Permite selecionar, por produto, se ele usa o frete real por CEP (Vavi)
 * ou o comportamento atual (frete grátis / default da loja). Padrão: grátis.
 * O objetivo é permitir migração progressiva — um produto por vez.
 */
class VAVI_Product_Meta {

	const META_KEY = '_vavi_freight_enabled';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'shipping_tab_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_shipping_tab' ) );
	}

	public static function add_meta_box() {
		add_meta_box(
			'vavi_freight_meta',
			__( 'Frete Vavi (API)', 'vavi-woocommerce' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'side',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'vavi_freight', 'vavi_freight_nonce' );
		$val = get_post_meta( $post->ID, self::META_KEY, true );
		?>
		<label style="display:block;margin-bottom:8px;">
			<input type="checkbox" name="vavi_freight_enabled" value="1" <?php checked( 'yes' === $val ); ?> />
			<strong><?php esc_html_e( 'Usar frete real Vavi (por CEP)', 'vavi-woocommerce' ); ?></strong>
		</label>
		<p class="description">
			<?php esc_html_e( 'Com isto ligado, o checkout calcula o frete real via API da Vavi para o CEP do cliente. Sem isto (padrão), o produto usa o frete grátis / default da loja.', 'vavi-woocommerce' ); ?>
		</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['vavi_freight_nonce'] ) || ! wp_verify_nonce( $_POST['vavi_freight_nonce'], 'vavi_freight' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$val = isset( $_POST['vavi_freight_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_KEY, $val );
	}

	/**
	 * Campo também na aba "Envio" (product data) — conveniência.
	 */
	public static function shipping_tab_field() {
		global $post;
		$val = get_post_meta( $post->ID, self::META_KEY, true );
		woocommerce_wp_checkbox(
			array(
				'id'          => 'vavi_freight_enabled_tab',
				'label'       => __( 'Usar frete real Vavi (por CEP)', 'vavi-woocommerce' ),
				'description' => __( 'Calcula o frete pela API da Vavi. Sem isto, usa frete grátis.', 'vavi-woocommerce' ),
				'value'       => 'yes' === $val ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
	}

	public static function save_shipping_tab( $post_id ) {
		$val = isset( $_POST['vavi_freight_enabled_tab'] ) && 'yes' === $_POST['vavi_freight_enabled_tab'] ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_KEY, $val );
	}

	/**
	 * Helper público usado por outras classes.
	 */
	public static function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_KEY, true );
	}
}
