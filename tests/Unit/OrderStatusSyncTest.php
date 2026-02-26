<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Order_Status_Sync;
use Kunaki_Order_Fulfillment;
use WP_Error;

class OrderStatusSyncTest extends KunakiTestCase {

	/** @var Kunaki_Order_Status_Sync */
	private $sync;

	protected function set_up() {
		parent::set_up();
		$this->sync = new Kunaki_Order_Status_Sync();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return 'kunaki_wc_debug_log' === $key ? 'no' : $default;
		} );

		$this->mock_wpdb_lock();
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_no_pending_orders() {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		$this->sync->sync_all_pending_orders();
		$this->addToAssertionCount( 1 ); // no exception = pass
	}

	public function test_shipped_completes_all_kunaki_order() {
		$order = $this->create_order_mock( 'KUN123', 'PROCESSING' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', 'SHIPPED' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_tracking_type', 'USPS' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_tracking_id', '9400111' )->once();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'yes' );
		$order->shouldReceive( 'set_status' )->with( 'completed', Mockery::any() )->once();
		// save() must happen before the customer email is sent.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/USPS.*9400111/' ), true )->once()->ordered();

		$this->sync->sync_all_pending_orders();
	}

	public function test_shipped_no_tracking() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'NA', 'NA' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', 'SHIPPED' )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_tracking_type', Mockery::any() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'yes' );
		$order->shouldReceive( 'set_status' )->once();
		// save() must happen before the customer email is sent.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/No tracking/' ), true )->once()->ordered();

		$this->sync->sync_all_pending_orders();
	}

	public function test_shipped_mixed_cart_stays_processing() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'no' );
		$order->shouldNotReceive( 'set_status' );
		// save() must happen before the customer email is sent.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::any(), true )->once()->ordered();

		$this->sync->sync_all_pending_orders();
	}

	public function test_declined_goes_on_hold() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'DECLINED', '', '' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', 'DECLINED' )->once();
		$order->shouldReceive( 'set_status' )->with( 'on-hold', Mockery::any() )->once();
		// save() must happen before notes are added.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/DECLINED/' ), false )->once()->ordered();

		$this->sync->sync_all_pending_orders();
	}

	/**
	 * @dataProvider intermediate_statuses
	 */
	public function test_intermediate_status_adds_note( $status ) {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', $status, '', '' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', $status )->once();
		$order->shouldNotReceive( 'set_status' );
		// save() must happen before notes are added.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( "/{$status}/" ), false )->once()->ordered();

		$this->sync->sync_all_pending_orders();
	}

	public function intermediate_statuses() {
		return array(
			'processing' => array( 'PROCESSING' ),
			'approved'   => array( 'APPROVED' ),
			'pending'    => array( 'PENDING' ),
		);
	}

	public function test_unchanged_status_is_skipped() {
		$order = $this->create_order_mock( 'KUN123', 'PROCESSING' );

		$this->mock_api_status( 'KUN123', 'PROCESSING', '', '' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'add_order_note' );
		$order->shouldNotReceive( 'save' );

		$this->sync->sync_all_pending_orders();
	}

	public function test_api_error_is_handled() {
		$order = $this->create_order_mock( 'KUN123', '' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'timeout', 'Timeout' ) );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->sync->sync_all_pending_orders();
	}

	// --- Helpers ---

	private function create_order_mock( $kunaki_order_id, $previous_status = '' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID )
			->andReturn( $kunaki_order_id );
		$order->shouldReceive( 'get_meta' )
			->with( '_kunaki_order_status' )
			->andReturn( $previous_status );

		return $order;
	}

	private function mock_wc_get_orders( $orders ) {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( $orders );
	}

	private function mock_api_status( $order_id, $status, $tracking_type, $tracking_id ) {
		$xml = "<Response>
			<ErrorCode>0</ErrorCode>
			<OrderId>{$order_id}</OrderId>
			<OrderStatus>{$status}</OrderStatus>
			<TrackingType>{$tracking_type}</TrackingType>
			<TrackingId>{$tracking_id}</TrackingId>
		</Response>";

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $xml ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $xml );
	}

	private function mock_wpdb_lock( $acquired = true ) {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'query' )->andReturn( $acquired ? 1 : 0 );
		$wpdb->shouldReceive( 'delete' )->andReturn( true );

		$GLOBALS['wpdb'] = $wpdb;
	}
}
