<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Order_Fulfillment {

	const META_KUNAKI_ORDER_ID      = '_kunaki_order_id';
	const META_KUNAKI_SUBMITTED     = '_kunaki_submitted';
	const META_FULFILLMENT_ERROR     = '_kunaki_fulfillment_error';
	const META_FULFILLMENT_FAILED    = '_kunaki_fulfillment_failed_at';
	const META_FULFILLMENT_ATTEMPTS  = '_kunaki_fulfillment_attempts';
	const META_FULFILLMENT_EXHAUSTED = '_kunaki_fulfillment_exhausted';

	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_processing' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_kunaki_order_info' ) );
		add_action( 'kunaki_wc_retry_failed_orders', array( $this, 'retry_failed_orders' ) );
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
			Kunaki_Logger::info( 'Already submitted to Kunaki, skipping.', array( 'order_id' => $order_id ) );
			return;
		}

		// Fallback check: durable marker written when save_meta_data() also failed.
		global $wpdb;
		$fallback_option = '_kunaki_submitted_' . $order_id;
		$fallback_value  = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$fallback_option
		) );
		if ( $fallback_value ) {
			Kunaki_Logger::info( 'Found fallback submitted marker, restoring meta.', array( 'order_id' => $order_id, 'kunaki_order_id' => $fallback_value ) );
			$order->update_meta_data( self::META_KUNAKI_ORDER_ID, $fallback_value );
			$order->update_meta_data( self::META_KUNAKI_SUBMITTED, 'yes' );
			$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
			$order->delete_meta_data( self::META_FULFILLMENT_FAILED );
			$order->delete_meta_data( self::META_FULFILLMENT_ATTEMPTS );
			$order->delete_meta_data( self::META_FULFILLMENT_EXHAUSTED );
			try {
				$order->save();
			} catch ( \Throwable $_ ) {
				// Still can't save — leave the marker for the next attempt.
				return;
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $fallback_option ) );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Kunaki order ID */
					__( 'Order submitted to Kunaki successfully. Kunaki Order ID: %s (recovered from fallback marker)', 'kunaki-woocommerce' ),
					$fallback_value
				),
				false
			);
			return;
		}

		// Acquire an atomic lock to prevent concurrent submissions.
		// Uses a single row in wp_options with the expiry timestamp as the value.
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
			Kunaki_Logger::debug( 'Could not acquire lock, another process is submitting.', array( 'order_id' => $order_id ) );
			return;
		}

		$retain_lock = false;

		try {

		// Re-check after acquiring lock in case another process submitted before we locked.
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return;
		}

		// Collect Kunaki products from the order.
		$kunaki_products = array();
		$has_non_kunaki  = false;

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$kunaki_id  = Kunaki_Product_Meta::get_kunaki_product_id( $product_id );

			if ( empty( $kunaki_id ) ) {
				$has_non_kunaki = true;
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
			Kunaki_Logger::debug( 'No Kunaki products found, skipping.', array( 'order_id' => $order_id ) );
			return;
		}

		// Get the shipping description selected at checkout.
		$shipping_description = $this->get_shipping_description( $order );

		if ( empty( $shipping_description ) ) {
			Kunaki_Logger::error( 'No Kunaki shipping description found.', array( 'order_id' => $order_id ) );
			$this->record_failure(
				$order,
				__( 'No matching Kunaki shipping method found on this order.', 'kunaki-woocommerce' ),
				'',
				true
			);
			return;
		}

		// Build shipping address.
		$countries    = WC()->countries->get_countries();
		$country_code = $order->get_shipping_country();
		$country_name = isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;
		// Strip parenthetical codes like "(US)" that some WooCommerce installs append.
		$country_name = preg_replace( '/\s*\([A-Z]{2}\)$/', '', $country_name );

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

		// Bail if credentials are known-bad (e.g. after salt rotation).
		if ( 'yes' === get_option( 'kunaki_wc_decrypt_failed' ) ) {
			Kunaki_Logger::error( 'Credentials invalid — skipping submission.', array( 'order_id' => $order_id ) );
			$this->record_failure(
				$order,
				__( 'Kunaki password could not be decrypted. Please re-enter your password in Kunaki settings.', 'kunaki-woocommerce' ),
				'kunaki_credentials_invalid'
			);
			return;
		}

		// Submit to Kunaki.
		$api    = new Kunaki_API_Client();
		$result = $api->submit_order( $address, $shipping_description, array_values( $kunaki_products ) );

		if ( is_wp_error( $result ) ) {
			Kunaki_Logger::error( 'Kunaki submission failed.', array( 'order_id' => $order_id, 'error' => $result->get_error_message() ) );
			$this->record_failure( $order, $result->get_error_message(), $result->get_error_code() );
			return;
		}

		if ( '' === $result ) {
			Kunaki_Logger::error( 'Kunaki returned success but no Order ID.', array( 'order_id' => $order_id ) );
			$this->record_failure(
				$order,
				__( 'API returned success but no Order ID.', 'kunaki-woocommerce' )
			);
			return;
		}

		// Store Kunaki order ID and mark as submitted.
		$order->update_meta_data( self::META_KUNAKI_ORDER_ID, $result );
		$order->update_meta_data( self::META_KUNAKI_SUBMITTED, 'yes' );
		$order->update_meta_data( '_kunaki_all_items', $has_non_kunaki ? 'no' : 'yes' );

		// Clear any failure meta from previous attempts.
		$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
		$order->delete_meta_data( self::META_FULFILLMENT_FAILED );
		$order->delete_meta_data( self::META_FULFILLMENT_ATTEMPTS );
		$order->delete_meta_data( self::META_FULFILLMENT_EXHAUSTED );

		try {
			$order->save();
		} catch ( \Throwable $e ) {
			// Order exists at Kunaki but meta failed to persist — mark submitted
			// directly so the hook never resubmits a duplicate.
			$order->update_meta_data( self::META_KUNAKI_ORDER_ID, $result );
			$order->update_meta_data( self::META_KUNAKI_SUBMITTED, 'yes' );
			try {
				$order->save_meta_data();
			} catch ( \Throwable $_ ) {
				// Last resort: write a durable marker directly to wp_options so
				// the order is never resubmitted, even after the lock expires.
				$marker_written = $wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					'_kunaki_submitted_' . $order_id,
					$result
				) );
				if ( ! $marker_written ) {
					// Nothing persisted — keep the lock so the order can't be
					// resubmitted until it expires and someone intervenes.
					Kunaki_Logger::error( 'CATASTROPHIC — Could not write durable marker. Lock retained.', array( 'order_id' => $order_id ) );
					$retain_lock = true;
					return;
				}
			}
			Kunaki_Logger::error( 'CRITICAL — Kunaki order created but save failed. Manual reconciliation required.', array(
				'order_id'        => $order_id,
				'kunaki_order_id' => $result,
				'error'           => $e->getMessage(),
			) );
			return;
		}

		// Clean up any fallback markers from previous failed attempts.
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_kunaki_submitted_' . $order_id ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_kunaki_failed_' . $order_id ) );

		try {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Kunaki order ID */
					__( 'Order submitted to Kunaki successfully. Kunaki Order ID: %s', 'kunaki-woocommerce' ),
					$result
				),
				false
			);
		} catch ( \Throwable $_ ) {
			// Order is already submitted and meta persisted — note is best effort.
		}

		Kunaki_Logger::info( 'Submitted to Kunaki.', array( 'order_id' => $order_id, 'kunaki_order_id' => $result ) );

		} finally {
			if ( ! $retain_lock ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
			}
		}
	}

	/**
	 * Record a structured failure on the order.
	 *
	 * @param WC_Order $order         WooCommerce order.
	 * @param string   $error_message Human-readable error description.
	 * @param string   $error_code    WP_Error code (used to skip counting circuit-open rejections).
	 * @param bool     $permanent     Whether the error is permanent (immediately mark exhausted, skip retry).
	 */
	private function record_failure( $order, $error_message, $error_code = '', $permanent = false ) {
		$skip_increment = $permanent || in_array( $error_code, array( 'kunaki_circuit_open', 'kunaki_credentials_invalid' ), true );

		$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
		$order->update_meta_data( self::META_FULFILLMENT_ERROR, $error_message );
		$order->update_meta_data( self::META_FULFILLMENT_FAILED, gmdate( 'c' ) );
		if ( ! $skip_increment ) {
			$order->update_meta_data( self::META_FULFILLMENT_ATTEMPTS, $attempts + 1 );
		}
		if ( $permanent ) {
			$order->update_meta_data( self::META_FULFILLMENT_EXHAUSTED, 'yes' );
		}
		try {
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			// Durable marker so retry_failed_orders() can still find this order.
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				'_kunaki_failed_' . $order->get_id(),
				$error_message
			) );
			Kunaki_Logger::error( 'Failed to persist failure meta. Wrote durable failure marker.', array(
				'order_id' => $order->get_id(),
				'error'    => $e->getMessage(),
			) );
		}

		try {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Kunaki fulfillment failed: %s', 'kunaki-woocommerce' ),
					$error_message
				),
				false
			);
		} catch ( \Throwable $_ ) {
			// Note didn't persist — not worth leaking the caller's lock.
		}
	}

	/**
	 * Retry order submission to Kunaki.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function prepare_for_retry( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'invalid_order', __( 'Order not found.', 'kunaki-woocommerce' ) );
		}

		if ( 'processing' !== $order->get_status() ) {
			return new \WP_Error( 'invalid_status', __( 'Order must be in processing status to retry.', 'kunaki-woocommerce' ) );
		}

		if ( 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return new \WP_Error( 'already_submitted', __( 'Order already submitted to Kunaki.', 'kunaki-woocommerce' ) );
		}

		// Reset retry budget so the order gets a fresh start and doesn't
		// immediately get re-exhausted by the cron if this attempt fails.
		$order->delete_meta_data( self::META_FULFILLMENT_EXHAUSTED );
		$order->delete_meta_data( self::META_FULFILLMENT_ATTEMPTS );
		$order->save();

		$this->handle_order_processing( $order_id, $order );

		// Re-fetch to check result.
		$order = wc_get_order( $order_id );
		if ( $order && 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return true;
		}

		return new \WP_Error(
			'retry_failed',
			$order ? $order->get_meta( self::META_FULFILLMENT_ERROR ) : __( 'Retry failed.', 'kunaki-woocommerce' )
		);
	}

	/**
	 * Restore failure meta for orders whose save_meta_data() failed in record_failure().
	 *
	 * These orders have a durable `_kunaki_failed_{order_id}` marker in wp_options
	 * but no META_FULFILLMENT_ERROR on the order itself.
	 */
	private function recover_orphaned_failures() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_kunaki_failed_' ) . '%'
		) );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$order_id = (int) str_replace( '_kunaki_failed_', '', $row->option_name );
			$order    = wc_get_order( $order_id );
			if ( ! $order ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $row->option_name ) );
				continue;
			}

			$order->update_meta_data( self::META_FULFILLMENT_ERROR, $row->option_value );
			$order->update_meta_data( self::META_FULFILLMENT_FAILED, gmdate( 'c' ) );
			$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
			$order->update_meta_data( self::META_FULFILLMENT_ATTEMPTS, max( $attempts, 1 ) );
			try {
				$order->save_meta_data();
			} catch ( \Throwable $_ ) {
				// Still can't save — leave the marker for the next run.
				continue;
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $row->option_name ) );
			Kunaki_Logger::info( 'Recovered orphaned failure marker.', array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * Cron handler: retry orders that failed submission and haven't exceeded max retries.
	 */
	public function retry_failed_orders() {
		$max_retries = absint( get_option( 'kunaki_wc_max_retries', 3 ) );
		if ( $max_retries < 1 ) {
			$max_retries = 3;
		}

		Kunaki_Logger::info( 'Starting failed order retry.', array( 'max_retries' => $max_retries ) );

		// Recover any orders orphaned by a save_meta_data() failure in record_failure().
		$this->recover_orphaned_failures();

		// Bail if credentials are known-bad — retrying would just burn time.
		if ( 'yes' === get_option( 'kunaki_wc_decrypt_failed' ) ) {
			Kunaki_Logger::info( 'Credentials invalid — skipping retry batch.' );
			return;
		}

		$orders = wc_get_orders( array(
			'status'     => array( 'processing' ),
			'meta_query' => array(
				array(
					'key'     => self::META_FULFILLMENT_ERROR,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::META_KUNAKI_SUBMITTED,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_FULFILLMENT_EXHAUSTED,
					'compare' => 'NOT EXISTS',
				),
			),
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'ASC',
		) );

		if ( empty( $orders ) ) {
			Kunaki_Logger::debug( 'No failed orders to retry.' );
			return;
		}

		$retried = 0;
		$cb      = new Kunaki_Circuit_Breaker();

		foreach ( $orders as $order ) {
			$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
			if ( $attempts >= $max_retries ) {
				$order->update_meta_data( self::META_FULFILLMENT_EXHAUSTED, 'yes' );
				try {
					$order->save();
				} catch ( \Throwable $_ ) {
					// Will be caught again next cron run.
				}
				try {
					$order->add_order_note(
						sprintf(
							/* translators: %d: max retry count */
							__( 'Kunaki fulfillment: all %d automatic retries exhausted. Manual intervention required.', 'kunaki-woocommerce' ),
							$max_retries
						),
						false
					);
				} catch ( \Throwable $_ ) {
					// Best effort — log entry below is the fallback.
				}
				Kunaki_Logger::info( 'Max retries exhausted. Requires manual intervention.', array( 'order_id' => $order->get_id(), 'max_retries' => $max_retries ) );
				continue;
			}

			if ( 'open' === $cb->get_state()['state'] ) {
				Kunaki_Logger::info( 'Circuit breaker is open — stopping retry batch.' );
				break;
			}

			// Linear backoff: wait $attempts * 15 minutes since the last failure.
			$failed_at = $order->get_meta( self::META_FULFILLMENT_FAILED );
			if ( $failed_at ) {
				$elapsed = time() - strtotime( $failed_at );
				$backoff = $attempts * 15 * MINUTE_IN_SECONDS;
				if ( $elapsed < $backoff ) {
					continue;
				}
			}

			$order_id = $order->get_id();
			Kunaki_Logger::info( 'Retrying submission.', array( 'order_id' => $order_id, 'attempt' => $attempts, 'max_retries' => $max_retries ) );

			try {
				$this->handle_order_processing( $order_id, $order );
			} catch ( \Throwable $e ) {
				Kunaki_Logger::error( 'Retry failed.', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
			}

			$retried++;
		}

		Kunaki_Logger::info( 'Retry complete.', array( 'orders_retried' => $retried ) );
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
		$error           = $order->get_meta( self::META_FULFILLMENT_ERROR );

		if ( empty( $kunaki_order_id ) && empty( $error ) ) {
			return;
		}

		echo '<div class="kunaki-order-info">';
		echo '<h3>' . esc_html__( 'Kunaki Fulfillment', 'kunaki-woocommerce' ) . '</h3>';

		if ( ! empty( $kunaki_order_id ) ) {
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
		} else {
			$attempts  = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
			$failed_at = $order->get_meta( self::META_FULFILLMENT_FAILED );

			echo '<p class="kunaki-error"><strong>' . esc_html__( 'Status:', 'kunaki-woocommerce' ) . '</strong> '
				. esc_html__( 'Failed', 'kunaki-woocommerce' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Error:', 'kunaki-woocommerce' ) . '</strong> '
				. esc_html( $error ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Attempts:', 'kunaki-woocommerce' ) . '</strong> '
				. esc_html( (string) $attempts ) . '</p>';
			if ( $failed_at ) {
				echo '<p><strong>' . esc_html__( 'Last attempt:', 'kunaki-woocommerce' ) . '</strong> '
					. esc_html( $failed_at ) . '</p>';
			}
			if ( 'processing' === $order->get_status() ) {
				echo '<button type="button" class="button kunaki-retry-button" data-order-id="'
					. esc_attr( (string) $order->get_id() ) . '">'
					. esc_html__( 'Retry Submission', 'kunaki-woocommerce' ) . '</button>';
				echo ' <span class="kunaki-retry-spinner spinner"></span>';
				echo '<span class="kunaki-retry-result"></span>';
			}
		}

		echo '</div>';
	}
}
