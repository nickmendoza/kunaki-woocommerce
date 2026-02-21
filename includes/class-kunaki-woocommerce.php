<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_WooCommerce {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	private function includes() {
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-logger.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-api-client.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-settings.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-product-meta.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-fulfillment.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-status-sync.php';
	}

	private function init_hooks() {
		// Shipping method.
		add_action( 'woocommerce_shipping_init', array( $this, 'load_shipping_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

		// Settings tab.
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );

		// Product meta.
		$product_meta = new Kunaki_Product_Meta();
		$product_meta->init();

		// Order fulfillment.
		$fulfillment = new Kunaki_Order_Fulfillment();
		$fulfillment->init();

		// Order status sync.
		$sync = new Kunaki_Order_Status_Sync();
		$sync->init();

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Plugin action links.
		add_filter( 'plugin_action_links_' . KUNAKI_WC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	public function load_shipping_method() {
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-shipping-method.php';
	}

	public function register_shipping_method( $methods ) {
		$methods['kunaki_shipping'] = 'WC_Shipping_Kunaki';
		return $methods;
	}

	public function add_settings_page( $settings ) {
		$settings[] = new Kunaki_Settings();
		return $settings;
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'product', 'woocommerce_page_wc-settings' ), true ) ) {
			wp_enqueue_style(
				'kunaki-admin',
				KUNAKI_WC_PLUGIN_URL . 'assets/css/kunaki-admin.css',
				array(),
				KUNAKI_WC_VERSION
			);
		}
	}

	public function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=kunaki' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'kunaki-woocommerce' ) . '</a>'
		);
		return $links;
	}
}
