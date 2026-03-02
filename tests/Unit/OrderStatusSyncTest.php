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
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_sync_note_pending', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'yes' );
		$order->shouldReceive( 'set_status' )->with( 'completed', Mockery::type( 'string' ) )->once();
		// save() must happen before the customer note with tracking is sent.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/USPS.*9400111/' ), true )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->sync->sync_all_pending_orders();
	}

	public function test_shipped_no_tracking() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'NA', 'NA' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', 'SHIPPED' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_sync_note_pending', Mockery::type( 'string' ) )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_tracking_type', Mockery::any() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'yes' );
		$order->shouldReceive( 'set_status' )->with( 'completed', Mockery::type( 'string' ) )->once();
		// save() must happen before the customer note is sent.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/No tracking/' ), true )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

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
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->sync->sync_all_pending_orders();
	}

	public function test_declined_goes_on_hold() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'DECLINED', '', '' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_status', 'DECLINED' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_sync_note_pending', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'set_status' )->with( 'on-hold', Mockery::any() )->once();
		// save() must happen before notes are added.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/DECLINED/' ), false )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

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
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_sync_note_pending', Mockery::type( 'string' ) )->once();
		$order->shouldNotReceive( 'set_status' );
		// save() must happen before notes are added.
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( "/{$status}/" ), false )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

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

	public function test_sync_lock_not_acquired_skips_order() {
		// Override the default mock — lock acquisition fails.
		$this->mock_wpdb_lock( false );

		$order = $this->create_order_mock( 'KUN123', '' );
		$this->mock_wc_get_orders( array( $order ) );

		// API should never be called.
		Functions\expect( 'wp_remote_post' )->never();
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->sync->sync_all_pending_orders();
	}

	public function test_all_items_fallback_iterates_products() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		// No snapshot stored — triggers the fallback iteration.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( '' );

		$item1 = Mockery::mock( 'WC_Order_Item_Product' );
		$item1->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item2 = Mockery::mock( 'WC_Order_Item_Product' );
		$item2->shouldReceive( 'get_product_id' )->andReturn( 43 );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1, $item2 ) );

		// Both are Kunaki products — should auto-complete.
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return '_kunaki_product_id' === $key ? 'PXZZ111111' : '';
		} );

		$order->shouldReceive( 'set_status' )->with( 'completed', Mockery::any() )->once();
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/USPS.*9400111/' ), true )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->sync->sync_all_pending_orders();
	}

	public function test_all_items_fallback_non_kunaki_stays_processing() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( '' );

		$item1 = Mockery::mock( 'WC_Order_Item_Product' );
		$item1->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item2 = Mockery::mock( 'WC_Order_Item_Product' );
		$item2->shouldReceive( 'get_product_id' )->andReturn( 43 );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1, $item2 ) );

		// Product 43 is NOT a Kunaki product — mixed cart.
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return ( '_kunaki_product_id' === $key && 42 === $id ) ? 'PXZZ111111' : '';
		} );

		$order->shouldNotReceive( 'set_status' );
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::any(), true )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->sync->sync_all_pending_orders();
	}

	public function test_save_throw_does_not_send_notes() {
		$order = $this->create_order_mock( 'KUN123', 'PROCESSING' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_sync_note_pending' )->andReturn( '' );
		$order->shouldReceive( 'set_status' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );

		// Notes must NOT be sent when save() fails.
		$order->shouldNotReceive( 'add_order_note' );

		$this->sync->sync_all_pending_orders();
	}

	public function test_pending_note_recovered_on_next_sync() {
		$pending = wp_json_encode( array(
			array( 'text' => 'Kunaki order shipped! Carrier: USPS, Tracking: 9400111', 'is_customer' => true ),
		) );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID )
			->andReturn( 'KUN123' );
		$order->shouldReceive( 'get_meta' )
			->with( '_kunaki_sync_note_pending' )
			->andReturn( $pending );

		$this->mock_wc_get_orders( array( $order ) );

		// Should send the pending note and clear the flag.
		$order->shouldReceive( 'add_order_note' )
			->with( Mockery::pattern( '/USPS.*9400111/' ), true )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		// API should NOT be called — recovery returns early.
		Functions\expect( 'wp_remote_post' )->never();

		$this->sync->sync_all_pending_orders();
	}

	public function test_sync_exception_does_not_halt_batch() {
		// First order — will throw during API call.
		$order1 = Mockery::mock( 'WC_Order' );
		$order1->shouldReceive( 'get_id' )->andReturn( 100 );
		$order1->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID )
			->andReturn( 'KUN100' );
		$order1->shouldReceive( 'get_meta' )
			->with( '_kunaki_order_status' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )
			->with( '_kunaki_sync_note_pending' )->andReturn( '' );

		// Second order — should still be processed.
		$order2 = Mockery::mock( 'WC_Order' );
		$order2->shouldReceive( 'get_id' )->andReturn( 200 );
		$order2->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID )
			->andReturn( 'KUN200' );
		$order2->shouldReceive( 'get_meta' )
			->with( '_kunaki_order_status' )->andReturn( 'SHIPPED' );
		$order2->shouldReceive( 'get_meta' )
			->with( '_kunaki_sync_note_pending' )->andReturn( '' );

		$this->mock_wc_get_orders( array( $order1, $order2 ) );

		// First API call throws, second returns unchanged status (SHIPPED).
		$call_count = 0;
		Functions\expect( 'wp_remote_post' )->twice()
			->andReturnUsing( function () use ( &$call_count ) {
				$call_count++;
				if ( 1 === $call_count ) {
					throw new \RuntimeException( 'Network failure' );
				}
				$xml = '<Response><ErrorCode>0</ErrorCode><OrderId>KUN200</OrderId>'
					. '<OrderStatus>SHIPPED</OrderStatus><TrackingType>NA</TrackingType>'
					. '<TrackingId>NA</TrackingId></Response>';
				return array( 'body' => $xml );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->zeroOrMoreTimes()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->zeroOrMoreTimes()
			->andReturn( '<Response><ErrorCode>0</ErrorCode><OrderId>KUN200</OrderId>'
				. '<OrderStatus>SHIPPED</OrderStatus><TrackingType>NA</TrackingType>'
				. '<TrackingId>NA</TrackingId></Response>' );

		// Second order skips (unchanged status), but the point is it was reached.
		$order2->shouldNotReceive( 'save' );

		$this->sync->sync_all_pending_orders();
	}

	public function test_send_pending_notes_save_meta_throws_is_swallowed() {
		$pending = wp_json_encode( array(
			array( 'text' => 'Kunaki order shipped! Carrier: USPS, Tracking: 9400111', 'is_customer' => true ),
		) );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID )
			->andReturn( 'KUN123' );
		$order->shouldReceive( 'get_meta' )
			->with( '_kunaki_sync_note_pending' )
			->andReturn( $pending );

		$this->mock_wc_get_orders( array( $order ) );

		// Notes should be sent even though save_meta_data will fail.
		$order->shouldReceive( 'add_order_note' )
			->with( Mockery::pattern( '/USPS.*9400111/' ), true )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once()
			->andThrow( new \RuntimeException( 'DB down' ) );

		// API should NOT be called — recovery returns early.
		Functions\expect( 'wp_remote_post' )->never();

		// Should complete without exception (documented tradeoff: possible duplicate on next run).
		$this->sync->sync_all_pending_orders();
		$this->addToAssertionCount( 1 );
	}

	public function test_all_items_are_kunaki_empty_items_returns_true() {
		$order = $this->create_order_mock( 'KUN123', '' );

		$this->mock_api_status( 'KUN123', 'SHIPPED', 'USPS', '9400111' );
		$this->mock_wc_get_orders( array( $order ) );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		// No snapshot stored — triggers the fallback iteration.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_all_items' )->andReturn( '' );
		// Empty items list — loop never executes, returns true.
		$order->shouldReceive( 'get_items' )->andReturn( array() );

		// Should auto-complete (all_items_are_kunaki returns true for empty items).
		$order->shouldReceive( 'set_status' )->with( 'completed', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'save' )->once()->ordered();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::any(), true )->once()->ordered();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_sync_note_pending' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->sync->sync_all_pending_orders();
	}

	public function test_sync_respects_custom_limit() {
		Functions\expect( 'wc_get_orders' )->once()
			->with( Mockery::on( function ( $args ) {
				return 10 === $args['limit'];
			} ) )
			->andReturn( array() );

		$this->sync->sync_all_pending_orders( 10 );
		$this->addToAssertionCount( 1 );
	}

	public function test_sync_default_limit_is_50() {
		Functions\expect( 'wc_get_orders' )->once()
			->with( Mockery::on( function ( $args ) {
				return 50 === $args['limit'];
			} ) )
			->andReturn( array() );

		$this->sync->sync_all_pending_orders();
		$this->addToAssertionCount( 1 );
	}

	public function test_cron_fires_with_zero_accepted_args() {
		Functions\expect( 'add_action' )->once()
			->with( 'kunaki_wc_sync_order_status', Mockery::any(), 10, 0 );

		$this->sync->init();
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
		$order->shouldReceive( 'get_meta' )
			->with( '_kunaki_sync_note_pending' )
			->andReturn( '' );

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
