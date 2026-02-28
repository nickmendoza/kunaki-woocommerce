<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Order_Fulfillment;
use Kunaki_Retry_Queue;
use WP_Error;

class RetryQueueTest extends KunakiTestCase {

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
		unset( $GLOBALS['wpdb'], $_POST['order_id'] );
		parent::tear_down();
	}

	public function test_record_failure_sets_error_meta() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );

		$error_captured = null;
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_error', Mockery::type( 'string' ) )
			->once()
			->andReturnUsing( function ( $key, $value ) use ( &$error_captured ) {
				$error_captured = $value;
			} );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', 1 )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringContainsString( 'No matching Kunaki shipping', $error_captured );
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
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_attempt_counter_increments_on_repeated_failure() {
		// First failure — attempts starts at 0.
		$order1 = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order1->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order1->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', 3 )->once();
		$order1->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order1->shouldReceive( 'save' )->once();
		$order1->shouldReceive( 'add_order_note' )->once();

		$this->fulfillment->handle_order_processing( 100, $order1 );
	}

	public function test_prepare_for_retry_rejects_non_processing_order() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_status', $result->get_error_code() );
	}

	public function test_prepare_for_retry_rejects_already_submitted_order() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'already_submitted', $result->get_error_code() );
	}

	public function test_prepare_for_retry_returns_error_for_invalid_order() {
		Functions\expect( 'wc_get_order' )->with( 999 )->andReturn( false );

		$result = $this->fulfillment->prepare_for_retry( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_order', $result->get_error_code() );
	}

	public function test_prepare_for_retry_succeeds_when_submission_works() {
		// First wc_get_order call — initial load in prepare_for_retry.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		// Second wc_get_order call — re-fetch after handle_order_processing.
		$order_after = Mockery::mock( 'WC_Order' );
		$order_after->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order, $order, $order_after );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertTrue( $result );
	}

	public function test_prepare_for_retry_returns_error_on_failure() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Some error' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		// Re-fetch returns same order (still not submitted).
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'retry_failed', $result->get_error_code() );
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

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock must be released even though save() threw.
		$this->assertNotEmpty( $delete_calls, 'Lock must be released when record_failure save() throws.' );
	}

	public function test_ajax_rejects_missing_capability() {
		$retry_queue = new Kunaki_Retry_Queue();

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );

		$response = null;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( &$response ) {
			$response = $data;
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();

		$this->assertSame( 'Permission denied.', $response['message'] );
	}

	public function test_ajax_rejects_missing_order_id() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = 0;

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$response = null;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( &$response ) {
			$response = $data;
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();

		$this->assertSame( 'Invalid order ID.', $response['message'] );
	}

	public function test_ajax_rejects_non_numeric_order_id() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = 'abc';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		$response = null;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( &$response ) {
			$response = $data;
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();

		$this->assertSame( 'Invalid order ID.', $response['message'] );
	}

	public function test_ajax_returns_error_from_prepare_for_retry() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = '100';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( false );

		$response = null;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( &$response ) {
			$response = $data;
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();

		$this->assertSame( 'Order not found.', $response['message'] );
	}

	public function test_ajax_returns_success_on_successful_retry() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = '100';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '', 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$response = null;
		Functions\expect( 'wp_send_json_success' )->once()->andReturnUsing( function ( $data ) use ( &$response ) {
			$response = $data;
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();

		$this->assertStringContainsString( '#100', $response['message'] );
	}

	public function test_ajax_handler_registers_on_init() {
		$retry_queue = new Kunaki_Retry_Queue();

		Functions\expect( 'add_action' )
			->once()->with( 'admin_menu', Mockery::type( 'array' ) );
		Functions\expect( 'add_action' )
			->once()->with( 'wp_ajax_kunaki_retry_order', Mockery::type( 'array' ) );

		$retry_queue->init();
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
