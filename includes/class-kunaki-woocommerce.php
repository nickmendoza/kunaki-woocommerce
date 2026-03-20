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
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-circuit-breaker.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-api-client.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-product-meta.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-fulfillment.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-status-sync.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-retry-queue.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-delivery-estimator.php';
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-dashboard-widget.php';
	}

	private function init_hooks() {
		// Shipping method.
		add_action( 'woocommerce_shipping_init', array( $this, 'load_shipping_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'split_shipping_packages' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 10, 2 );

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

		// Retry queue.
		$retry_queue = new Kunaki_Retry_Queue();
		$retry_queue->init();

		// Dashboard widget.
		$dashboard = new Kunaki_Dashboard_Widget();
		$dashboard->init();

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
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-settings.php';
		$settings[] = new Kunaki_Settings();
		return $settings;
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$css_screens = array( 'product', 'woocommerce_page_wc-settings', 'shop_order', 'woocommerce_page_kunaki-failed-orders', 'dashboard' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$css_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( in_array( $screen->id, $css_screens, true ) ) {
			wp_enqueue_style(
				'kunaki-admin',
				KUNAKI_WC_PLUGIN_URL . 'assets/css/kunaki-admin.css',
				array(),
				KUNAKI_WC_VERSION
			);
		}

		$retry_screens = array( 'shop_order', 'woocommerce_page_kunaki-failed-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$retry_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( in_array( $screen->id, $retry_screens, true ) ) {
			wp_enqueue_script(
				'kunaki-retry',
				KUNAKI_WC_PLUGIN_URL . 'assets/js/kunaki-retry.js',
				array( 'jquery' ),
				KUNAKI_WC_VERSION,
				true
			);
			wp_localize_script( 'kunaki-retry', 'kunakiRetry', array(
				'nonce' => wp_create_nonce( 'kunaki_retry_order' ),
				'i18n'  => array(
					'submitted'     => __( 'Submitted', 'kunaki-woocommerce' ),
					'requestFailed' => __( 'Request failed.', 'kunaki-woocommerce' ),
				),
			) );
		}
	}

	/**
	 * Split cart into separate shipping packages for Kunaki and non-Kunaki items.
	 *
	 * @param array $packages Default shipping packages.
	 * @return array Modified packages.
	 */
	public function split_shipping_packages( $packages ) {
		$split = array();

		foreach ( $packages as $package ) {
			$kunaki_items     = array();
			$non_kunaki_items = array();

			foreach ( $package['contents'] as $key => $item ) {
				if ( Kunaki_Product_Meta::is_kunaki_product( $item['product_id'] ) ) {
					$kunaki_items[ $key ] = $item;
				} else {
					$non_kunaki_items[ $key ] = $item;
				}
			}

			if ( ! empty( $kunaki_items ) && ! empty( $non_kunaki_items ) ) {
				$kunaki_package             = $package;
				$kunaki_package['contents'] = $kunaki_items;
				$kunaki_package['contents_cost'] = array_sum( wp_list_pluck( $kunaki_items, 'line_total' ) );
				$kunaki_package['package_name'] = __( 'Disc Shipping', 'kunaki-woocommerce' );
				$split[] = $kunaki_package;

				$other_package             = $package;
				$other_package['contents'] = $non_kunaki_items;
				$other_package['contents_cost'] = array_sum( wp_list_pluck( $non_kunaki_items, 'line_total' ) );
				$other_package['package_name'] = __( 'Other Items', 'kunaki-woocommerce' );
				$split[] = $other_package;
			} else {
				$split[] = $package;
			}
		}

		return $split;
	}

	/**
	 * Remove non-Kunaki shipping rates from packages that only contain Kunaki products,
	 * and remove Kunaki rates from packages with no Kunaki products.
	 *
	 * @param array $rates   Available shipping rates for this package.
	 * @param array $package The shipping package.
	 * @return array Filtered rates.
	 */
	public function filter_package_rates( $rates, $package ) {
		$has_kunaki     = false;
		$has_non_kunaki = false;

		foreach ( $package['contents'] as $item ) {
			if ( Kunaki_Product_Meta::is_kunaki_product( $item['product_id'] ) ) {
				$has_kunaki = true;
			} else {
				$has_non_kunaki = true;
			}
		}

		// Pure Kunaki package — remove non-Kunaki rates.
		if ( $has_kunaki && ! $has_non_kunaki ) {
			foreach ( $rates as $rate_id => $rate ) {
				if ( 'kunaki_shipping' !== $rate->method_id ) {
					unset( $rates[ $rate_id ] );
				}
			}
		}

		// Pure non-Kunaki package — remove Kunaki rates.
		if ( $has_non_kunaki && ! $has_kunaki ) {
			foreach ( $rates as $rate_id => $rate ) {
				if ( 'kunaki_shipping' === $rate->method_id ) {
					unset( $rates[ $rate_id ] );
				}
			}
		}

		return $rates;
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
