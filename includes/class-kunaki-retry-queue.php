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

	public function render_page() {
		$failed_orders = wc_get_orders( array(
			'status'       => 'processing',
			'meta_key'     => Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR,
			'meta_compare' => 'EXISTS',
			'limit'        => 100,
			'orderby'      => 'date',
			'order'        => 'DESC',
		) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kunaki Failed Orders', 'kunaki-woocommerce' ) . '</h1>';

		if ( empty( $failed_orders ) ) {
			echo '<p>' . esc_html__( 'No failed orders found.', 'kunaki-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Attempts', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Failure', 'kunaki-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'kunaki-woocommerce' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $failed_orders as $order ) {
			$order_id  = $order->get_id();
			$edit_url  = $order->get_edit_order_url();
			$error     = $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR );
			$attempts  = (int) $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS );
			$failed_at = $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '"><strong>#' . esc_html( $order_id ) . '</strong></a></td>';
			$date_created = $order->get_date_created();
			echo '<td>' . esc_html( $date_created ? $date_created->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</td>';
			echo '<td>' . esc_html( $error ) . '</td>';
			echo '<td>' . esc_html( $attempts ) . '</td>';
			echo '<td>' . esc_html( $failed_at ) . '</td>';
			echo '<td>';
			echo '<button type="button" class="button kunaki-retry-button" data-order-id="' . esc_attr( $order_id ) . '">'
				. esc_html__( 'Retry', 'kunaki-woocommerce' ) . '</button>';
			echo ' <span class="spinner"></span>';
			echo '<span class="kunaki-retry-result"></span>';
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
