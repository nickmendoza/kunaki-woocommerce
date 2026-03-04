<?php

defined( 'ABSPATH' ) || exit;

class WC_Shipping_Kunaki extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'kunaki_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Kunaki Shipping', 'kunaki-woocommerce' );
		$this->method_description = __( 'Live shipping rates from Kunaki for CD/DVD products.', 'kunaki-woocommerce' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);
		$this->enabled = 'yes';

		$this->init();
	}

	private function init() {
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', $this->method_title );

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'   => __( 'Method Title', 'kunaki-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Kunaki Shipping', 'kunaki-woocommerce' ),
			),
		);
	}

	/**
	 * Calculate shipping rates by calling Kunaki ShippingOptions API.
	 *
	 * @param array $package WooCommerce shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		// Collect Kunaki products from the package.
		$kunaki_products = array();

		foreach ( $package['contents'] as $item ) {
			$product_id = $item['product_id'];
			$kunaki_id  = Kunaki_Product_Meta::get_kunaki_product_id( $product_id );

			if ( empty( $kunaki_id ) ) {
				continue;
			}

			if ( isset( $kunaki_products[ $kunaki_id ] ) ) {
				$kunaki_products[ $kunaki_id ]['quantity'] += $item['quantity'];
			} else {
				$kunaki_products[ $kunaki_id ] = array(
					'product_id' => $kunaki_id,
					'quantity'   => $item['quantity'],
				);
			}
		}

		if ( empty( $kunaki_products ) ) {
			return;
		}

		$country_code = $package['destination']['country'];
		$country      = $this->get_kunaki_country_name( $country_code );
		// Kunaki only accepts state/province for US and CA.
		$state        = in_array( $country_code, array( 'US', 'CA' ), true )
			? $package['destination']['state']
			: '';
		$postal_code  = $package['destination']['postcode'];

		if ( empty( $country ) ) {
			return;
		}

		// Check transient cache.
		$cache_key = 'kunaki_ship_' . md5( $country . $state . $postal_code . wp_json_encode( $kunaki_products ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			$options = $cached;
		} else {
			$api     = new Kunaki_API_Client();
			$options = $api->get_shipping_options( $country, $state, $postal_code, array_values( $kunaki_products ) );

			if ( is_wp_error( $options ) ) {
				Kunaki_Logger::error( 'Shipping calculation failed.', array( 'error' => $options->get_error_message() ) );
				return;
			}

			set_transient( $cache_key, $options, 30 * MINUTE_IN_SECONDS );
		}

		foreach ( $options as $option ) {
			$rate_id = $this->get_rate_id() . ':' . sanitize_title( $option['description'] );

			$this->add_rate( array(
				'id'        => $rate_id,
				'label'     => $option['description'] . ' (' . $option['delivery_time'] . ')',
				'cost'      => $option['price'],
				'meta_data' => array(
					'kunaki_shipping_description' => $option['description'],
					'kunaki_delivery_time'        => $option['delivery_time'],
				),
			) );
		}
	}

	/**
	 * Convert WooCommerce ISO country code to Kunaki full country name.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 code.
	 * @return string Full country name.
	 */
	private function get_kunaki_country_name( $country_code ) {
		$countries = WC()->countries->get_countries();
		$name      = isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : '';
		// Strip parenthetical codes like "(US)" that some WooCommerce installs append.
		return preg_replace( '/\s*\([A-Z]{2}\)$/', '', $name );
	}
}
