<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Order_Status_Sync {

	public function init() {
		add_action( 'kunaki_wc_sync_order_status', array( $this, 'sync_all_pending_orders' ) );
	}

	/**
	 * Query Kunaki for status updates on all submitted but not yet completed orders.
	 */
	public function sync_all_pending_orders() {
		Kunaki_Logger::info( 'Starting Kunaki order status sync.' );

		$orders = wc_get_orders( array(
			'status'       => array( 'processing' ),
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID,
			'meta_compare' => 'EXISTS',
			'limit'        => 50,
			'orderby'      => 'date',
			'order'        => 'ASC',
		) );

		if ( empty( $orders ) ) {
			Kunaki_Logger::debug( 'No pending Kunaki orders to sync.' );
			return;
		}

		$api = new Kunaki_API_Client();

		foreach ( $orders as $order ) {
			try {
				$this->sync_single_order( $order, $api );
			} catch ( \Throwable $e ) {
				Kunaki_Logger::error(
					'Order #' . $order->get_id() . ': Sync failed with exception: ' . $e->getMessage()
				);
			}
		}

		Kunaki_Logger::info( 'Kunaki order status sync complete. Checked ' . count( $orders ) . ' orders.' );
	}

	/**
	 * Sync a single order's status with Kunaki.
	 *
	 * Uses a per-order lock to prevent concurrent cron executions from
	 * double-processing the same order (which could send duplicate emails).
	 *
	 * @param WC_Order          $order WooCommerce order.
	 * @param Kunaki_API_Client $api   API client instance.
	 */
	private function sync_single_order( $order, $api ) {
		$order_id        = $order->get_id();
		$kunaki_order_id = $order->get_meta( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID );

		if ( empty( $kunaki_order_id ) ) {
			return;
		}

		// Acquire per-order lock to prevent concurrent sync processing.
		global $wpdb;
		$lock_option = '_kunaki_sync_lock_' . $order_id;
		$lock_expiry = time() + 2 * MINUTE_IN_SECONDS;

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s",
			$lock_option,
			(string) time()
		) );

		$acquired = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$lock_option,
			(string) $lock_expiry
		) );

		if ( ! $acquired ) {
			Kunaki_Logger::debug( "Order #{$order_id}: Could not acquire sync lock, another process is syncing." );
			return;
		}

		try {
			$this->process_sync( $order, $api, $kunaki_order_id );
		} finally {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_option ) );
		}
	}

	/**
	 * Process the actual status sync for a single order.
	 *
	 * Meta and status changes are saved before customer-facing notes are added.
	 * This ensures that if save() fails, nothing is persisted and the next cron
	 * run retries from scratch. Customer emails are only sent after a successful
	 * save, preventing both stuck orders and duplicate notifications.
	 *
	 * @param WC_Order          $order            WooCommerce order.
	 * @param Kunaki_API_Client $api              API client instance.
	 * @param string            $kunaki_order_id  Kunaki order ID.
	 */
	private function process_sync( $order, $api, $kunaki_order_id ) {
		$order_id = $order->get_id();

		$result = $api->get_order_status( $kunaki_order_id );

		if ( is_wp_error( $result ) ) {
			Kunaki_Logger::error( "Order #{$order_id}: Failed to get Kunaki status: " . $result->get_error_message() );
			return;
		}

		$kunaki_status = $result['status'];
		$tracking_type = $result['tracking_type'];
		$tracking_id   = $result['tracking_id'];

		$previous_status = $order->get_meta( '_kunaki_order_status' );

		// Skip if status hasn't changed.
		if ( $previous_status === $kunaki_status ) {
			return;
		}

		$order->update_meta_data( '_kunaki_order_status', $kunaki_status );

		Kunaki_Logger::info( "Order #{$order_id}: Kunaki status changed from '{$previous_status}' to '{$kunaki_status}'." );

		// Defer all order notes until after save() succeeds. If save() fails,
		// no meta is persisted and the next cron run retries cleanly — without
		// duplicate notes or duplicate customer emails.
		$customer_note = '';
		$admin_note    = '';

		switch ( $kunaki_status ) {
			case 'SHIPPED':
				if ( 'NA' !== $tracking_id && ! empty( $tracking_id ) ) {
					$order->update_meta_data( '_kunaki_tracking_type', $tracking_type );
					$order->update_meta_data( '_kunaki_tracking_id', $tracking_id );

					$customer_note = sprintf(
						/* translators: 1: carrier name, 2: tracking number */
						__( 'Kunaki order shipped! Carrier: %1$s, Tracking: %2$s', 'kunaki-woocommerce' ),
						$tracking_type,
						$tracking_id
					);
				} else {
					$customer_note = __( 'Kunaki order shipped! (No tracking number available)', 'kunaki-woocommerce' );
				}

				// Transition to completed if all items are Kunaki products.
				// The customer note with tracking is sent separately after
				// save() because set_status() notes are admin-only and not
				// included in the "Completed Order" email template.
				if ( $this->all_items_are_kunaki( $order ) ) {
					$order->set_status( 'completed', __( 'Kunaki fulfillment complete.', 'kunaki-woocommerce' ) );
				}
				break;

			case 'DECLINED':
				$admin_note = __( 'Kunaki order was DECLINED. Please check your Kunaki account for details.', 'kunaki-woocommerce' );
				$order->set_status( 'on-hold', __( 'Kunaki order declined.', 'kunaki-woocommerce' ) );
				break;

			case 'PROCESSING':
			case 'APPROVED':
			case 'PENDING':
				$admin_note = sprintf(
					/* translators: %s: Kunaki order status */
					__( 'Kunaki order status update: %s', 'kunaki-woocommerce' ),
					$kunaki_status
				);
				break;
		}

		// Persist all meta and status changes before adding notes.
		$order->save();

		// Now safe — meta is persisted, so the next cron run won't retry.
		if ( '' !== $admin_note ) {
			$order->add_order_note( $admin_note, false );
		}
		if ( '' !== $customer_note ) {
			$order->add_order_note( $customer_note, true );
		}
	}

	/**
	 * Check if all line items in the order are Kunaki products.
	 *
	 * Uses a snapshot stored at fulfillment time so that deleting or
	 * modifying products after order placement doesn't break auto-complete.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function all_items_are_kunaki( $order ) {
		$stored = $order->get_meta( '_kunaki_all_items' );
		if ( '' !== $stored ) {
			return 'yes' === $stored;
		}

		// Fallback for orders submitted before the snapshot was stored.
		foreach ( $order->get_items() as $item ) {
			if ( ! Kunaki_Product_Meta::is_kunaki_product( $item->get_product_id() ) ) {
				return false;
			}
		}
		return true;
	}
}
