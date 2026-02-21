<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Order_Fulfillment {

	const META_KUNAKI_ORDER_ID      = '_kunaki_order_id';
	const META_KUNAKI_SUBMITTED     = '_kunaki_submitted';
	const META_KUNAKI_SHIPPING_DESC = '_kunaki_shipping_description';

	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_processing' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_kunaki_order_info' ) );
	}

	/**
	 * Handle order transition to "processing" status.
	 *
	 * @param int           $order_id WooCommerce order ID.
	 * @param WC_Order|null $order    WooCommerce order object.
	 */
	public function handle_order_processing( $order_id, $order = null ) {
		if ( is_null( $order ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		// Prevent double-submission.
		if ( 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			Kunaki_Logger::info( "Order #{$order_id}: Already submitted to Kunaki, skipping." );
			return;
		}

		// Acquire an atomic lock to prevent concurrent submissions.
		// Uses a single row in wp_options with the expiry timestamp as the value.
		global $wpdb;
		$lock_option = '_kunaki_lock_' . $order_id;
		$lock_expiry = time() + 5 * MINUTE_IN_SECONDS;

		// Atomically delete any expired lock (no SELECT-then-DELETE race).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s",
			$lock_option,
			(string) time()
		) );

		// Atomic acquire — INSERT IGNORE on a single row, only one process wins.
		$acquired = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$lock_option,
			(string) $lock_expiry
		) );

		if ( ! $acquired ) {
			Kunaki_Logger::debug( "Order #{$order_id}: Could not acquire lock, another process is submitting." );
			return;
		}

		// Re-check after acquiring lock in case another process submitted before we locked.
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
			return;
		}

		// Collect Kunaki products from the order.
		$kunaki_products = array();

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$kunaki_id  = Kunaki_Product_Meta::get_kunaki_product_id( $product_id );

			if ( empty( $kunaki_id ) ) {
				continue;
			}

			if ( isset( $kunaki_products[ $kunaki_id ] ) ) {
				$kunaki_products[ $kunaki_id ]['quantity'] += $item->get_quantity();
			} else {
				$kunaki_products[ $kunaki_id ] = array(
					'product_id' => $kunaki_id,
					'quantity'   => $item->get_quantity(),
				);
			}
		}

		if ( empty( $kunaki_products ) ) {
			Kunaki_Logger::debug( "Order #{$order_id}: No Kunaki products found, skipping." );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
			return;
		}

		// Get the shipping description selected at checkout.
		$shipping_description = $this->get_shipping_description( $order );

		if ( empty( $shipping_description ) ) {
			Kunaki_Logger::error( "Order #{$order_id}: No Kunaki shipping description found." );
			$order->add_order_note(
				__( 'Kunaki fulfillment failed: No matching Kunaki shipping method found on this order.', 'kunaki-woocommerce' ),
				false
			);
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
			return;
		}

		// Build shipping address.
		$countries    = WC()->countries->get_countries();
		$country_code = $order->get_shipping_country();
		$country_name = isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;

		// Kunaki only accepts state/province for US and CA.
		$state = in_array( $country_code, array( 'US', 'CA' ), true )
			? $order->get_shipping_state()
			: '';

		$address = array(
			'name'      => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company'   => $order->get_shipping_company(),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'city'      => $order->get_shipping_city(),
			'state'     => $state,
			'postcode'  => $order->get_shipping_postcode(),
			'country'   => $country_name,
		);

		// Submit to Kunaki.
		$api    = new Kunaki_API_Client();
		$result = $api->submit_order( $address, $shipping_description, array_values( $kunaki_products ) );

		if ( is_wp_error( $result ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
			Kunaki_Logger::error( "Order #{$order_id}: Kunaki submission failed: " . $result->get_error_message() );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Kunaki fulfillment failed: %s', 'kunaki-woocommerce' ),
					$result->get_error_message()
				),
				false
			);
			return;
		}

		// Store Kunaki order ID and mark as submitted.
		$order->update_meta_data( self::META_KUNAKI_ORDER_ID, $result );
		$order->update_meta_data( self::META_KUNAKI_SUBMITTED, 'yes' );
		$order->update_meta_data( self::META_KUNAKI_SHIPPING_DESC, $shipping_description );
		$order->save();

		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );

		$order->add_order_note(
			sprintf(
				/* translators: %s: Kunaki order ID */
				__( 'Order submitted to Kunaki successfully. Kunaki Order ID: %s', 'kunaki-woocommerce' ),
				$result
			),
			false
		);

		Kunaki_Logger::info( "Order #{$order_id}: Submitted to Kunaki, Kunaki Order ID: {$result}" );
	}

	/**
	 * Extract the Kunaki shipping description from the WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Shipping description or empty string.
	 */
	private function get_shipping_description( $order ) {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( 'kunaki_shipping' === $shipping_item->get_method_id() ) {
				$description = $shipping_item->get_meta( 'kunaki_shipping_description' );
				if ( ! empty( $description ) ) {
					return $description;
				}
			}
		}

		return '';
	}

	/**
	 * Display Kunaki order info on the admin order edit page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function display_kunaki_order_info( $order ) {
		$kunaki_order_id = $order->get_meta( self::META_KUNAKI_ORDER_ID );
		if ( empty( $kunaki_order_id ) ) {
			return;
		}

		echo '<div class="kunaki-order-info">';
		echo '<h3>' . esc_html__( 'Kunaki Fulfillment', 'kunaki-woocommerce' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Kunaki Order ID:', 'kunaki-woocommerce' ) . '</strong> ' . esc_html( $kunaki_order_id ) . '</p>';

		$status = $order->get_meta( '_kunaki_order_status' );
		if ( ! empty( $status ) ) {
			echo '<p><strong>' . esc_html__( 'Kunaki Status:', 'kunaki-woocommerce' ) . '</strong> ' . esc_html( $status ) . '</p>';
		}

		$tracking_id   = $order->get_meta( '_kunaki_tracking_id' );
		$tracking_type = $order->get_meta( '_kunaki_tracking_type' );
		if ( ! empty( $tracking_id ) && 'NA' !== $tracking_id ) {
			echo '<p><strong>' . esc_html__( 'Tracking:', 'kunaki-woocommerce' ) . '</strong> ';
			echo esc_html( $tracking_type ) . ' &ndash; ' . esc_html( $tracking_id );
			echo '</p>';
		}

		echo '</div>';
	}
}
