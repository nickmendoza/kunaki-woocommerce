<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Order_Status_Sync {

	public function init() {
		add_action( 'kunaki_wc_sync_order_status', array( $this, 'sync_all_pending_orders' ) );
	}

	/**
	 * Query Kunaki for status updates on all submitted but not yet completed orders.
	 *
	 * @param int $limit Maximum orders to process per batch. Default 50.
	 */
	public function sync_all_pending_orders( $limit = 50 ) {
		Kunaki_Logger::info( 'Starting order status sync.' );

		$orders = wc_get_orders( array(
			'status'       => array( 'processing' ),
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID,
			'meta_compare' => 'EXISTS',
			'limit'        => $limit,
			'orderby'      => 'date',
			'order'        => 'ASC',
		) );

		if ( empty( $orders ) ) {
			Kunaki_Logger::debug( 'No pending orders to sync.' );
			return;
		}

		$api = new Kunaki_API_Client();

		foreach ( $orders as $order ) {
			try {
				$this->sync_single_order( $order, $api );
			} catch ( \Throwable $e ) {
				Kunaki_Logger::error( 'Sync failed.', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
			}
		}

		Kunaki_Logger::info( 'Order status sync complete.', array( 'orders_checked' => count( $orders ) ) );
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
			Kunaki_Logger::debug( 'Could not acquire sync lock.', array( 'order_id' => $order_id ) );
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

		// Recover notes from a previous crash between save() and add_order_note().
		$pending = $order->get_meta( '_kunaki_sync_note_pending' );
		if ( '' !== $pending ) {
			$this->send_pending_notes( $order );
			return;
		}

		$result = $api->get_order_status( $kunaki_order_id );

		if ( is_wp_error( $result ) ) {
			Kunaki_Logger::error( 'Failed to get Kunaki status.', array( 'order_id' => $order_id, 'error' => $result->get_error_message() ) );
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

		Kunaki_Logger::info( 'Kunaki status changed.', array( 'order_id' => $order_id, 'from' => $previous_status, 'to' => $kunaki_status ) );

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

		// Store pending notes in meta so they survive a crash between save()
		// and add_order_note(). The next cron run recovers them.
		$notes     = array();
		$notes_json = '';
		if ( '' !== $admin_note ) {
			$notes[] = array( 'text' => $admin_note, 'is_customer' => false );
		}
		if ( '' !== $customer_note ) {
			$notes[] = array( 'text' => $customer_note, 'is_customer' => true );
		}
		if ( ! empty( $notes ) ) {
			$notes_json = wp_json_encode( $notes );
			$order->update_meta_data( '_kunaki_sync_note_pending', $notes_json );
		}

		// Persist all meta and status changes before adding notes.
		try {
			$order->save();
		} catch ( \Throwable $e ) {
			Kunaki_Logger::error( 'save() failed during sync.', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
			// Pending notes meta remains in memory (unsaved); next cron run will
			// re-detect the status change, rebuild notes, and retry the save.
			return;
		}

		// Now safe — meta is persisted, so the next cron run won't retry.
		$this->send_pending_notes( $order, $notes_json );
	}

	/**
	 * Send pending order notes and clear the pending flag.
	 *
	 * @param WC_Order    $order        WooCommerce order.
	 * @param string|null $pending_json JSON-encoded notes array. Reads from meta if null.
	 */
	private function send_pending_notes( $order, $pending_json = null ) {
		if ( null === $pending_json ) {
			$pending_json = $order->get_meta( '_kunaki_sync_note_pending' );
		}
		if ( '' === $pending_json ) {
			return;
		}

		$notes = json_decode( $pending_json, true );
		if ( is_array( $notes ) ) {
			foreach ( $notes as $note ) {
				$order->add_order_note( $note['text'], $note['is_customer'] );
			}
		}

		$order->delete_meta_data( '_kunaki_sync_note_pending' );
		try {
			$order->save_meta_data();
		} catch ( \Throwable $_ ) {
			// Worst case: note is sent again on next cron run — better than lost.
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
