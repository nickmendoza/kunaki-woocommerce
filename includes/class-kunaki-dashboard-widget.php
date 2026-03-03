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

		echo '</ul>';
	}

	/**
	 * Get cached order counts, refreshing from the database if stale.
	 *
	 * @return array{fulfilling: int, action_needed: int, retrying: int}
	 */
	public function get_counts() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = $this->query_counts();
		set_transient( self::TRANSIENT_KEY, $counts, self::TRANSIENT_TTL );
		return $counts;
	}

	/**
	 * Query live order counts from the database.
	 *
	 * Uses only flat meta_query arrays (no nested relation => 'OR') for
	 * reliable behaviour with both the legacy post-meta store and HPOS
	 * custom order tables.
	 *
	 * @return array{fulfilling: int, action_needed: int, retrying: int}
	 */
	private function query_counts() {
		// All submitted processing orders.
		/** @var int[] $submitted */
		$submitted = wc_get_orders( array(
			'status'     => 'processing',
			'meta_query' => array(
				array( 'key' => Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED, 'value' => 'yes' ),
			),
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Shipped but still processing (mixed carts needing manual completion).
		/** @var int[] $shipped */
		$shipped = wc_get_orders( array(
			'status'     => 'processing',
			'meta_query' => array(
				array( 'key' => '_kunaki_order_status', 'value' => 'SHIPPED' ),
			),
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Exhausted retries.
		/** @var int[] $exhausted */
		$exhausted = wc_get_orders( array(
			'status'     => 'processing',
			'meta_query' => array(
				array( 'key' => Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED, 'compare' => 'EXISTS' ),
			),
			'limit'  => -1,
			'return' => 'ids',
		) );

		// Failed submissions awaiting automatic retry.
		/** @var int[] $retrying */
		$retrying = wc_get_orders( array(
			'status'     => 'processing',
			'meta_query' => array(
				array( 'key' => Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR, 'compare' => 'EXISTS' ),
				array( 'key' => Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED, 'compare' => 'NOT EXISTS' ),
				array( 'key' => Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED, 'compare' => 'NOT EXISTS' ),
			),
			'limit'  => -1,
			'return' => 'ids',
		) );

		return array(
			'fulfilling'    => count( array_diff( $submitted, $shipped ) ),
			'action_needed' => count( array_unique( array_merge( $exhausted, $shipped ) ) ),
			'retrying'      => count( $retrying ),
		);
	}
}
