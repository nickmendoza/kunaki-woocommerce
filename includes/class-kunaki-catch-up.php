<?php
defined( 'ABSPATH' ) || exit;

/**
 * Picks up orders that were paid while the plugin was inactive.
 *
 * With the plugin inactive nothing listens for an order reaching Processing,
 * so the order is never queued and leaves no trace. The deactivation and
 * activation hooks record the gap. A one-time scan on the retry cron then
 * hands every disc order paid in it to the normal failure record: the retry
 * cron sends the ones that are safe to send, and the rest wait under
 * "Action needed" for someone to click Retry. The scan never calls Kunaki.
 */
class Kunaki_Catch_Up {

	/** The inactive window and the scan's progress through it. */
	const OPTION = 'kunaki_wc_catch_up';

	/** How many orders the scan has held since the Failures page was last opened. */
	const NOTICE_OPTION = 'kunaki_wc_catch_up_notice';

	/** Uninstall removes every option starting with _kunaki_lock_. */
	const LOCK = '_kunaki_lock_catch_up';

	/** The scan never looks further back than this, however long the plugin was off. */
	const MAX_LOOKBACK = 30 * DAY_IN_SECONDS;

	/** An order paid longer ago than this is held, not sent: it may have been fulfilled by hand. */
	const AUTO_SEND_MAX_WAIT = DAY_IN_SECONDS;

	/**
	 * The furthest past reactivation the scan looks. WordPress runs the
	 * activation hook before it saves the plugin as active, so a checkout
	 * already under way can be paid moments later by a request that never
	 * loaded the plugin. The scan never looks past the moment it runs, which is
	 * normally about a minute after reactivation, so that is the usual reach.
	 */
	const ACTIVATION_GRACE = 5 * MINUTE_IN_SECONDS;

	/** Orders checked per cron tick. */
	const BATCH_SIZE = 200;

	/** How many cron ticks in a row the scan goes back to an order it could not check. */
	const MAX_TRIES = 3;

	public function init() {
		// Before retry_failed_orders() (priority 10), so orders handed over for
		// automatic sending go out in the same cron run.
		add_action( 'kunaki_wc_retry_failed_orders', array( $this, 'run' ), 5 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Deactivation hook: remember when the plugin went inactive.
	 *
	 * Only touches one option. WooCommerce, Action Scheduler and the plugin's
	 * other classes may not be loaded when this runs.
	 */
	public static function mark_deactivated() {
		$state = get_option( self::OPTION );

		// A start that is already recorded belongs to a scan that never
		// finished. Keep it so the next activation scans that whole gap again;
		// orders already handed over are skipped by their record.
		$from = is_array( $state ) && ! empty( $state['from'] ) ? (int) $state['from'] : time();

		update_option( self::OPTION, array( 'from' => $from ), false );
	}

	/**
	 * Activation hook: close the window and leave a scan pending.
	 *
	 * Only touches one option, for the same reason as mark_deactivated().
	 */
	public static function mark_activated() {
		$state = get_option( self::OPTION );

		// No record of a deactivation (first install, or deactivated by a
		// version that didn't record it): nothing to catch up on.
		if ( ! is_array( $state ) || empty( $state['from'] ) ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'from'   => (int) $state['from'],
				'to'     => time(),
				'cursor' => 0,
				'sent'   => 0,
				'held'   => 0,
			),
			false
		);
	}

	/**
	 * Cron handler: check one batch of the orders paid in the inactive window.
	 *
	 * Nothing may be thrown from here: retry_failed_orders() runs next on the
	 * same hook and an exception would stop it.
	 */
	public function run() {
		try {
			if ( ! $this->is_pending( get_option( self::OPTION ) ) ) {
				return;
			}

			if ( ! $this->acquire_lock() ) {
				return;
			}

			try {
				// Read it again, from the database, now that no other run can
				// be part-way through a batch.
				wp_cache_delete( self::OPTION, 'options' );
				$state = get_option( self::OPTION );
				if ( $this->is_pending( $state ) ) {
					$this->scan( $state );
				}
			} finally {
				$this->release_lock();
			}
		} catch ( \Throwable $e ) {
			Kunaki_Logger::error( 'Catch-up scan failed.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Whether the option describes a closed window that still has to be scanned.
	 *
	 * @param mixed $state Value of the option.
	 * @return bool
	 */
	private function is_pending( $state ) {
		return is_array( $state ) && ! empty( $state['from'] ) && ! empty( $state['to'] );
	}

	/**
	 * Check the next batch of orders and save how far the scan has got.
	 *
	 * @param array $state The pending window, as stored in the option.
	 */
	private function scan( array $state ) {
		$to     = (int) $state['to'];
		$from   = max( (int) $state['from'], $to - self::MAX_LOOKBACK );
		$until  = min( time(), $to + self::ACTIVATION_GRACE );
		$cursor = (int) ( $state['cursor'] ?? 0 );
		$tries  = (int) ( $state['tries'] ?? 0 );

		// IDs only, and no meta arguments: whether an order has a Kunaki record
		// is checked in PHP, where it behaves the same under both order storages.
		$ids = wc_get_orders( array(
			'status'    => 'processing',
			'date_paid' => $from . '...' . $until,
			'limit'     => -1,
			'return'    => 'ids',
			'orderby'   => 'ID',
			'order'     => 'ASC',
		) );

		// Oldest first. Only an order placed before the deactivation can have a
		// Kunaki shipping choice, so the ones that can be sent automatically have
		// the lowest IDs and are reached first.
		$ids = array_map( 'absint', (array) $ids );
		sort( $ids );
		$ids = array_values( array_filter( $ids, function ( $id ) use ( $cursor ) {
			return $id > $cursor;
		} ) );

		$batch       = array_slice( $ids, 0, self::BATCH_SIZE );
		$fulfillment = new Kunaki_Order_Fulfillment();
		$sent        = 0;
		$held        = 0;
		$unchecked   = array();

		foreach ( $batch as $order_id ) {
			try {
				$outcome = $this->check_order( $order_id, $fulfillment );
			} catch ( \Throwable $e ) {
				// One bad order must not stop the rest.
				Kunaki_Logger::error( 'Catch-up scan could not check an order.', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
				$unchecked[] = $order_id;
				$outcome     = '';
			}

			if ( 'held' === $outcome ) {
				$held++;
				// Counted straight away, so a batch that dies part-way has still
				// told the admin about the orders it held.
				update_option( self::NOTICE_OPTION, (int) get_option( self::NOTICE_OPTION, 0 ) + 1 );
			} elseif ( 'sent' === $outcome ) {
				$sent++;
			}

			$cursor = $order_id;
		}

		if ( $sent + $held > 0 ) {
			delete_transient( Kunaki_Dashboard_Widget::TRANSIENT_KEY );
		}

		// Before the checks below, which can end this run early: these orders
		// are held whatever happens to the window.
		if ( $held > 0 ) {
			$this->notify_orders_held( $held );
		}

		// Read the option from the database, not this request's cached copy. If
		// the plugin was deactivated or reactivated while this batch ran, the
		// option now describes a different window and must be left alone.
		wp_cache_delete( self::OPTION, 'options' );
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) || (int) ( $current['to'] ?? 0 ) !== $to ) {
			return;
		}

		$sent += (int) ( $state['sent'] ?? 0 );
		$held += (int) ( $state['held'] ?? 0 );

		$progress = array(
			'from'   => (int) $state['from'],
			'to'     => $to,
			'cursor' => $cursor,
			'sent'   => $sent,
			'held'   => $held,
			'tries'  => 0,
		);

		if ( $unchecked ) {
			if ( $tries + 1 < self::MAX_TRIES ) {
				// An order that could not be checked may be one that was missed,
				// and the error is most likely temporary. Start the next tick
				// from it. Orders after it that were handled just now are
				// skipped then by their record.
				$progress['cursor'] = $unchecked[0] - 1;
				$progress['tries']  = $tries + 1;
				update_option( self::OPTION, $progress, false );
				return;
			}
			// Still failing after several ticks. Move on, so one bad order
			// can't keep the scan from ever finishing.
			Kunaki_Logger::error( 'Catch-up scan gave up on orders it could not check.', array( 'order_ids' => $unchecked ) );
		}

		if ( count( $ids ) > count( $batch ) ) {
			update_option( self::OPTION, $progress, false );
			return;
		}

		delete_option( self::OPTION );
		Kunaki_Logger::info( 'Catch-up scan finished.', array(
			'from' => gmdate( 'c', $from ),
			'to'   => gmdate( 'c', $to ),
			'sent' => $sent,
			'held' => $held,
		) );
	}

	/**
	 * Tell listeners that this run held orders for a person.
	 *
	 * Once per run, not once per order: after a long break there can be many,
	 * and each is listed on the Failures page. A listener that throws must not
	 * get out, or this run's progress would not be saved.
	 *
	 * @param int $count How many orders this run held.
	 */
	private function notify_orders_held( $count ) {
		try {
			/**
			 * Fires when a catch-up scan run has held orders for someone to review.
			 *
			 * @param int $count How many orders this run held.
			 */
			do_action( 'kunaki_wc_catch_up_orders_held', $count );
		} catch ( \Throwable $e ) {
			Kunaki_Logger::error( 'A listener on kunaki_wc_catch_up_orders_held threw.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Hand one order to the failure record if it was missed.
	 *
	 * @param int                      $order_id    WooCommerce order ID.
	 * @param Kunaki_Order_Fulfillment $fulfillment Fulfillment instance.
	 * @return string 'sent', 'held', or '' if the order was left alone.
	 */
	private function check_order( $order_id, Kunaki_Order_Fulfillment $fulfillment ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'processing' !== $order->get_status() ) {
			return '';
		}

		if ( $this->has_kunaki_record( $order ) ) {
			return '';
		}

		if ( ! $this->has_kunaki_items( $order ) ) {
			return '';
		}

		$paid = $order->get_date_paid();
		if ( ! $paid ) {
			return '';
		}

		// Measured now, not at reactivation: a scan that runs late holds more
		// orders, never fewer.
		$waited_too_long = ( time() - $paid->getTimestamp() ) >= self::AUTO_SEND_MAX_WAIT;

		return $fulfillment->record_missed_order( $order, $waited_too_long );
	}

	/**
	 * Whether the plugin has ever touched this order, or is about to.
	 *
	 * An order with any trace is left to the code that made it. Only an order
	 * with none at all can have been missed.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function has_kunaki_record( $order ) {
		$meta_keys = array(
			Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED,
			Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID,
			Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR,
			Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED,
			Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED,
			Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR,
			Kunaki_Order_Fulfillment::META_KUNAKI_STATUS,
		);
		foreach ( $meta_keys as $meta_key ) {
			if ( '' !== (string) $order->get_meta( $meta_key ) ) {
				return true;
			}
		}

		// Durable markers and the submission lock live in wp_options.
		global $wpdb;
		$order_id = $order->get_id();
		$rows     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s)",
			'_kunaki_submitted_' . $order_id,
			'_kunaki_failed_' . $order_id,
			'_kunaki_lock_' . $order_id
		) );
		if ( $rows > 0 ) {
			return true;
		}

		// A submission that is queued or running.
		return function_exists( 'as_next_scheduled_action' )
			&& (bool) as_next_scheduled_action(
				Kunaki_Order_Fulfillment::SUBMIT_ACTION,
				array( 'order_id' => $order_id ),
				Kunaki_Order_Fulfillment::ACTION_GROUP
			);
	}

	/**
	 * Whether the order contains at least one Kunaki product.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function has_kunaki_items( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! empty( Kunaki_Product_Meta::get_kunaki_id_for_item( $item->get_product_id(), $item->get_variation_id() ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Take the scan lock, so two cron runs can't check the same batch.
	 *
	 * Same single-row pattern as the per-order submission lock.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private function acquire_lock() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s",
			self::LOCK,
			(string) time()
		) );

		return (bool) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::LOCK,
			(string) ( time() + 5 * MINUTE_IN_SECONDS )
		) );
	}

	private function release_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) );
	}

	/**
	 * Tell the admin that the scan held orders for them.
	 *
	 * Alerts are off by default, so without this a held order could sit unseen.
	 * The alert, when alerts are on, reports this same count.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$count = (int) get_option( self::NOTICE_OPTION, 0 );
		if ( $count < 1 ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post( sprintf(
			/* translators: 1: number of orders, 2: URL of the Kunaki Failures page */
			_n(
				'<strong>Kunaki WooCommerce:</strong> While the plugin was inactive, %1$d disc order was paid and was not sent to Kunaki automatically. <a href="%2$s">Review it</a>',
				'<strong>Kunaki WooCommerce:</strong> While the plugin was inactive, %1$d disc orders were paid and were not sent to Kunaki automatically. <a href="%2$s">Review them</a>',
				$count,
				'kunaki-woocommerce'
			),
			$count,
			esc_url( admin_url( 'admin.php?page=kunaki-failed-orders' ) )
		) );
		echo '</p></div>';
	}

	/**
	 * Stop showing the notice. Called when the Failures page is opened.
	 */
	public static function clear_notice() {
		delete_option( self::NOTICE_OPTION );
	}
}
