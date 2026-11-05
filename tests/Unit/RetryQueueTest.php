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
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_last_error', Mockery::type( 'string' ) )
			->once()
			->andReturnUsing( function ( $key, $value ) use ( &$error_captured ) {
				$error_captured = $value;
			} );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
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
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_exhausted' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_last_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_attempt_counter_increments_on_repeated_failure() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		// Simulate a transient API failure (non-permanent).
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );
		Functions\expect( 'update_option' )->zeroOrMoreTimes();

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', 3 )->once();
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		$this->fulfillment->handle_order_processing( 100, $order );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Some error' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
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

	public function test_prepare_for_retry_clears_exhausted_and_attempts() {
		$deleted_meta = array();

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'API down' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing( function ( $key ) use ( &$deleted_meta ) {
			$deleted_meta[] = $key;
		} );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		// Re-fetch returns same order (still not submitted — retry failed).
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$this->fulfillment->prepare_for_retry( 100 );

		$this->assertContains( '_kunaki_fulfillment_exhausted', $deleted_meta );
		$this->assertContains( '_kunaki_fulfillment_attempts', $deleted_meta );
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
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock must be released even though save_meta_data() threw.
		$this->assertNotEmpty( $delete_calls, 'Lock must be released when record_failure save_meta_data() throws.' );
	}

	public function test_ajax_rejects_missing_capability() {
		$retry_queue = new Kunaki_Retry_Queue();

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );

		$test = $this;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( $test ) {
			$test->assertSame( 'Permission denied.', $data['message'] );
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();
	}

	public function test_ajax_rejects_missing_order_id() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = 0;

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$test = $this;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( $test ) {
			$test->assertSame( 'Invalid order ID.', $data['message'] );
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();
	}

	public function test_ajax_rejects_non_numeric_order_id() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = 'abc';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$test = $this;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( $test ) {
			$test->assertSame( 'Invalid order ID.', $data['message'] );
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();
	}

	public function test_ajax_returns_error_from_prepare_for_retry() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = '100';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( false );

		$test = $this;
		Functions\expect( 'wp_send_json_error' )->once()->andReturnUsing( function ( $data ) use ( $test ) {
			$test->assertSame( 'Order not found.', $data['message'] );
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();
	}

	public function test_ajax_returns_success_on_successful_retry() {
		$retry_queue = new Kunaki_Retry_Queue();
		$_POST['order_id'] = '100';

		Functions\expect( 'check_ajax_referer' )->once()->with( 'kunaki_retry_order', 'nonce' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
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

		$test = $this;
		Functions\expect( 'wp_send_json_success' )->once()->andReturnUsing( function ( $data ) use ( $test ) {
			$test->assertStringContainsString( '#100', $data['message'] );
			throw new \RuntimeException( 'wp_die' );
		} );

		$this->expectException( \RuntimeException::class );
		$retry_queue->ajax_retry_order();
	}

	public function test_ajax_handler_registers_on_init() {
		$retry_queue = new Kunaki_Retry_Queue();

		Functions\expect( 'add_action' )
			->once()->with( 'admin_menu', Mockery::type( 'array' ) );
		Functions\expect( 'add_action' )
			->once()->with( 'wp_ajax_kunaki_retry_order', Mockery::type( 'array' ) );

		$retry_queue->init();
	}

	public function test_render_page_empty_shows_no_failed_orders() {
		$retry_queue = new Kunaki_Retry_Queue();

		Functions\expect( 'wc_get_orders' )->times( 3 )->andReturn( array() );

		ob_start();
		$retry_queue->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( '(0)', $html );
		$this->assertStringContainsString( 'No failed orders found', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_render_page_lists_failed_orders() {
		$retry_queue = new Kunaki_Retry_Queue();

		$date_created = Mockery::mock( 'WC_DateTime' );
		$date_created->shouldReceive( 'date_i18n' )->andReturn( '2026-01-15' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/100' );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED )
			->andReturn( '' );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED )
			->andReturn( '' );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR )
			->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )
			->andReturn( '2' );
		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED )
			->andReturn( '2026-01-15T10:00:00+00:00' );
		$order->shouldReceive( 'get_date_created' )->andReturn( $date_created );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'John' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Doe' );

		$order->shouldReceive( 'get_meta' )
			->with( Kunaki_Order_Fulfillment::META_KUNAKI_STATUS )
			->andReturn( '' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		Functions\expect( 'wc_get_orders' )->times( 3 )->andReturn( array( $order ), array(), array() );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		ob_start();
		$retry_queue->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( '(1)', $html );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( '#100', $html );
		$this->assertStringContainsString( 'Connection timed out', $html );
		$this->assertStringContainsString( 'John Doe', $html );
		$this->assertStringContainsString( 'Retry', $html );
		$this->assertStringContainsString( 'Retrying automatically', $html );
	}

	public function test_render_page_lists_exhausted_and_declined_orders() {
		$retry_queue = new Kunaki_Retry_Queue();

		$exhausted = $this->create_listed_order( 100, 'processing', array(
			Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED       => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED  => 'yes',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR      => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR => 'Invalid product ID',
		) );
		$declined  = $this->create_listed_order( 200, 'on-hold', array(
			Kunaki_Order_Fulfillment::META_KUNAKI_STATUS => 'DECLINED',
		) );

		// Retrying query: none. Exhausted query: one. Declined query: one, plus a duplicate.
		Functions\expect( 'wc_get_orders' )->times( 3 )
			->andReturn( array(), array( $exhausted ), array( $declined, $exhausted ) );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		ob_start();
		$retry_queue->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( '(2)', $html );
		$this->assertStringContainsString( 'Retries exhausted', $html );
		$this->assertStringContainsString( 'Invalid product ID', $html );
		$this->assertStringContainsString( 'data-order-id="100"', $html );
		$this->assertStringContainsString( 'Declined by Kunaki', $html );
		// On-hold declined order: guidance instead of a button that would fail.
		$this->assertStringNotContainsString( 'data-order-id="200"', $html );
		$this->assertStringContainsString( 'Change the order status to Processing', $html );
		// Newest first.
		$this->assertLessThan( strpos( $html, '#100' ), strpos( $html, '#200' ) );
	}

	public function test_prepare_for_retry_resubmits_declined_order() {
		$deleted_meta = array();
		$notes        = array();

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( 'DECLINED' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUNOLD' );
		// Submitted flag is cleared by the reset; the re-fetched order reports the new submission.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing( function ( $key ) use ( &$deleted_meta ) {
			$deleted_meta[] = $key;
		} );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( &$notes ) {
			$notes[] = $note;
		} );

		$order_after = Mockery::mock( 'WC_Order' );
		$order_after->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order, $order, $order_after );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );
		$this->mock_wpdb_lock( true );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertTrue( $result );
		foreach ( array( '_kunaki_order_id', '_kunaki_submitted', '_kunaki_order_status', '_kunaki_tracking_id' ) as $key ) {
			$this->assertContains( $key, $deleted_meta );
		}
		$this->assertStringContainsString( 'KUNOLD', implode( ' ', $notes ) );
	}

	public function test_prepare_for_retry_rejects_declined_order_not_in_processing() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldNotReceive( 'delete_meta_data' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_status', $result->get_error_code() );
	}

	// --- Helpers ---

	private function create_listed_order( $id, $status, $meta ) {
		$meta += array(
			Kunaki_Order_Fulfillment::META_KUNAKI_STATUS          => '',
			Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED       => 'yes',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED  => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR      => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS   => '',
			Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED     => '',
		);
		$date_created = Mockery::mock( 'WC_DateTime' );
		$date_created->shouldReceive( 'date_i18n' )->andReturn( '2026-01-15' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( "https://example.com/order/{$id}" );
		$order->shouldReceive( 'get_date_created' )->andReturn( $date_created );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Jane' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Roe' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $meta ) {
			return $meta[ $key ];
		} );
		return $order;
	}

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
