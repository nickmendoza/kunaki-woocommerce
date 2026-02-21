<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Product_Meta {

	const META_KEY = '_kunaki_product_id';

	public function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_field' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'display_validation_errors' ) );
	}

	public function render_field() {
		echo '<div class="options_group kunaki-product-options">';

		woocommerce_wp_text_input( array(
			'id'          => self::META_KEY,
			'label'       => __( 'Kunaki Product ID', 'kunaki-woocommerce' ),
			'placeholder' => __( 'e.g., PXZZ111111', 'kunaki-woocommerce' ),
			'desc_tip'    => true,
			'description' => __( 'Enter the 10-character Kunaki Product ID. Only products with this ID will be fulfilled through Kunaki.', 'kunaki-woocommerce' ),
		) );

		global $post;
		$kunaki_id = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! empty( $kunaki_id ) ) {
			$image_url = Kunaki_API_Client::get_product_image_url( $kunaki_id, 'BX' );
			echo '<p class="form-field kunaki-product-preview">';
			echo '<label>' . esc_html__( 'Kunaki Preview', 'kunaki-woocommerce' ) . '</label>';
			echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Kunaki Product', 'kunaki-woocommerce' ) . '" style="max-width:150px; max-height:150px;" />';
			echo '</p>';
		}

		echo '</div>';
	}

	public function save_field( $post_id ) {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$kunaki_id = isset( $_POST[ self::META_KEY ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) )
			: '';

		if ( '' !== $kunaki_id && ! preg_match( '/^[A-Za-z0-9]{10}$/', $kunaki_id ) ) {
			set_transient(
				'kunaki_wc_product_meta_error_' . get_current_user_id(),
				__( 'Kunaki Product ID must be exactly 10 alphanumeric characters.', 'kunaki-woocommerce' ),
				30
			);
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $kunaki_id );
	}

	public function display_validation_errors() {
		$transient_key = 'kunaki_wc_product_meta_error_' . get_current_user_id();
		$error         = get_transient( $transient_key );
		if ( $error ) {
			delete_transient( $transient_key );
			echo '<div class="error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	public function add_column( $columns ) {
		$columns['kunaki_product_id'] = __( 'Kunaki ID', 'kunaki-woocommerce' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'kunaki_product_id' === $column ) {
			$kunaki_id = get_post_meta( $post_id, self::META_KEY, true );
			echo esc_html( $kunaki_id ?: '---' );
		}
	}

	/**
	 * Get the Kunaki Product ID for a WooCommerce product.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string Kunaki Product ID or empty string.
	 */
	public static function get_kunaki_product_id( $product_id ) {
		return (string) get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Check if a WooCommerce product has a Kunaki Product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool
	 */
	public static function is_kunaki_product( $product_id ) {
		return '' !== self::get_kunaki_product_id( $product_id );
	}
}
