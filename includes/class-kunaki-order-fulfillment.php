<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Order_Fulfillment {

	const META_KUNAKI_ORDER_ID      = '_kunaki_order_id';
	const META_KUNAKI_SUBMITTED     = '_kunaki_submitted';
	const META_FULFILLMENT_ERROR     = '_kunaki_fulfillment_error';
	const META_FULFILLMENT_FAILED    = '_kunaki_fulfillment_failed_at';
	const META_FULFILLMENT_ATTEMPTS  = '_kunaki_fulfillment_attempts';
	const META_FULFILLMENT_EXHAUSTED = '_kunaki_fulfillment_exhausted';
	const META_FULFILLMENT_LAST_ERROR = '_kunaki_fulfillment_last_error';
	const META_KUNAKI_STATUS         = '_kunaki_order_status';

	const META_ADDRESS_HASH          = '_kunaki_address_hash';

	/** The Kunaki shipping option chosen at Retry for an order that has none from checkout. */
	const META_CHOSEN_SHIPPING       = '_kunaki_chosen_shipping';

	/** 'yes' once the order's stored message says Retry will use the cheapest option. Never cleared. */
	const META_NEEDS_SHIPPING        = '_kunaki_needs_shipping';

	/** Starts the value of a durable failure marker when the failure was permanent. */
	const PERMANENT_MARKER_PREFIX    = '[kunaki-permanent] ';

	/** The same, for an order that was also flagged META_NEEDS_SHIPPING. */
	const PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX = '[kunaki-permanent-needs-shipping] ';

	const SUBMIT_ACTION = 'kunaki_wc_submit_order';
	const ACTION_GROUP  = 'kunaki-woocommerce';

	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_order_submission' ), 10, 1 );
		add_action( self::SUBMIT_ACTION, array( $this, 'run_queued_submission' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_kunaki_order_info' ) );
		add_action( 'kunaki_wc_retry_failed_orders', array( $this, 'retry_failed_orders' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'note_cancelled_after_submission' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'note_cancelled_after_submission' ), 10, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'note_partially_refunded_after_submission' ), 10, 1 );
	}

	/**
	 * Queue a background Kunaki submission when an order moves to "processing".
	 *
	 * This hook fires inside checkout and payment-gateway webhooks. Calling the
	 * Kunaki API here (30s timeout) would stall checkout and can make gateways
	 * time out and resend the webhook, so the submission runs via Action
	 * Scheduler instead. Falls back to submitting inline if Action Scheduler
	 * isn't available.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function queue_order_submission( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->handle_order_processing( $order_id );
			return;
		}

		$args = array( 'order_id' => $order_id );

		// One pending submission per order is enough — handle_order_processing()
		// is idempotent, but there's no point queuing duplicates on repeated
		// status transitions.
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::SUBMIT_ACTION, $args, self::ACTION_GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::SUBMIT_ACTION, $args, self::ACTION_GROUP );
		Kunaki_Logger::debug( 'Queued Kunaki submission.', array( 'order_id' => $order_id ) );
	}

	/**
	 * Hand queued submissions over to the retry queue before deactivation.
	 *
	 * With the plugin inactive, Action Scheduler would run these jobs with no
	 * callback and discard them, leaving the orders unsent with nothing to show
	 * for it. Recording a failure instead lists them on the Failures page and
	 * lets the retry cron send them after reactivation.
	 */
	public function park_queued_submissions() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$actions = as_get_scheduled_actions( array(
			'hook'     => self::SUBMIT_ACTION,
			'group'    => self::ACTION_GROUP,
			'status'   => 'pending',
			'per_page' => -1,
		) );

		foreach ( $actions as $action ) {
			try {
				$args  = $action->get_args();
				$order = wc_get_order( absint( $args['order_id'] ?? 0 ) );
				if ( $order && 'processing' === $order->get_status() && 'yes' !== $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
					$this->record_failure(
						$order,
						__( 'The Kunaki plugin was deactivated before this order was sent. It will be retried when the plugin is active again.', 'kunaki-woocommerce' ),
						'kunaki_plugin_deactivated'
					);
				}
			} catch ( \Throwable $e ) {
				// Never block deactivation over a single order.
				Kunaki_Logger::error( 'Could not hand over queued submission.', array( 'error' => $e->getMessage() ) );
			}
		}

		// Hook only: passed a group as well, this would only cancel jobs with no args.
		as_unschedule_all_actions( self::SUBMIT_ACTION );
	}

	/**
	 * Record an order that was paid while the plugin was inactive.
	 *
	 * Nothing is sent from here. A recent order that has a Kunaki shipping
	 * choice becomes a retryable failure, which the retry cron sends. Anything
	 * else is held as a permanent failure until someone clicks Retry: an order
	 * that waited too long may have been fulfilled by hand, and an order with
	 * no Kunaki shipping choice needs someone to accept the cheapest option.
	 *
	 * @param WC_Order $order           WooCommerce order with no Kunaki record.
	 * @param bool     $waited_too_long Whether it was paid too long ago to send without a person.
	 * @return string 'sent' if it was handed to the retry cron, 'held' if it waits for a click.
	 */
	public function record_missed_order( $order, $waited_too_long ) {
		if ( '' === $this->get_shipping_description( $order ) ) {
			// The message says what Retry will do; the flag records that it has.
			$order->update_meta_data( self::META_NEEDS_SHIPPING, 'yes' );
			$this->record_failure(
				$order,
				__( 'This order was paid while the Kunaki plugin was inactive and has no Kunaki shipping option, so it was not sent automatically. If it has not been fulfilled another way, retry it to send it with the cheapest Kunaki shipping option for its address.', 'kunaki-woocommerce' ),
				'',
				true
			);
			return 'held';
		}

		if ( $waited_too_long ) {
			$this->record_failure(
				$order,
				__( 'This order was paid while the Kunaki plugin was inactive and had waited more than a day, so it was not sent automatically. If it has not been fulfilled another way, retry it to send it to Kunaki.', 'kunaki-woocommerce' ),
				'',
				true
			);
			return 'held';
		}

		$this->record_failure(
			$order,
			__( 'This order was paid while the Kunaki plugin was inactive. It will be sent to Kunaki automatically.', 'kunaki-woocommerce' ),
			'kunaki_plugin_deactivated'
		);
		return 'sent';
	}

	/**
	 * Action Scheduler callback: submit a queued order.
	 *
	 * An uncaught exception would leave the order with no failure meta, so the
	 * retry cron would never find it. Record it as a failure instead so it is
	 * retried and visible on the Failures page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function run_queued_submission( $order_id ) {
		// The order may have been cancelled, refunded, or put on hold between
		// queuing and now — only submit orders that are still processing.
		$queued_order = wc_get_order( absint( $order_id ) );
		if ( ! $queued_order || 'processing' !== $queued_order->get_status() ) {
			Kunaki_Logger::info( 'Order no longer processing — skipping queued submission.', array( 'order_id' => $order_id ) );
			return;
		}

		try {
			$this->handle_order_processing( absint( $order_id ) );
		} catch ( \Throwable $e ) {
			Kunaki_Logger::error( 'Queued submission threw.', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
			$order = wc_get_order( absint( $order_id ) );
			if ( $order && 'yes' !== $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
				$this->record_failure( $order, $e->getMessage() );
			}
		}
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
			$order->delete_meta_data( self::META_FULFILLMENT_LAST_ERROR );
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
		list( $kunaki_products, $has_non_kunaki ) = $this->get_kunaki_products( $order );

		if ( empty( $kunaki_products ) ) {
			Kunaki_Logger::debug( 'No Kunaki products found, skipping.', array( 'order_id' => $order_id ) );
			return;
		}

		// The shipping option selected at checkout, or the one an admin accepted
		// by clicking Retry. Nothing here ever chooses one.
		$shipping_description = $this->get_shipping_description( $order );

		if ( empty( $shipping_description ) ) {
			Kunaki_Logger::error( 'No Kunaki shipping description found.', array( 'order_id' => $order_id ) );
			// The message says what Retry will do, and the flag records that it
			// has: prepare_for_retry() only chooses shipping for a flagged order.
			$order->update_meta_data( self::META_NEEDS_SHIPPING, 'yes' );
			$this->record_failure(
				$order,
				__( 'No matching Kunaki shipping method found on this order. Retry it to send it with the cheapest Kunaki shipping option for its address.', 'kunaki-woocommerce' ),
				'',
				true
			);
			return;
		}

		// Build shipping address.
		$country_code = $order->get_shipping_country();
		$country_name = Kunaki_API_Client::get_country_name( $country_code );
		if ( '' === $country_name ) {
			$country_name = $country_code;
		}

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
		$order->update_meta_data( Kunaki_Delivery_Estimator::META_SUBMITTED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::META_ADDRESS_HASH, self::get_address_hash( $order ) );

		// Clear any failure meta from previous attempts.
		$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
		$order->delete_meta_data( self::META_FULFILLMENT_FAILED );
		$order->delete_meta_data( self::META_FULFILLMENT_ATTEMPTS );
		$order->delete_meta_data( self::META_FULFILLMENT_EXHAUSTED );
		$order->delete_meta_data( self::META_FULFILLMENT_LAST_ERROR );

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
		$skip_increment = $permanent || in_array( $error_code, array( 'kunaki_circuit_open', 'kunaki_credentials_invalid', 'kunaki_plugin_deactivated' ), true );

		$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
		$order->update_meta_data( self::META_FULFILLMENT_FAILED, gmdate( 'c' ) );
		if ( ! $skip_increment ) {
			$order->update_meta_data( self::META_FULFILLMENT_ATTEMPTS, $attempts + 1 );
		}
		if ( $permanent ) {
			// Permanent failures go straight to "needs attention". The message is
			// kept under LAST_ERROR (not ERROR) so the order stays out of the
			// retry cron's ERROR EXISTS query and can't starve retryable orders.
			$order->update_meta_data( self::META_FULFILLMENT_EXHAUSTED, 'yes' );
			$order->update_meta_data( self::META_FULFILLMENT_LAST_ERROR, $error_message );
			$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
		} else {
			$order->update_meta_data( self::META_FULFILLMENT_ERROR, $error_message );
		}
		$saved = true;
		try {
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			$saved = false;
			// Durable marker so retry_failed_orders() can still find this order.
			// The prefix keeps a permanent failure permanent when it is restored;
			// without it the order would come back as retryable and be resent.
			// It also carries the "Retry will choose shipping" flag, which was
			// set on the order just before this and lost with the failed save.
			$marker_prefix = '';
			if ( $permanent ) {
				$marker_prefix = 'yes' === $order->get_meta( self::META_NEEDS_SHIPPING )
					? self::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX
					: self::PERMANENT_MARKER_PREFIX;
			}
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				'_kunaki_failed_' . $order->get_id(),
				$marker_prefix . $error_message
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

		// Only once the exhausted state is saved. If it wasn't, the durable
		// marker brings the order back, and its eventual exhaustion alerts then.
		if ( $permanent && $saved ) {
			self::notify_needs_attention( $order->get_id(), 'permanent_failure', $error_message );
		}
	}

	/**
	 * Tell listeners an order needs a person before it can be fulfilled.
	 *
	 * A listener that throws must not get out: it would stop the retry run or
	 * status sync that found the order, and the orders after it.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   'retries_exhausted', 'permanent_failure' or 'declined'.
	 * @param string $message  The error, or for 'declined' the Kunaki order ID.
	 */
	public static function notify_needs_attention( $order_id, $reason, $message ) {
		try {
			/**
			 * Fires when an order needs a person before it can be fulfilled.
			 *
			 * @param int    $order_id Order ID.
			 * @param string $reason   'retries_exhausted', 'permanent_failure' or 'declined'.
			 * @param string $message  The error, or for 'declined' the Kunaki order ID.
			 */
			do_action( 'kunaki_wc_order_needs_attention', $order_id, $reason, $message );
		} catch ( \Throwable $e ) {
			Kunaki_Logger::error( 'A listener on kunaki_wc_order_needs_attention threw.', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
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

		$declined = 'DECLINED' === $order->get_meta( self::META_KUNAKI_STATUS );
		if ( ! $declined && 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return new \WP_Error( 'already_submitted', __( 'Order already submitted to Kunaki.', 'kunaki-woocommerce' ) );
		}

		// An order with no Kunaki shipping choice, whose stored message has said
		// what Retry will do: this click accepts the cheapest option. It is looked
		// up before anything on the order changes, so a failed lookup leaves the
		// order and its failure record exactly as they were.
		$chosen = null;
		if ( 'yes' === $order->get_meta( self::META_NEEDS_SHIPPING ) && '' === $this->get_shipping_description( $order, false ) ) {
			list( $kunaki_products ) = $this->get_kunaki_products( $order );
			if ( ! empty( $kunaki_products ) ) {
				$chosen = $this->choose_cheapest_shipping( $order, $kunaki_products );
				if ( is_wp_error( $chosen ) ) {
					return $chosen;
				}
				$order->update_meta_data( self::META_CHOSEN_SHIPPING, $chosen['description'] );
			}
		}

		$declined_order_id = '';
		if ( $declined ) {
			// Kunaki declined the previous submission. The admin has moved the
			// order back to processing and explicitly asked to retry, so clear
			// the old Kunaki order and submit a new one.
			$declined_order_id = (string) $order->get_meta( self::META_KUNAKI_ORDER_ID );
			foreach ( array(
				self::META_KUNAKI_ORDER_ID,
				self::META_KUNAKI_SUBMITTED,
				self::META_KUNAKI_STATUS,
				'_kunaki_tracking_type',
				'_kunaki_tracking_id',
				'_kunaki_all_items',
				'_kunaki_sync_note_pending',
				Kunaki_Delivery_Estimator::META_SUBMITTED_AT,
				Kunaki_Delivery_Estimator::META_SHIPPED_AT,
			) as $key ) {
				$order->delete_meta_data( $key );
			}
		}

		// Reset retry budget so the order gets a fresh start and doesn't
		// immediately get re-exhausted by the cron if this attempt fails.
		$order->delete_meta_data( self::META_FULFILLMENT_EXHAUSTED );
		$order->delete_meta_data( self::META_FULFILLMENT_ATTEMPTS );
		$order->delete_meta_data( self::META_FULFILLMENT_LAST_ERROR );
		try {
			$order->save();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'save_failed', __( 'Failed to reset retry budget.', 'kunaki-woocommerce' ) );
		}

		if ( '' !== $declined_order_id ) {
			try {
				$order->add_order_note(
					sprintf(
						/* translators: %s: previous Kunaki order ID */
						__( 'Resubmitting to Kunaki. Previous Kunaki order %s was declined.', 'kunaki-woocommerce' ),
						$declined_order_id
					),
					false
				);
			} catch ( \Throwable $_ ) {
				// Best effort — the resubmission note below carries the new ID.
			}
		}

		if ( null !== $chosen ) {
			try {
				$order->add_order_note(
					sprintf(
						/* translators: 1: shipping option name, 2: delivery time, 3: price */
						__( 'No Kunaki shipping option was chosen for this order at checkout. Sending it with the cheapest Kunaki option for its address: %1$s (%2$s), Kunaki price %3$s.', 'kunaki-woocommerce' ),
						$chosen['description'],
						$chosen['delivery_time'],
						number_format( (float) $chosen['price'], 2, '.', '' )
					),
					false
				);
			} catch ( \Throwable $_ ) {
				// Best effort — the choice itself is saved on the order.
			}
		}

		$this->handle_order_processing( $order_id, $order );

		// Re-fetch to check result.
		$order = wc_get_order( $order_id );
		if ( $order && 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return true;
		}

		// A permanent failure keeps its message under LAST_ERROR, not ERROR.
		return new \WP_Error(
			'retry_failed',
			$order ? self::get_failure_message( $order ) : __( 'Retry failed.', 'kunaki-woocommerce' )
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

			$message        = (string) $row->option_value;
			$needs_shipping = 0 === strpos( $message, self::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX );
			$permanent      = $needs_shipping || 0 === strpos( $message, self::PERMANENT_MARKER_PREFIX );

			if ( $permanent ) {
				// Restore it as it was recorded: waiting for a person. Restored as
				// a retryable failure, the retry cron would send it.
				$message = substr( $message, strlen( $needs_shipping ? self::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX : self::PERMANENT_MARKER_PREFIX ) );
				$order->update_meta_data( self::META_FULFILLMENT_EXHAUSTED, 'yes' );
				$order->update_meta_data( self::META_FULFILLMENT_LAST_ERROR, $message );
				$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
				$order->update_meta_data( self::META_FULFILLMENT_FAILED, gmdate( 'c' ) );
				if ( $needs_shipping ) {
					// The message says Retry will choose shipping. Without the
					// flag, the first Retry would only repeat that promise.
					$order->update_meta_data( self::META_NEEDS_SHIPPING, 'yes' );
				}
			} else {
				$order->update_meta_data( self::META_FULFILLMENT_ERROR, $message );
				$order->update_meta_data( self::META_FULFILLMENT_FAILED, gmdate( 'c' ) );
				$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
				$order->update_meta_data( self::META_FULFILLMENT_ATTEMPTS, max( $attempts, 1 ) );
			}
			try {
				$order->save_meta_data();
			} catch ( \Throwable $_ ) {
				// Still can't save — leave the marker for the next run.
				continue;
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $row->option_name ) );
			Kunaki_Logger::info( 'Recovered orphaned failure marker.', array( 'order_id' => $order_id, 'permanent' => $permanent ) );

			// record_failure() held the alert back because the save had failed.
			if ( $permanent ) {
				self::notify_needs_attention( $order_id, 'permanent_failure', $message );
			}
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
			'status'       => array( 'processing' ),
			'meta_key'     => self::META_FULFILLMENT_ERROR,
			'meta_compare' => 'EXISTS',
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'ASC',
		) );

		// Filter in PHP — HPOS NOT EXISTS meta queries can return false positives.
		$orders = array_filter( $orders, function ( $order ) {
			return 'yes' !== $order->get_meta( self::META_KUNAKI_SUBMITTED )
				&& 'yes' !== $order->get_meta( self::META_FULFILLMENT_EXHAUSTED );
		} );

		if ( empty( $orders ) ) {
			Kunaki_Logger::debug( 'No failed orders to retry.' );
			return;
		}

		$retried = 0;
		$cb      = new Kunaki_Circuit_Breaker();

		foreach ( $orders as $order ) {
			$attempts = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
			if ( $attempts >= $max_retries ) {
				// Move the message to LAST_ERROR so the admin can still see it,
				// while the order drops out of the ERROR EXISTS retry query.
				$last_error = (string) $order->get_meta( self::META_FULFILLMENT_ERROR );
				$order->update_meta_data( self::META_FULFILLMENT_EXHAUSTED, 'yes' );
				$order->update_meta_data( self::META_FULFILLMENT_LAST_ERROR, $last_error );
				$order->delete_meta_data( self::META_FULFILLMENT_ERROR );
				$saved = true;
				try {
					$order->save();
				} catch ( \Throwable $_ ) {
					// Will be caught again next cron run, which alerts then.
					$saved = false;
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
				if ( $saved ) {
					self::notify_needs_attention( $order->get_id(), 'retries_exhausted', $last_error );
				}
				continue;
			}

			if ( ! $cb->allows_request() ) {
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
	 * The option the customer chose at checkout wins. An order without one
	 * (placed while the plugin was inactive, or created by hand) can carry a
	 * choice an admin accepted by clicking Retry.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param bool     $include_chosen Whether a choice stored at Retry counts.
	 * @return string Shipping description or empty string.
	 */
	private function get_shipping_description( $order, $include_chosen = true ) {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( 'kunaki_shipping' === $shipping_item->get_method_id() ) {
				$description = $shipping_item->get_meta( 'kunaki_shipping_description' );
				if ( ! empty( $description ) ) {
					return $description;
				}
			}
		}

		return $include_chosen ? (string) $order->get_meta( self::META_CHOSEN_SHIPPING ) : '';
	}

	/**
	 * Collect the Kunaki products on an order, with quantities summed per Kunaki ID.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array The products keyed by Kunaki ID, then whether the order has other items too.
	 */
	private function get_kunaki_products( $order ) {
		$kunaki_products = array();
		$has_non_kunaki  = false;

		foreach ( $order->get_items() as $item ) {
			$kunaki_id = Kunaki_Product_Meta::get_kunaki_id_for_item( $item->get_product_id(), $item->get_variation_id() );

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

		return array( $kunaki_products, $has_non_kunaki );
	}

	/**
	 * Ask Kunaki for the shipping options for an order and pick the cheapest.
	 *
	 * Only prepare_for_retry() calls this: choosing shipping needs a person's
	 * click, because the order is manufactured and billed with whatever is sent.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param array    $kunaki_products Products from get_kunaki_products().
	 * @return array|WP_Error The option (description, delivery_time, price), or why none could be chosen.
	 */
	private function choose_cheapest_shipping( $order, array $kunaki_products ) {
		$country_code = (string) $order->get_shipping_country();
		if ( ! Kunaki_API_Client::is_supported_country( $country_code ) ) {
			return new \WP_Error(
				'kunaki_no_shipping_options',
				__( "Kunaki does not ship to this order's country, so it can't be sent to Kunaki.", 'kunaki-woocommerce' )
			);
		}

		$country = Kunaki_API_Client::get_country_name( $country_code );
		if ( '' === $country ) {
			$country = $country_code;
		}

		// Kunaki only accepts state/province for US and CA.
		$state = in_array( $country_code, array( 'US', 'CA' ), true )
			? (string) $order->get_shipping_state()
			: '';

		$api     = new Kunaki_API_Client();
		$options = $api->get_shipping_options( $country, $state, (string) $order->get_shipping_postcode(), array_values( $kunaki_products ) );

		if ( is_wp_error( $options ) ) {
			return $options;
		}

		$cheapest = null;
		foreach ( $options as $option ) {
			if ( '' === (string) $option['description'] ) {
				continue;
			}
			// Strictly cheaper, so the first of two equal prices is kept.
			if ( null === $cheapest || $option['price'] < $cheapest['price'] ) {
				$cheapest = $option;
			}
		}

		if ( null === $cheapest ) {
			return new \WP_Error(
				'kunaki_no_shipping_options',
				__( "Kunaki returned no shipping options for this order's address. Check the address, then retry.", 'kunaki-woocommerce' )
			);
		}

		return $cheapest;
	}

	/**
	 * Fingerprint of the shipping address, stored at submission so later edits
	 * can be detected (Kunaki keeps the address it was sent).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_address_hash( $order ) {
		return md5( (string) wp_json_encode( array(
			$order->get_shipping_first_name(),
			$order->get_shipping_last_name(),
			$order->get_shipping_company(),
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_city(),
			$order->get_shipping_state(),
			$order->get_shipping_postcode(),
			$order->get_shipping_country(),
		) ) );
	}

	/**
	 * Whether Kunaki may still manufacture/ship this order, so admin changes
	 * in WooCommerce (cancel, refund, address edit) need to be made at Kunaki too.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_pending_at_kunaki( $order ) {
		return 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED )
			&& ! in_array( $order->get_meta( self::META_KUNAKI_STATUS ), array( 'SHIPPED', 'DECLINED' ), true );
	}

	/**
	 * Add an admin note when an order already sent to Kunaki is cancelled or refunded.
	 *
	 * Kunaki's API has no cancel call, so the admin must cancel it with Kunaki.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function note_cancelled_after_submission( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::is_pending_at_kunaki( $order ) ) {
			return;
		}
		$order->add_order_note( sprintf(
			/* translators: %s: Kunaki order ID */
			__( 'Warning: this order was already sent to Kunaki (Kunaki Order ID %s). Kunaki will still manufacture, ship, and bill it unless you cancel it with Kunaki directly.', 'kunaki-woocommerce' ),
			$order->get_meta( self::META_KUNAKI_ORDER_ID )
		), false );
	}

	/**
	 * Add an admin note when an order already sent to Kunaki is partially refunded.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function note_partially_refunded_after_submission( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::is_pending_at_kunaki( $order ) ) {
			return;
		}
		$order->add_order_note( sprintf(
			/* translators: %s: Kunaki order ID */
			__( 'Note: this order was already sent to Kunaki (Kunaki Order ID %s). The refund does not change what Kunaki ships — adjust the order with Kunaki directly if needed.', 'kunaki-woocommerce' ),
			$order->get_meta( self::META_KUNAKI_ORDER_ID )
		), false );
	}

	/**
	 * Classify an order's Kunaki failure state for admin display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null 'declined', 'exhausted', 'retrying', or null if not failed.
	 */
	public static function get_failure_state( $order ) {
		if ( 'DECLINED' === $order->get_meta( self::META_KUNAKI_STATUS ) ) {
			return 'declined';
		}
		if ( 'yes' === $order->get_meta( self::META_KUNAKI_SUBMITTED ) ) {
			return null;
		}
		if ( 'yes' === $order->get_meta( self::META_FULFILLMENT_EXHAUSTED ) ) {
			return 'exhausted';
		}
		if ( '' !== (string) $order->get_meta( self::META_FULFILLMENT_ERROR ) ) {
			return 'retrying';
		}
		return null;
	}

	/**
	 * Get the most relevant failure message for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_failure_message( $order ) {
		$error = (string) $order->get_meta( self::META_FULFILLMENT_ERROR );
		if ( '' === $error ) {
			$error = (string) $order->get_meta( self::META_FULFILLMENT_LAST_ERROR );
		}
		return $error;
	}

	/**
	 * Render the Retry button (or guidance when the order isn't retryable yet).
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param string   $label    Button label.
	 */
	public static function render_retry_control( $order, $label ) {
		if ( 'processing' !== $order->get_status() ) {
			if ( 'on-hold' === $order->get_status() && 'DECLINED' === $order->get_meta( self::META_KUNAKI_STATUS ) ) {
				echo '<p class="description">' . esc_html__( 'Change the order status to Processing to resubmit it to Kunaki.', 'kunaki-woocommerce' ) . '</p>';
			}
			return;
		}
		echo '<button type="button" class="button kunaki-retry-button" data-order-id="'
			. esc_attr( (string) $order->get_id() ) . '">'
			. esc_html( $label ) . '</button>';
		echo ' <span class="kunaki-retry-spinner spinner"></span>';
		echo '<span class="kunaki-retry-result"></span>';
	}

	/**
	 * Display Kunaki order info on the admin order edit page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function display_kunaki_order_info( $order ) {
		$kunaki_order_id = $order->get_meta( self::META_KUNAKI_ORDER_ID );
		$failure_state   = self::get_failure_state( $order );

		if ( empty( $kunaki_order_id ) && null === $failure_state ) {
			return;
		}

		echo '<div class="kunaki-order-info">';
		echo '<h3>' . esc_html__( 'Kunaki Fulfillment', 'kunaki-woocommerce' ) . '</h3>';

		if ( ! empty( $kunaki_order_id ) ) {
			echo '<p><strong>' . esc_html__( 'Kunaki Order ID:', 'kunaki-woocommerce' ) . '</strong> ' . esc_html( $kunaki_order_id ) . '</p>';

			$status = $order->get_meta( self::META_KUNAKI_STATUS );
			if ( ! empty( $status ) ) {
				echo '<p><strong>' . esc_html__( 'Kunaki Status:', 'kunaki-woocommerce' ) . '</strong> ' . esc_html( $status ) . '</p>';
				if ( 'PENDING' === $status ) {
					echo '<p class="description">' . wp_kses_post( sprintf(
						/* translators: %s: Kunaki billing URL */
						__( 'Order may be awaiting payment. <a href="%s" target="_blank">Check Kunaki billing</a>.', 'kunaki-woocommerce' ),
						'https://kunaki.com/accounting/XMLBilling.ASP'
					) ) . '</p>';
				}
			}

			$tracking_id   = $order->get_meta( '_kunaki_tracking_id' );
			$tracking_type = $order->get_meta( '_kunaki_tracking_type' );
			if ( ! empty( $tracking_id ) && 'NA' !== $tracking_id ) {
				echo '<p><strong>' . esc_html__( 'Tracking:', 'kunaki-woocommerce' ) . '</strong> ';
				echo esc_html( $tracking_type ) . ' &ndash; ' . esc_html( $tracking_id );
				echo '</p>';
			}

			if ( self::is_pending_at_kunaki( $order ) ) {
				// Status sync only polls processing orders, so this order's Kunaki
				// status is never refreshed after cancellation. Word the warning so
				// it stays true after the admin cancels it with Kunaki.
				if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
					echo '<p class="kunaki-error">' . esc_html__( 'This order was cancelled or refunded in WooCommerce after it was sent to Kunaki. Unless it was also cancelled with Kunaki directly, Kunaki will still manufacture and ship it.', 'kunaki-woocommerce' ) . '</p>';
				}
				$sent_hash = (string) $order->get_meta( self::META_ADDRESS_HASH );
				if ( '' !== $sent_hash && self::get_address_hash( $order ) !== $sent_hash ) {
					echo '<p class="kunaki-error">' . esc_html__( 'The shipping address was changed after this order was sent to Kunaki. Kunaki will ship to the original address unless you update it with Kunaki directly.', 'kunaki-woocommerce' ) . '</p>';
				}
			}

			if ( 'declined' === $failure_state ) {
				echo '<p class="kunaki-error">' . esc_html__( 'Kunaki declined this order. Retrying creates a new Kunaki order.', 'kunaki-woocommerce' ) . '</p>';
				self::render_retry_control( $order, __( 'Resubmit to Kunaki', 'kunaki-woocommerce' ) );
			}
		} else {
			$error     = self::get_failure_message( $order );
			$attempts  = (int) $order->get_meta( self::META_FULFILLMENT_ATTEMPTS );
			$failed_at = $order->get_meta( self::META_FULFILLMENT_FAILED );

			$status_label = 'exhausted' === $failure_state
				? __( 'Failed — automatic retries stopped, manual retry required', 'kunaki-woocommerce' )
				: __( 'Failed — will retry automatically', 'kunaki-woocommerce' );

			echo '<p class="kunaki-error"><strong>' . esc_html__( 'Status:', 'kunaki-woocommerce' ) . '</strong> '
				. esc_html( $status_label ) . '</p>';
			if ( '' !== $error ) {
				echo '<p><strong>' . esc_html__( 'Error:', 'kunaki-woocommerce' ) . '</strong> '
					. esc_html( $error ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( 'Attempts:', 'kunaki-woocommerce' ) . '</strong> '
				. esc_html( (string) $attempts ) . '</p>';
			if ( $failed_at ) {
				echo '<p><strong>' . esc_html__( 'Last attempt:', 'kunaki-woocommerce' ) . '</strong> '
					. esc_html( $failed_at ) . '</p>';
			}
			self::render_retry_control( $order, __( 'Retry Submission', 'kunaki-woocommerce' ) );
		}

		echo '</div>';
	}
}
