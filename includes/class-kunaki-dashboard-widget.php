<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Dashboard_Widget {

	const TRANSIENT_KEY = 'kunaki_dashboard_counts';
	const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'kunaki_orders_summary',
			__( 'Kunaki Orders', 'kunaki-woocommerce' ),
			array( $this, 'render_widget' )
		);
	}

	public function render_widget() {
		$circuit_breaker = new Kunaki_Circuit_Breaker();
		$cb_state        = $circuit_breaker->get_state();

		if ( 'closed' !== $cb_state['state'] ) {
			$label = 'open' === $cb_state['state']
				? __( 'API circuit breaker is open — requests are paused.', 'kunaki-woocommerce' )
				: __( 'API circuit breaker is half-open — probing for recovery.', 'kunaki-woocommerce' );
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $label ) . '</p></div>';
		}

		$counts = $this->get_counts();

		$orders_url = admin_url( 'edit.php?post_type=shop_order&post_status=wc-processing' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$orders_url = admin_url( 'admin.php?page=wc-orders&status=processing' );
		}
		$failed_url = admin_url( 'admin.php?page=kunaki-failed-orders' );

		echo '<ul class="kunaki-dashboard-summary">';

		echo '<li>';
		echo '<a href="' . esc_url( $orders_url ) . '">';
		echo esc_html__( 'Fulfilling', 'kunaki-woocommerce' );
		echo '</a> ';
		echo '<span class="kunaki-count">' . esc_html( (string) $counts['fulfilling'] ) . '</span>';
		echo '</li>';

		echo '<li>';
		echo '<a href="' . esc_url( $failed_url ) . '">';
		echo esc_html__( 'Action needed', 'kunaki-woocommerce' );
		echo '</a> ';
		echo '<span class="kunaki-count kunaki-count-alert">' . esc_html( (string) $counts['action_needed'] ) . '</span>';
		echo '</li>';

		echo '<li>';
		echo '<a href="' . esc_url( $failed_url ) . '">';
		echo esc_html__( 'Retrying', 'kunaki-woocommerce' );
		echo '</a> ';
		echo '<span class="kunaki-count">' . esc_html( (string) $counts['retrying'] ) . '</span>';
		echo '</li>';

		// Mixed carts: Kunaki shipped the discs, but the order stays processing
		// until the admin ships the other items and completes it.
		echo '<li>';
		echo '<a href="' . esc_url( $orders_url ) . '">';
		echo esc_html__( 'Discs shipped, rest of order pending', 'kunaki-woocommerce' );
		echo '</a> ';
		echo '<span class="kunaki-count">' . esc_html( (string) $counts['ready_to_complete'] ) . '</span>';
		echo '</li>';

		echo '</ul>';

		$estimator = new Kunaki_Delivery_Estimator();
		$stats     = $estimator->get_manufacturing_stats();

		if ( null !== $stats ) {
			echo '<p class="kunaki-manufacturing-time description">';
			echo esc_html( sprintf(
				/* translators: 1: average manufacturing days, 2: number of orders in sample */
				__( 'Avg. manufacturing time: %1$s days (based on %2$d orders)', 'kunaki-woocommerce' ),
				number_format_i18n( $stats['mean_days'], 1 ),
				$stats['sample_size']
			) );
			echo '</p>';
		}
	}

	/**
	 * Get cached order counts, refreshing from the database if stale.
	 *
	 * @return array{fulfilling: int, action_needed: int, retrying: int, ready_to_complete: int}
	 */
	public function get_counts() {
		$cached = get_transient( self::TRANSIENT_KEY );
		// Ignore caches written by older versions with fewer keys.
		if ( is_array( $cached ) && isset( $cached['fulfilling'], $cached['action_needed'], $cached['retrying'], $cached['ready_to_complete'] ) ) {
			return $cached;
		}

		$counts = $this->query_counts();
		set_transient( self::TRANSIENT_KEY, $counts, self::TRANSIENT_TTL );
		return $counts;
	}

	/**
	 * Query live order counts from the database.
	 *
	 * Uses top-level meta_key/meta_value args, not meta_query: WooCommerce
	 * silently ignores meta_query on order queries unless HPOS is enabled.
	 *
	 * "Action needed" and "Retrying" match exactly what the Kunaki Failures
	 * page lists, since both link there.
	 *
	 * @return array{fulfilling: int, action_needed: int, retrying: int, ready_to_complete: int}
	 */
	private function query_counts() {
		// All submitted processing orders.
		/** @var int[] $submitted */
		$submitted = wc_get_orders( array(
			'status'       => 'processing',
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED,
			'meta_value'   => 'yes',
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Shipped but still processing (mixed carts needing manual completion).
		/** @var int[] $shipped */
		$shipped = wc_get_orders( array(
			'status'       => 'processing',
			'meta_key'     => '_kunaki_order_status',
			'meta_value'   => 'SHIPPED',
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Exhausted retries.
		/** @var int[] $exhausted */
		$exhausted = wc_get_orders( array(
			'status'       => 'processing',
			'meta_key'     => Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED,
			'meta_compare' => 'EXISTS',
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Declined by Kunaki — on-hold (as sync leaves them) or moved back to
		// processing for resubmission.
		/** @var int[] $declined */
		$declined = wc_get_orders( array(
			'status'       => array( 'processing', 'on-hold' ),
			'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_STATUS,
			'meta_value'   => 'DECLINED',
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Failed submissions awaiting automatic retry.
		// Computed via array_diff — HPOS NOT EXISTS meta queries can return false positives.
		/** @var int[] $with_error */
		$with_error = wc_get_orders( array(
			'status'       => 'processing',
			'meta_key'     => Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR,
			'meta_compare' => 'EXISTS',
			'limit'  => -1,
			'return' => 'ids',
		) );
		$retrying = array_diff( $with_error, $submitted, $exhausted );

		return array(
			'fulfilling'        => count( array_diff( $submitted, $shipped, $declined ) ),
			'action_needed'     => count( array_unique( array_merge( $exhausted, $declined ) ) ),
			'retrying'          => count( $retrying ),
			'ready_to_complete' => count( $shipped ),
		);
	}
}
