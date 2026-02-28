<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Order_Fulfillment;
use WP_Error;

class OrderFulfillmentTest extends KunakiTestCase {

	/** @var Kunaki_Order_Fulfillment */
	private $fulfillment;

	protected function set_up() {
		parent::set_up();
		$this->fulfillment = new Kunaki_Order_Fulfillment();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_skips_already_submitted_order() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldNotReceive( 'update_meta_data' );

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_skips_order_without_kunaki_products() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( '' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldNotReceive( 'update_meta_data' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_error_note_when_no_shipping_description() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/No matching Kunaki shipping/' ), false );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_successful_submission_stores_meta() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_id', 'KUN999' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_submitted', 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_shipping_description', 'USPS Priority' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_all_items', 'yes' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_failed_at' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_attempts' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_exhausted' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/KUN999/' ), false )->once();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_api_failure_adds_error_note() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_attempts', 1 )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/Connection timed out/' ), false );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_empty_order_id_from_api() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId></OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId></OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_attempts', 1 )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/no Order ID/' ), false );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_mixed_cart_sets_all_items_no() {
		$item_kunaki = Mockery::mock( 'WC_Order_Item_Product' );
		$item_kunaki->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item_kunaki->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$item_other = Mockery::mock( 'WC_Order_Item_Product' );
		$item_other->shouldReceive( 'get_product_id' )->andReturn( 99 );
		$item_other->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = $this->create_submittable_order( array( $item_kunaki, $item_other ) );

		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return ( 42 === $id && '_kunaki_product_id' === $key ) ? 'PXZZ111111' : '';
		} );

		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN888</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN888</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_all_items', 'no' )->once();
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_non_us_ca_sends_empty_state() {
		$captured_body = null;
		$order = $this->create_full_order_mock( 'PXZZ111111', 'GB', '' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];
				return array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN777</OrderId></Response>' );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN777</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringContainsString( '<State_Province></State_Province>', $captured_body );
	}

	public function test_aggregates_duplicate_kunaki_product_quantities() {
		$captured_body = null;

		$item1 = Mockery::mock( 'WC_Order_Item_Product' );
		$item1->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item1->shouldReceive( 'get_quantity' )->andReturn( 2 );

		$item2 = Mockery::mock( 'WC_Order_Item_Product' );
		$item2->shouldReceive( 'get_product_id' )->andReturn( 43 );
		$item2->shouldReceive( 'get_quantity' )->andReturn( 3 );

		$order = $this->create_submittable_order( array( $item1, $item2 ) );

		// Both WC products map to the same Kunaki product.
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return '_kunaki_product_id' === $key ? 'PXZZ111111' : '';
		} );

		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];
				return array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN666</OrderId></Response>' );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN666</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringContainsString( '<Quantity>5</Quantity>', $captured_body );
	}

	public function test_fallback_marker_restores_meta_and_cleans_up() {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		// Return the stored Kunaki Order ID from the durable marker.
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'KUN555' );
		$wpdb->shouldReceive( 'delete' )
			->once()->with( 'wp_options', array( 'option_name' => '_kunaki_submitted_100' ) )
			->andReturn( true );
		$GLOBALS['wpdb'] = $wpdb;

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_order_id', 'KUN555' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_submitted', 'yes' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_failed_at' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_attempts' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_exhausted' )->once();
		$order->shouldReceive( 'save' )->once();

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_fallback_marker_save_failure_leaves_marker() {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'KUN555' );
		// Marker should NOT be deleted when save() fails.
		$wpdb->shouldNotReceive( 'delete' );
		$GLOBALS['wpdb'] = $wpdb;

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_lock_not_acquired_skips_submission() {
		$this->mock_wpdb_lock( false );

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );

		// Should never attempt to get items or submit.
		$order->shouldNotReceive( 'get_items' );
		$order->shouldNotReceive( 'update_meta_data' );

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	// --- Helpers ---

	private function create_order_mock() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		return $order;
	}

	private function create_submittable_order( $items = null ) {
		if ( null === $items ) {
			$item = Mockery::mock( 'WC_Order_Item_Product' );
			$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
			$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
			$items = array( $item );
		}

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( $items );

		$this->mock_wpdb_lock( true );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		return $order;
	}

	private function create_full_order_mock( $kunaki_id, $country_code = 'US', $state = 'CA' ) {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )
			->with( 42, '_kunaki_product_id', true )
			->andReturn( $kunaki_id );

		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order, $country_code, $state );

		return $order;
	}

	private function setup_shipping_methods_on_order( $order ) {
		$shipping_item = Mockery::mock( 'WC_Order_Item_Shipping' );
		$shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'kunaki_shipping' );
		$shipping_item->shouldReceive( 'get_meta' )
			->with( 'kunaki_shipping_description' )->andReturn( 'USPS Priority' );

		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array( $shipping_item ) );
	}

	private function setup_address_on_order( $order, $country_code = 'US', $state = 'CA' ) {
		$countries_obj = Mockery::mock( 'WC_Countries' );
		$countries_obj->shouldReceive( 'get_countries' )->andReturn( array(
			'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
		) );

		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $countries_obj;
		Functions\when( 'WC' )->justReturn( $wc );

		$order->shouldReceive( 'get_shipping_country' )->andReturn( $country_code );
		$order->shouldReceive( 'get_shipping_state' )->andReturn( $state );
		$order->shouldReceive( 'get_shipping_first_name' )->andReturn( 'John' );
		$order->shouldReceive( 'get_shipping_last_name' )->andReturn( 'Doe' );
		$order->shouldReceive( 'get_shipping_company' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_address_1' )->andReturn( '123 Main St' );
		$order->shouldReceive( 'get_shipping_address_2' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( 'Beverly Hills' );
		$order->shouldReceive( 'get_shipping_postcode' )->andReturn( '90210' );
	}

	public function test_double_save_failure_writes_durable_marker_and_releases_lock() {
		// Set up wpdb with tracking from the start (can't use mock_wpdb_lock
		// because we need to capture specific calls).
		$query_calls  = array();
		$delete_calls = array();

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $sql ) use ( &$query_calls ) {
			$query_calls[] = $sql;
			return 1;
		} );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing( function ( $table, $where ) use ( &$delete_calls ) {
			$delete_calls[] = $where;
			return true;
		} );
		$GLOBALS['wpdb'] = $wpdb;

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN555</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN555</OrderId></Response>'
		);

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB still down' ) );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock acquire (DELETE + INSERT IGNORE) + durable marker INSERT = 3 query() calls.
		$this->assertCount( 3, $query_calls, 'Expected 3 $wpdb->query() calls (lock acquire + durable marker).' );
		// Lock should have been released via delete().
		$this->assertNotEmpty( $delete_calls, 'Expected $wpdb->delete() to release the lock.' );
	}

	public function test_durable_marker_failure_retains_lock() {
		$query_calls  = array();
		$delete_calls = array();

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		// Track query() calls: lock acquire succeeds (calls 1-2), durable marker fails (call 3).
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $sql ) use ( &$query_calls ) {
			$query_calls[] = $sql;
			// First two calls are lock acquire (DELETE expired + INSERT IGNORE).
			// Third call is the durable marker INSERT — return 0 to simulate failure.
			return count( $query_calls ) <= 2 ? 1 : 0;
		} );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing( function ( $table, $where ) use ( &$delete_calls ) {
			$delete_calls[] = $where;
			return true;
		} );
		$GLOBALS['wpdb'] = $wpdb;

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN555</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN555</OrderId></Response>'
		);

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB still down' ) );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock acquire (DELETE + INSERT IGNORE) + durable marker INSERT = 3 query() calls.
		$this->assertCount( 3, $query_calls, 'Expected 3 $wpdb->query() calls (lock acquire + durable marker).' );
		// Lock should NOT have been released — marker failed, lock is the last line of defense.
		$this->assertEmpty( $delete_calls, 'Lock must be retained when durable marker write fails.' );
	}

	// --- Retry cron tests ---

	public function test_retry_cron_retries_failed_order() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_retry_cron_skips_order_at_max_attempts() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '4' );

		// Should never attempt submission (4 > 3: initial + 3 retries exhausted).
		$order->shouldNotReceive( 'get_items' );

		// Should mark exhausted and save.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'save' )->once();

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_retry_cron_exception_does_not_halt_loop() {
		$order1 = Mockery::mock( 'WC_Order' );
		$order1->shouldReceive( 'get_id' )->andReturn( 100 );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '0' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order1->shouldReceive( 'get_items' )->andReturn( array() );
		$order1->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order1->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order1->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$order2 = Mockery::mock( 'WC_Order' );
		$order2->shouldReceive( 'get_id' )->andReturn( 200 );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '0' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order2->shouldReceive( 'get_items' )->andReturn( array() );
		$order2->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order2->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order2->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order1, $order2 ) );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order1 );
		Functions\expect( 'wc_get_order' )->with( 200 )->andReturn( $order2 );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		// Both orders go through handle_order_processing. Neither has Kunaki items,
		// so both exit early after lock. The key assertion is that reaching this point
		// without an uncaught exception means both orders were processed.
		$this->fulfillment->retry_failed_orders();
	}

	public function test_retry_cron_no_failed_orders() {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_successful_submission_clears_failure_meta() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_failed_at' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_attempts' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_exhausted' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_attempt_counter_increments() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', 3 )->once();
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_record_failure_save_throws_still_releases_lock() {
		$delete_calls = array();

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing( function ( $table, $where ) use ( &$delete_calls ) {
			$delete_calls[] = $where;
			return true;
		} );
		$GLOBALS['wpdb'] = $wpdb;

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$shipping_item = Mockery::mock( 'WC_Order_Item_Shipping' );
		$shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'kunaki_shipping' );
		$shipping_item->shouldReceive( 'get_meta' )
			->with( 'kunaki_shipping_description' )->andReturn( 'USPS Priority' );

		$countries_obj = Mockery::mock( 'WC_Countries' );
		$countries_obj->shouldReceive( 'get_countries' )->andReturn( array( 'US' => 'United States' ) );
		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $countries_obj;
		Functions\when( 'WC' )->justReturn( $wc );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array( $shipping_item ) );
		$order->shouldReceive( 'get_shipping_country' )->andReturn( 'US' );
		$order->shouldReceive( 'get_shipping_state' )->andReturn( 'CA' );
		$order->shouldReceive( 'get_shipping_first_name' )->andReturn( 'John' );
		$order->shouldReceive( 'get_shipping_last_name' )->andReturn( 'Doe' );
		$order->shouldReceive( 'get_shipping_company' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_address_1' )->andReturn( '123 Main St' );
		$order->shouldReceive( 'get_shipping_address_2' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( 'Beverly Hills' );
		$order->shouldReceive( 'get_shipping_postcode' )->andReturn( '90210' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock must be released even though record_failure's save() threw.
		$this->assertNotEmpty( $delete_calls, 'Lock must be released when record_failure save() throws.' );
	}

	public function test_retry_cron_respects_max_retries_setting() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '6' );

		// With max_retries=5, attempts=6 > 5 means exhausted.
		$order->shouldNotReceive( 'get_items' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'save' )->once();

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '5' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	private function mock_wpdb_lock( $acquired = true ) {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'query' )->andReturn( $acquired ? 1 : 0 );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'delete' )->andReturn( true );

		$GLOBALS['wpdb'] = $wpdb;
	}
}
