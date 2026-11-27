<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Order_Fulfillment;
use Kunaki_Retry_Queue;
use WP_Error;

class RetryQueueTest extends KunakiTestCase {

	/** Kunaki's answer to a rate lookup, with the dearer option listed first. */
	const TWO_SHIPPING_OPTIONS = '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText>'
		. '<Option><Description>UPS Ground</Description><DeliveryTime>1-5 business days</DeliveryTime><Price>9.80</Price></Option>'
		. '<Option><Description>USPS First Class Mail</Description><DeliveryTime>2-5 business days</DeliveryTime><Price>6.29</Price></Option>'
		. '</Response>';

	/** What the scan, or a failed submission, leaves on an order with no Kunaki shipping choice. */
	const HELD_WITHOUT_SHIPPING = array(
		'_kunaki_fulfillment_exhausted'  => 'yes',
		'_kunaki_fulfillment_last_error' => 'This order has no Kunaki shipping option. Retry it to send it with the cheapest Kunaki shipping option for its address.',
		'_kunaki_fulfillment_failed_at'  => '2026-01-15T10:00:00+00:00',
		'_kunaki_needs_shipping'         => 'yes',
	);

	/** @var Kunaki_Order_Fulfillment */
	private $fulfillment;

	/** @var array<string, mixed> Meta of the order built by create_retry_order(), including what a Retry writes. */
	public $meta = array();

	/** @var string[] Bodies of the requests sent to Kunaki. */
	public $requests = array();

	/** @var string|WP_Error What Kunaki answers to a shipping-options request. */
	public $shipping_response = '';

	/** @var string[] Order notes added. */
	public $notes = array();

	/** @var int How many times the order was saved. */
	public $saves = 0;

	/** @var string[] Options deleted. */
	public $deleted_options = array();

	protected function set_up() {
		parent::set_up();
		$this->fulfillment = new Kunaki_Order_Fulfillment();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
		// render_page() clears the catch-up notice.
		$this->deleted_options = array();
		$test                  = $this;
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( $test ) {
			$test->deleted_options[] = $key;
			return true;
		} );

		$this->meta              = array();
		$this->requests          = array();
		$this->notes             = array();
		$this->saves             = 0;
		$this->shipping_response = self::TWO_SHIPPING_OPTIONS;
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
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
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', 'yes' )->once();
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
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
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( 'yes' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
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

	public function test_render_page_clears_the_catch_up_notice() {
		$retry_queue = new Kunaki_Retry_Queue();
		Functions\expect( 'wc_get_orders' )->times( 3 )->andReturn( array() );

		ob_start();
		$retry_queue->render_page();
		ob_end_clean();

		// The notice links here, so opening this page answers it.
		$this->assertContains( 'kunaki_wc_catch_up_notice', $this->deleted_options );
	}

	// --- Retry on an order with no Kunaki shipping choice ---

	public function test_retry_sends_a_flagged_order_with_the_cheapest_kunaki_shipping() {
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertTrue( $result );
		$this->assertCount( 2, $this->requests );

		// First Kunaki is asked what it offers for this address and these discs.
		$this->assertStringStartsWith( '<ShippingOptions>', $this->requests[0] );
		$this->assertStringContainsString( '<Country>United States</Country>', $this->requests[0] );
		$this->assertStringContainsString( '<State_Province>CA</State_Province>', $this->requests[0] );
		$this->assertStringContainsString( '<PostalCode>90210</PostalCode>', $this->requests[0] );
		$this->assertStringContainsString( '<ProductId>PXZZ111111</ProductId><Quantity>1</Quantity>', $this->requests[0] );

		// Then the order goes out with the cheaper option, although it was listed second.
		$this->assertStringStartsWith( '<Order>', $this->requests[1] );
		$this->assertStringContainsString( '<ShippingDescription>USPS First Class Mail</ShippingDescription>', $this->requests[1] );
		$this->assertStringNotContainsString( 'UPS Ground', $this->requests[1] );

		$this->assertSame( 'USPS First Class Mail', $this->meta['_kunaki_chosen_shipping'] );
		$this->assertSame( 'yes', $this->meta['_kunaki_submitted'] );

		// The note says what was used, how long it takes and what Kunaki charges.
		$notes = implode( "\n", $this->notes );
		$this->assertStringContainsString( 'Sending it with the cheapest Kunaki option for its address: USPS First Class Mail (2-5 business days), Kunaki price 6.29.', $notes );
	}

	public function test_retry_keeps_the_first_of_two_equally_cheap_options() {
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING );
		$this->shipping_response = '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText>'
			. '<Option><Description>Listed First</Description><DeliveryTime>3 days</DeliveryTime><Price>6.29</Price></Option>'
			. '<Option><Description>Listed Second</Description><DeliveryTime>2 days</DeliveryTime><Price>6.29</Price></Option>'
			. '</Response>';

		$this->assertTrue( $this->fulfillment->prepare_for_retry( 100 ) );

		$this->assertSame( 'Listed First', $this->meta['_kunaki_chosen_shipping'] );
	}

	public function test_retry_looks_the_options_up_again_when_a_choice_is_already_stored() {
		// An earlier Retry chose shipping, then the submission failed. Prices or
		// the address may have changed since, so this click asks again.
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING + array( '_kunaki_chosen_shipping' => 'UPS Ground' ) );

		$this->assertTrue( $this->fulfillment->prepare_for_retry( 100 ) );

		$this->assertStringStartsWith( '<ShippingOptions>', $this->requests[0] );
		$this->assertSame( 'USPS First Class Mail', $this->meta['_kunaki_chosen_shipping'] );
		$this->assertStringContainsString( '<ShippingDescription>USPS First Class Mail</ShippingDescription>', $this->requests[1] );
	}

	/**
	 * @dataProvider failed_lookups
	 */
	public function test_retry_leaves_the_order_untouched_when_no_shipping_can_be_chosen( $shipping_response, $country, $expected_code, $expected_requests ) {
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING, false, $country );
		$this->shipping_response = $shipping_response;
		$before                  = $this->meta;

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertNotSame( '', $result->get_error_message() );

		// The order keeps its failure record, so it stays on the Failures page.
		// Had the retry budget been reset first, it would now have no record at all.
		$this->assertSame( $before, $this->meta );
		$this->assertSame( 0, $this->saves );
		$this->assertSame( array(), $this->notes );

		// Nothing was sent.
		$this->assertCount( $expected_requests, $this->requests );
		foreach ( $this->requests as $request ) {
			$this->assertStringStartsNotWith( '<Order>', $request );
		}
	}

	public function failed_lookups() {
		return array(
			'Kunaki unreachable'  => array( new WP_Error( 'http_request_failed', 'Could not resolve host: kunaki.com' ), 'US', 'http_request_failed', 1 ),
			'no options offered'  => array( '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText></Response>', 'US', 'kunaki_no_shipping_options', 1 ),
			'only nameless options' => array( '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText><Option><Description></Description><DeliveryTime>2 days</DeliveryTime><Price>1.00</Price></Option></Response>', 'US', 'kunaki_no_shipping_options', 1 ),
			// Kunaki would answer with no options, so it isn't asked.
			'unsupported country' => array( self::TWO_SHIPPING_OPTIONS, 'BR', 'kunaki_no_shipping_options', 0 ),
		);
	}

	public function test_failed_lookup_on_a_declined_order_leaves_the_old_kunaki_order_in_place() {
		// Sent once with a chosen option, then declined by Kunaki.
		$this->create_retry_order( array(
			'_kunaki_order_status'    => 'DECLINED',
			'_kunaki_order_id'        => 'KUNOLD',
			'_kunaki_submitted'       => 'yes',
			'_kunaki_needs_shipping'  => 'yes',
			'_kunaki_chosen_shipping' => 'USPS First Class Mail',
		) );
		$this->shipping_response = new WP_Error( 'http_request_failed', 'Could not resolve host: kunaki.com' );
		$before                  = $this->meta;

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, $this->meta, 'Nothing about the declined order may change, not even in memory.' );
		$this->assertSame( 0, $this->saves );
	}

	public function test_first_retry_on_an_order_that_failed_before_this_version_only_explains() {
		// What 1.4.0 left on an order with no Kunaki shipping line. Its message
		// never said Retry would choose shipping, so this click must not.
		$this->create_retry_order( array(
			'_kunaki_fulfillment_exhausted'  => 'yes',
			'_kunaki_fulfillment_last_error' => 'No matching Kunaki shipping method found on this order.',
			'_kunaki_fulfillment_failed_at'  => '2026-01-15T10:00:00+00:00',
		) );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'retry_failed', $result->get_error_code() );
		// The admin is told what the next click will do. (This used to be blank:
		// a permanent failure keeps its message under LAST_ERROR.)
		$this->assertStringContainsString( 'cheapest', $result->get_error_message() );

		// Nothing was asked of Kunaki and nothing was sent.
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( '_kunaki_chosen_shipping', $this->meta );

		// The order is held again, now with the new message and the flag.
		$this->assertSame( 'yes', $this->meta['_kunaki_fulfillment_exhausted'] );
		$this->assertStringContainsString( 'cheapest', $this->meta['_kunaki_fulfillment_last_error'] );
		$this->assertSame( 'yes', $this->meta['_kunaki_needs_shipping'] );

		// So the second click sends it.
		$this->assertTrue( $this->fulfillment->prepare_for_retry( 100 ) );
		$this->assertStringContainsString( '<ShippingDescription>USPS First Class Mail</ShippingDescription>', end( $this->requests ) );
	}

	/**
	 * @dataProvider flag_states
	 */
	public function test_retry_never_looks_up_shipping_for_an_order_that_has_a_kunaki_shipping_line( $flag ) {
		$meta = array(
			'_kunaki_fulfillment_exhausted'  => 'yes',
			'_kunaki_fulfillment_last_error' => 'Connection timed out',
		);
		if ( '' !== $flag ) {
			$meta['_kunaki_needs_shipping'] = $flag;
		}
		$this->create_retry_order( $meta, true );

		$this->assertTrue( $this->fulfillment->prepare_for_retry( 100 ) );

		// One request: the order itself, with what the customer chose at checkout.
		$this->assertCount( 1, $this->requests );
		$this->assertStringStartsWith( '<Order>', $this->requests[0] );
		$this->assertStringContainsString( '<ShippingDescription>USPS Priority</ShippingDescription>', $this->requests[0] );
		$this->assertArrayNotHasKey( '_kunaki_chosen_shipping', $this->meta );
	}

	public function flag_states() {
		return array(
			'not flagged' => array( '' ),
			'flagged'     => array( 'yes' ),
		);
	}

	public function test_retry_lookup_sends_no_state_outside_the_us_and_canada() {
		// Kunaki only accepts a state or province for US and CA addresses.
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING, false, 'GB' );

		$this->assertTrue( $this->fulfillment->prepare_for_retry( 100 ) );

		$this->assertStringContainsString( '<Country>United Kingdom</Country>', $this->requests[0] );
		$this->assertStringContainsString( '<State_Province></State_Province>', $this->requests[0] );
	}

	public function test_retry_makes_no_lookup_for_an_order_with_no_discs() {
		$this->create_retry_order( self::HELD_WITHOUT_SHIPPING );
		// The product is no longer linked to a Kunaki disc.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		// There is nothing to send, so nothing to ask Kunaki about.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( '_kunaki_chosen_shipping', $this->meta );
	}

	public function test_retry_on_an_already_submitted_order_makes_no_lookup() {
		$this->create_retry_order( array(
			'_kunaki_submitted'      => 'yes',
			'_kunaki_needs_shipping' => 'yes',
		) );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'already_submitted', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	// --- Helpers ---

	/**
	 * A Processing disc order that keeps its meta in $this->meta, with Kunaki's
	 * HTTP endpoint faked, so a test can see what a Retry wrote, saved and sent.
	 *
	 * @param array  $meta         The order's meta before the Retry.
	 * @param bool   $kunaki_line  Whether the order has a Kunaki shipping line from checkout.
	 * @param string $country_code Shipping country.
	 */
	private function create_retry_order( array $meta, $kunaki_line = false, $country_code = 'US' ) {
		$test       = $this;
		$this->meta = $meta;

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $test ) {
			return isset( $test->meta[ $key ] ) ? $test->meta[ $key ] : '';
		} );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $test ) {
			$test->meta[ $key ] = $value;
		} );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing( function ( $key ) use ( $test ) {
			unset( $test->meta[ $key ] );
		} );
		$order->shouldReceive( 'save' )->andReturnUsing( function () use ( $test ) {
			$test->saves++;
		} );
		$order->shouldReceive( 'save_meta_data' )->andReturnUsing( function () use ( $test ) {
			$test->saves++;
		} );
		$order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( $test ) {
			$test->notes[] = $note;
		} );

		if ( $kunaki_line ) {
			$this->setup_shipping_methods_on_order( $order );
		} else {
			// What the customer picked while the plugin was inactive.
			$other = Mockery::mock( 'WC_Order_Item_Shipping' );
			$other->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );
			$order->shouldReceive( 'get_shipping_methods' )->andReturn( array( $other ) );
		}
		$this->setup_address_on_order( $order, $country_code );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\when( 'get_option' )->justReturn( 'no' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->mock_wpdb_lock( true );

		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( $test ) {
			$body             = (string) $args['body'];
			$test->requests[] = $body;
			if ( 0 === strpos( $body, '<ShippingOptions' ) ) {
				return $test->shipping_response instanceof WP_Error
					? $test->shipping_response
					: array( 'body' => $test->shipping_response );
			}
			return array( 'body' => '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText><OrderId>KUN777</OrderId></Response>' );
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
			return $response['body'];
		} );

		return $order;
	}

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
			$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
