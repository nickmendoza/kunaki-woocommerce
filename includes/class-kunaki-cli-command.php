<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for Kunaki WooCommerce.
 */
class Kunaki_CLI_Command {

	/**
	 * Sync Kunaki order statuses.
	 *
	 * Queries the Kunaki API for status updates on all WooCommerce orders
	 * that have been submitted to Kunaki but not yet completed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List pending orders without syncing.
	 *
	 * [--limit=<number>]
	 * : Maximum number of orders to process.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kunaki sync
	 *     wp kunaki sync --dry-run
	 *     wp kunaki sync --limit=10
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$orders = wc_get_orders( array(
			'status'       => array( 'processing' ),
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID,
			'meta_compare' => 'EXISTS',
			'limit'        => -1,
			'return'       => 'ids',
		) );

		$count = count( $orders );

		if ( 0 === $count ) {
			\WP_CLI::success( 'No pending Kunaki orders to sync.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d pending Kunaki order(s).', $count ) );

		if ( $dry_run ) {
			foreach ( $orders as $order_id ) {
				\WP_CLI::log( sprintf( '  #%d', $order_id ) );
			}
			return;
		}

		$sync_limit = $limit > 0 ? $limit : -1;

		$sync = new Kunaki_Order_Status_Sync();
		$sync->sync_all_pending_orders( $sync_limit );

		$remaining = wc_get_orders( array(
			'status'       => array( 'processing' ),
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID,
			'meta_compare' => 'EXISTS',
			'limit'        => -1,
			'return'       => 'ids',
		) );

		$checked = $limit > 0 ? min( $count, $limit ) : $count;
		$updated = $count - count( $remaining );

		\WP_CLI::success( sprintf(
			'Sync complete. %d order(s) checked, %d status(es) updated.',
			$checked,
			$updated
		) );
	}
}
