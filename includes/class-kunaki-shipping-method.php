<?php

defined( 'ABSPATH' ) || exit;

class WC_Shipping_Kunaki extends WC_Shipping_Method {

	/** Transient set while Kunaki rate lookups are failing for infrastructure reasons. */
	const UNAVAILABLE_TRANSIENT = 'kunaki_wc_rates_unavailable';

	/** How long a failed lookup is cached before trying the API again. */
	const ERROR_TTL = 2 * MINUTE_IN_SECONDS;

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
			$kunaki_id = Kunaki_Product_Meta::get_kunaki_id_for_cart_item( $item );

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
		$country      = Kunaki_API_Client::get_country_name( $country_code );
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
			// A recent failure for this exact lookup — don't make the customer
			// wait on another API timeout on every cart refresh.
			$error_key = 'kunaki_ship_err_' . md5( $country . $state . $postal_code . wp_json_encode( $kunaki_products ) );
			if ( false !== get_transient( $error_key ) ) {
				return;
			}

			$api     = new Kunaki_API_Client();
			$options = $api->get_shipping_options( $country, $state, $postal_code, array_values( $kunaki_products ) );

			if ( is_wp_error( $options ) ) {
				Kunaki_Logger::error( 'Shipping calculation failed.', array( 'error' => $options->get_error_message() ) );
				set_transient( $error_key, 1, self::ERROR_TTL );

				// Outage (not a bad address): tell customers rates are temporarily
				// unavailable, and make WooCommerce recalculate once it clears.
				$outage_codes = array_merge( Kunaki_Circuit_Breaker::TRIPPABLE_ERROR_CODES, array( 'kunaki_circuit_open' ) );
				if ( in_array( $options->get_error_code(), $outage_codes, true ) ) {
					set_transient( self::UNAVAILABLE_TRANSIENT, time(), self::ERROR_TTL );
				}
				return;
			}

			set_transient( $cache_key, $options, 30 * MINUTE_IN_SECONDS );
		}

		$estimator = new Kunaki_Delivery_Estimator();

		foreach ( $options as $option ) {
			$rate_id = $this->get_rate_id() . ':' . sanitize_title( $option['description'] );
			$label   = $option['description'] . ' (' . $option['delivery_time'] . ')';

			$estimate = $estimator->estimate_delivery( $option['delivery_time'] );
			if ( null !== $estimate && '' !== $estimate['label'] ) {
				/* translators: %s: estimated delivery date range, e.g. "Mar 25–Mar 28" */
				$label .= ' · ' . sprintf( __( 'Est. delivery %s', 'kunaki-woocommerce' ), $estimate['label'] );
			}

			$this->add_rate( array(
				'id'        => $rate_id,
				'label'     => $label,
				'cost'      => $option['price'],
				'meta_data' => array(
					'kunaki_shipping_description' => $option['description'],
					'kunaki_delivery_time'        => $option['delivery_time'],
				),
			) );
		}
	}
}
