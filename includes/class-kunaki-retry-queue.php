<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Retry_Queue {

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'wp_ajax_kunaki_retry_order', array( $this, 'ajax_retry_order' ) );
	}

	public function add_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Kunaki Failed Orders', 'kunaki-woocommerce' ),
			__( 'Kunaki Failures', 'kunaki-woocommerce' ),
			'manage_woocommerce',
			'kunaki-failed-orders',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Collect orders needing attention: retrying, retries exhausted, or declined by Kunaki.
	 *
	 * Uses separate EXISTS / value queries merged in PHP — HPOS NOT EXISTS
	 * meta queries can return false positives.
	 *
	 * @return WC_Order[] Orders sorted newest first.
	 */
	public function get_failed_orders() {
		$base = array(
			'status'  => 'processing',
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		$queries = array(
			$base + array(
				'meta_key'     => Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR,
				'meta_compare' => 'EXISTS',
			),
			$base + array(
				'meta_key'     => Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED,
				'meta_value'   => 'yes',
			),
			array(
				'status'       => array( 'processing', 'on-hold' ),
				'meta_key'     => Kunaki_Order_Fulfillment::META_KUNAKI_STATUS,
				'meta_value'   => 'DECLINED',
			) + $base,
		);

		$orders = array();
		foreach ( $queries as $query ) {
			foreach ( wc_get_orders( $query ) as $order ) {
				if ( isset( $orders[ $order->get_id() ] ) ) {
					continue;
				}
				if ( null !== Kunaki_Order_Fulfillment::get_failure_state( $order ) ) {
					$orders[ $order->get_id() ] = $order;
				}
			}
		}

		krsort( $orders );
		return array_values( $orders );
	}

	public function render_page() {
		$failed_orders = $this->get_failed_orders();
		$count         = count( $failed_orders );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kunaki Failed Orders', 'kunaki-woocommerce' )
			. ' <span class="count">(' . esc_html( (string) $count ) . ')</span></h1>';

		if ( empty( $failed_orders ) ) {
			echo '<p>' . esc_html__( 'No failed orders found.', 'kunaki-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		$state_labels = array(
			'retrying'  => __( 'Retrying automatically', 'kunaki-woocommerce' ),
			'exhausted' => __( 'Retries exhausted', 'kunaki-woocommerce' ),
			'declined'  => __( 'Declined by Kunaki', 'kunaki-woocommerce' ),
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Attempts', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Failure', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'kunaki-woocommerce' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $failed_orders as $order ) {
			$order_id  = $order->get_id();
			$state     = Kunaki_Order_Fulfillment::get_failure_state( $order );
			$attempts  = (int) $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS );
			$failed_at = $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#' . esc_html( (string) $order_id ) . '</strong></a></td>';
			$date_created = $order->get_date_created();
			echo '<td>' . esc_html( $date_created ? $date_created->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</td>';
			echo '<td>' . esc_html( $state_labels[ $state ] ?? '' ) . '</td>';
			echo '<td>' . esc_html( Kunaki_Order_Fulfillment::get_failure_message( $order ) ) . '</td>';
			echo '<td>' . esc_html( (string) $attempts ) . '</td>';
			echo '<td>' . esc_html( $failed_at ) . '</td>';
			echo '<td>';
			Kunaki_Order_Fulfillment::render_retry_control( $order, __( 'Retry', 'kunaki-woocommerce' ) );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	public function ajax_retry_order() {
		check_ajax_referer( 'kunaki_retry_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'kunaki-woocommerce' ) ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'kunaki-woocommerce' ) ) );
		}

		$fulfillment = new Kunaki_Order_Fulfillment();
		$result      = $fulfillment->prepare_for_retry( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: order ID */
				__( 'Order #%d submitted to Kunaki successfully.', 'kunaki-woocommerce' ),
				$order_id
			),
		) );
	}
}
