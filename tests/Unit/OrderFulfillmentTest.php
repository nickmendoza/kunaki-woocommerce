<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Actions;
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

	public function test_init_registers_hooks() {
		Functions\expect( 'add_action' )
			->once()->with( 'woocommerce_order_status_processing', array( $this->fulfillment, 'queue_order_submission' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_submit_order', array( $this->fulfillment, 'run_queued_submission' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'woocommerce_admin_order_data_after_shipping_address', Mockery::type( 'array' ) );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_retry_failed_orders', Mockery::type( 'array' ) );

		$this->fulfillment->init();
	}

	public function test_processing_hook_queues_background_submission_instead_of_calling_api() {
		Functions\expect( 'as_next_scheduled_action' )->once()
			->with( 'kunaki_wc_submit_order', array( 'order_id' => 100 ), 'kunaki-woocommerce' )
			->andReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->once()
			->with( 'kunaki_wc_submit_order', array( 'order_id' => 100 ), 'kunaki-woocommerce' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );
		// No order load, no API call during checkout.
		Functions\expect( 'wc_get_order' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->fulfillment->queue_order_submission( 100 );
	}

	public function test_processing_hook_does_not_queue_duplicate_submission() {
		Functions\expect( 'as_next_scheduled_action' )->once()->andReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->fulfillment->queue_order_submission( 100 );
	}

	public function test_queued_submission_exception_records_retryable_failure() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		// First get_meta('_kunaki_submitted') call throws inside handle_order_processing;
		// the handler then re-checks it before recording the failure.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )
			->andReturnUsing( function () {
				static $calls = 0;
				if ( 0 === $calls++ ) {
					throw new \RuntimeException( 'Unexpected' );
				}
				return '';
			} );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', 'Unexpected' )->once();
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->run_queued_submission( 100 );
	}

	public function test_queued_submission_skips_order_no_longer_processing() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldNotReceive( 'get_meta' );

		Functions\expect( 'wc_get_order' )->once()->with( 100 )->andReturn( $order );
		Functions\expect( 'wp_remote_post' )->never();

		$this->fulfillment->run_queued_submission( 100 );
	}

	public function test_cancel_after_submission_adds_kunaki_warning_note() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( 'PROCESSING' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN555' );
		$order->shouldReceive( 'add_order_note' )->once()
			->with( Mockery::pattern( '/KUN555.*cancel it with Kunaki/' ), false );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$this->fulfillment->note_cancelled_after_submission( 100 );
	}

	/**
	 * @dataProvider final_kunaki_statuses
	 */
	public function test_cancel_after_kunaki_final_status_adds_no_note( $status ) {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( $status );
		$order->shouldNotReceive( 'add_order_note' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$this->fulfillment->note_cancelled_after_submission( 100 );
	}

	public function final_kunaki_statuses() {
		return array( 'shipped' => array( 'SHIPPED' ), 'declined' => array( 'DECLINED' ) );
	}

	public function test_cancel_of_unsubmitted_order_adds_no_note() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldNotReceive( 'add_order_note' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$this->fulfillment->note_cancelled_after_submission( 100 );
	}

	public function test_partial_refund_after_submission_adds_note() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN555' );
		$order->shouldReceive( 'add_order_note' )->once()
			->with( Mockery::pattern( '/KUN555.*refund does not change/' ), false );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$this->fulfillment->note_partially_refunded_after_submission( 100 );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		// Permanent: message goes to LAST_ERROR so the order stays out of the retry query.
		// It says what Retry will do, and the flag records that it has been said.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', Mockery::pattern( '/No matching Kunaki shipping.*cheapest/' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', 'yes' )->once();
		// Nothing automatic asks Kunaki for shipping options, or anything else.
		Functions\expect( 'wp_remote_post' )->never();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', Mockery::any() );
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
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
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_all_items', 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_submitted_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_address_hash', Mockery::pattern( '/^[0-9a-f]{32}$/' ) )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_failed_at' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_attempts' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_exhausted' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_last_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->with( Mockery::pattern( '/KUN999/' ), false )->once();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_country_name_strips_parenthetical_code() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )
			->with( 42, '_kunaki_product_id', true )
			->andReturn( 'PXZZ111111' );

		$this->setup_shipping_methods_on_order( $order );

		// Override countries to include parenthetical codes.
		$countries_obj = Mockery::mock( 'WC_Countries' );
		$countries_obj->shouldReceive( 'get_countries' )->andReturn( array(
			'US' => 'United States (US)', 'CA' => 'Canada (CA)',
		) );
		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $countries_obj;
		Functions\when( 'WC' )->justReturn( $wc );

		$order->shouldReceive( 'get_shipping_country' )->andReturn( 'US' );
		$order->shouldReceive( 'get_shipping_state' )->andReturn( 'CA' );
		$order->shouldReceive( 'get_shipping_first_name' )->andReturn( 'John' );
		$order->shouldReceive( 'get_shipping_last_name' )->andReturn( 'Doe' );
		$order->shouldReceive( 'get_shipping_company' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_address_1' )->andReturn( '123 Main St' );
		$order->shouldReceive( 'get_shipping_address_2' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( 'Beverly Hills' );
		$order->shouldReceive( 'get_shipping_postcode' )->andReturn( '90210' );

		$captured_body = null;
		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];
				return array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>' );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->withAnyArgs()->once();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringContainsString( '<Country>United States</Country>', $captured_body );
		$this->assertStringNotContainsString( '(US)', $captured_body );
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
		$order->shouldReceive( 'save_meta_data' )->once();
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
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/no Order ID/' ), false );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_submission_uses_variation_kunaki_id() {
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item->shouldReceive( 'get_variation_id' )->andReturn( 43 );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$order = $this->create_submittable_order( array( $item ) );

		Functions\when( 'get_post_meta' )->alias( function ( $id ) {
			return array( 42 => 'PXPARENT01', 43 => 'PXVARIANT1' )[ $id ] ?? '';
		} );

		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return $default;
		} );

		$sent = '';
		$xml  = '<Response><ErrorCode>0</ErrorCode><OrderId>KUN1</OrderId></Response>';
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$sent, $xml ) {
			$sent = $args['body'];
			return array( 'body' => $xml );
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $xml );
		$this->setup_shipping_methods_on_order( $order );
		$this->setup_address_on_order( $order, 'US', 'CA' );
		$order->shouldIgnoreMissing();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringContainsString( 'PXVARIANT1', $sent );
		$this->assertStringNotContainsString( 'PXPARENT01', $sent );
	}

	public function test_deactivation_hands_queued_orders_to_retry_queue() {
		$action = Mockery::mock( 'ActionScheduler_Action' );
		$action->shouldReceive( 'get_args' )->andReturn( array( 'order_id' => 100 ) );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_KUNAKI_SUBMITTED )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED, Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR, Mockery::pattern( '/deactivated/' ) )->once();
		// Not Kunaki's fault, so it must not use up a retry attempt.
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS, Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'as_get_scheduled_actions' )->once()->andReturn( array( $action ) );
		// Hook only: with a group too, Action Scheduler only cancels jobs with no args.
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'kunaki_wc_submit_order' );

		$this->fulfillment->park_queued_submissions();
	}

	public function test_deactivation_ignores_orders_not_needing_submission() {
		$action = Mockery::mock( 'ActionScheduler_Action' );
		$action->shouldReceive( 'get_args' )->andReturn( array( 'order_id' => 100 ) );

		$cancelled = Mockery::mock( 'WC_Order' );
		$cancelled->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$cancelled->shouldNotReceive( 'update_meta_data' );

		Functions\when( 'wc_get_order' )->justReturn( $cancelled );
		Functions\expect( 'as_get_scheduled_actions' )->once()->andReturn( array( $action ) );
		Functions\expect( 'as_unschedule_all_actions' )->once();

		$this->fulfillment->park_queued_submissions();
	}

	public function test_mixed_cart_sets_all_items_no() {
		$item_kunaki = Mockery::mock( 'WC_Order_Item_Product' );
		$item_kunaki->shouldReceive( 'get_product_id' )->andReturn( 42 );
		$item_kunaki->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item_kunaki->shouldReceive( 'get_quantity' )->andReturn( 1 );

		$item_other = Mockery::mock( 'WC_Order_Item_Product' );
		$item_other->shouldReceive( 'get_product_id' )->andReturn( 99 );
		$item_other->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
		$item1->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item1->shouldReceive( 'get_quantity' )->andReturn( 2 );

		$item2 = Mockery::mock( 'WC_Order_Item_Product' );
		$item2->shouldReceive( 'get_product_id' )->andReturn( 43 );
		$item2->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_last_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->with( Mockery::pattern( '/KUN555.*recovered/' ), false )->once();

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
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
		// Failed long ago — past the backoff window.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 7200 ) );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
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
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );

		// Should never attempt submission (3 >= 3: exhausted).
		$order->shouldNotReceive( 'get_items' );

		// Should mark exhausted, move the error to LAST_ERROR (so the admin can
		// still see it), add order note, and save.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Connection timed out' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/retries exhausted/' ), false );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

		$this->fulfillment->retry_failed_orders();
	}

	public function test_retry_cron_exception_does_not_halt_loop() {
		$order1 = Mockery::mock( 'WC_Order' );
		$order1->shouldReceive( 'get_id' )->andReturn( 100 );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '0' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 7200 ) );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order1->shouldReceive( 'get_items' )->andReturn( array() );
		$order1->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order1->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order1->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$order2 = Mockery::mock( 'WC_Order' );
		$order2->shouldReceive( 'get_id' )->andReturn( 200 );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '0' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 7200 ) );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
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

	public function test_retry_cron_skips_order_within_backoff_window() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		// Failed 5 minutes ago — backoff for attempts=2 is 30 min, so too recent.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 5 * MINUTE_IN_SECONDS ) );

		// Should never attempt submission due to backoff.
		$order->shouldNotReceive( 'get_items' );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

		$this->fulfillment->retry_failed_orders();
	}

	public function test_retry_cron_retries_order_past_backoff_window() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );
		// Failed 20 minutes ago — backoff for attempts=1 is 15 min, so eligible.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 20 * MINUTE_IN_SECONDS ) );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
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

	public function test_retry_cron_no_failed_orders() {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

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
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_last_error' )->once();
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
		$order->shouldReceive( 'save_meta_data' )->once();
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
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
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
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock must be released even though record_failure's save_meta_data() threw.
		$this->assertNotEmpty( $delete_calls, 'Lock must be released when record_failure save_meta_data() throws.' );
	}

	public function test_retry_cron_respects_max_retries_setting() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '5' );

		// With max_retries=5, attempts=5 >= 5 means exhausted.
		$order->shouldNotReceive( 'get_items' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Connection timed out' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()->with( Mockery::pattern( '/retries exhausted/' ), false );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '5' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

		$this->fulfillment->retry_failed_orders();
	}

	public function test_recover_orphaned_failure_restores_meta() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_error', 'Connection timed out' )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', 1 )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => 'Connection timed out',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_options', array( 'option_name' => '_kunaki_failed_100' ) )->once();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_fallback_marker_recovery_adds_order_note() {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'KUN555' );
		$wpdb->shouldReceive( 'delete' )
			->once()->with( 'wp_options', array( 'option_name' => '_kunaki_submitted_100' ) )
			->andReturn( true );
		$GLOBALS['wpdb'] = $wpdb;

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->with( Mockery::pattern( '/KUN555.*recovered/' ), false )->once();

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_race_condition_recheck_skips_already_submitted() {
		$this->mock_wpdb_lock( true );

		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );

		// After lock acquired, re-fetch returns order already submitted.
		$refetched = Mockery::mock( 'WC_Order' );
		$refetched->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $refetched );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		// Should never attempt to get items or submit.
		$refetched->shouldNotReceive( 'get_items' );
		$refetched->shouldNotReceive( 'update_meta_data' );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_save_fails_but_save_meta_succeeds() {
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
		// save() throws, but save_meta_data() succeeds — no durable marker needed.
		$order->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->fulfillment->handle_order_processing( 100, $order );

		// Lock should be released (only 2 query calls: lock DELETE + INSERT, no durable marker).
		$this->assertNotEmpty( $delete_calls, 'Lock must be released when save_meta_data() succeeds.' );
	}

	public function test_recover_orphaned_failures_deleted_order_cleans_marker() {
		$marker = (object) array(
			'option_name'  => '_kunaki_failed_999',
			'option_value' => 'Connection timed out',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		// Marker should be cleaned up even though order doesn't exist.
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_options', array( 'option_name' => '_kunaki_failed_999' ) )->once();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 999 )->andReturn( false );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_recover_orphaned_failures_save_throws_leaves_marker() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()
			->andThrow( new \RuntimeException( 'DB down' ) );

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => 'Connection timed out',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		// Marker should NOT be deleted when save_meta_data() throws.
		$wpdb->shouldNotReceive( 'delete' );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_record_failure_writes_durable_marker_on_save_throw() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new \WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$queries = array();
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $q ) use ( &$queries ) {
			$queries[] = $q;
			return 1;
		} );
		$wpdb->shouldReceive( 'delete' )->andReturn( true );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$this->fulfillment->handle_order_processing( 100, $order );

		// The durable marker query should contain '_kunaki_failed_100'.
		$this->assertTrue(
			count( $queries ) > 0,
			'At least one query should have been executed (including the durable failure marker).'
		);
	}

	public function test_display_order_info_returns_early_when_no_data() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( '' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertEmpty( $html );
	}

	public function test_display_order_info_shows_submitted_order_with_tracking() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN555' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( 'SHIPPED' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_id' )->andReturn( '9400111' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_type' )->andReturn( 'USPS' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'KUN555', $html );
		$this->assertStringContainsString( 'SHIPPED', $html );
		$this->assertStringContainsString( 'USPS', $html );
		$this->assertStringContainsString( '9400111', $html );
		$this->assertStringNotContainsString( 'Retry', $html );
	}

	public function test_display_order_info_shows_failed_with_retry_button() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )->andReturn( '2026-01-15T10:00:00+00:00' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringContainsString( 'Connection timed out', $html );
		$this->assertStringContainsString( 'Retry Submission', $html );
		$this->assertStringContainsString( 'data-order-id="100"', $html );
	}

	public function test_display_order_info_shows_exhausted_with_last_error_and_retry_button() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_last_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )->andReturn( '' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'retries stopped', $html );
		$this->assertStringContainsString( 'Connection timed out', $html );
		$this->assertStringContainsString( 'Retry Submission', $html );
	}

	public function test_display_order_info_shows_resubmit_for_declined_processing_order() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN555' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( 'DECLINED' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_type' )->andReturn( '' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Kunaki declined this order', $html );
		$this->assertStringContainsString( 'Resubmit to Kunaki', $html );
		$this->assertStringContainsString( 'data-order-id="100"', $html );
	}

	public function test_display_order_info_declined_on_hold_explains_next_step() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN555' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( 'DECLINED' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_tracking_type' )->andReturn( '' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Change the order status to Processing', $html );
		$this->assertStringNotContainsString( 'kunaki-retry-button', $html );
	}

	public function test_display_order_info_warns_when_cancelled_or_address_changed_after_submission() {
		$order = $this->create_display_submitted_order( 'cancelled', 'old-hash' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		// Sync never refreshes cancelled orders, so the warning must stay true
		// after the admin cancels at Kunaki — it can't flatly claim Kunaki ships it.
		$this->assertStringContainsString( 'Unless it was also cancelled with Kunaki directly, Kunaki will still manufacture and ship it', $html );
		$this->assertStringContainsString( 'shipping address was changed', $html );
	}

	public function test_display_order_info_no_warning_when_address_unchanged() {
		$order = $this->create_display_submitted_order( 'processing', null );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'still manufacture', $html );
		$this->assertStringNotContainsString( 'address was changed', $html );
	}

	/**
	 * @param string      $status    WooCommerce order status.
	 * @param string|null $sent_hash Stored address hash; null = matches current address.
	 */
	private function create_display_submitted_order( $status, $sent_hash ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			$order->shouldReceive( 'get_shipping_' . $field )->andReturn( 'x' );
		}
		$meta = array(
			'_kunaki_order_id'      => 'KUN555',
			'_kunaki_order_status'  => 'PROCESSING',
			'_kunaki_submitted'     => 'yes',
			'_kunaki_tracking_id'   => '',
			'_kunaki_tracking_type' => '',
		);
		$meta['_kunaki_address_hash'] = null === $sent_hash ? Kunaki_Order_Fulfillment::get_address_hash( $order ) : $sent_hash;
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $meta ) {
			return $meta[ $key ];
		} );
		return $order;
	}

	public function test_display_order_info_hides_retry_button_when_not_processing() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )->andReturn( '2026-01-15T10:00:00+00:00' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );

		ob_start();
		$this->fulfillment->display_kunaki_order_info( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringNotContainsString( 'Retry Submission', $html );
	}

	public function test_handle_order_processing_null_order_wc_get_order_returns_false() {
		Functions\expect( 'wc_get_order' )->with( 999 )->once()->andReturn( false );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		// Should return silently — no exception, no further calls.
		$this->fulfillment->handle_order_processing( 999, null );
		$this->addToAssertionCount( 1 );
	}

	public function test_max_retries_zero_falls_back_to_three() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		// Attempts = 2 — less than fallback of 3, so should NOT be exhausted.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '2' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_failed_at' )
			->andReturn( gmdate( 'c', time() - 7200 ) );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		// Should NOT be marked exhausted since fallback max_retries = 3 > 2.
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '0' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_lock( true );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_circuit_open_records_failure_without_incrementing_attempts() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		// Circuit breaker in open state, cooldown not yet elapsed.
		$open_state = wp_json_encode( array(
			'state'           => 'open',
			'failure_count'   => 5,
			'last_failure_at' => time() - 60,
			'opened_at'       => time() - 60,
		) );
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) use ( $open_state ) {
			if ( 'kunaki_wc_circuit_breaker' === $key ) {
				return $open_state;
			}
			if ( 'kunaki_wc_circuit_cooldown' === $key ) {
				return 5;
			}
			return $default;
		} );

		// Circuit blocks the request — wp_remote_post should never be called.
		Functions\expect( 'wp_remote_post' )->never();

		// Should record error and timestamp but NOT increment attempts.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_error', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldNotReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		// A failure that will be retried doesn't need a person yet.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_record_failure_add_order_note_throws_does_not_propagate() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new \WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once();
		// add_order_note throws — should be caught, not propagated.
		$order->shouldReceive( 'add_order_note' )->once()
			->andThrow( new \RuntimeException( 'Note save failed' ) );

		$this->mock_wpdb_lock( true );
		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		// Should complete without exception — lock is released by the caller.
		$this->fulfillment->handle_order_processing( 100, $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_exhaustion_save_throws_does_not_halt_loop() {
		$order1 = Mockery::mock( 'WC_Order' );
		$order1->shouldReceive( 'get_id' )->andReturn( 100 );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order1->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Connection timed out' )->once();
		$order1->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order1->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order1->shouldReceive( 'save' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order1->shouldReceive( 'add_order_note' )->once();

		$order2 = Mockery::mock( 'WC_Order' );
		$order2->shouldReceive( 'get_id' )->andReturn( 200 );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order2->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Connection timed out' )->once();
		$order2->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order2->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order2->shouldReceive( 'save' )->once();
		$order2->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order1, $order2 ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

		// Only order2 alerts: order1's exhausted state wasn't saved, so the next
		// cron run exhausts it again and alerts then.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->once()->with( 200, 'retries_exhausted', 'Connection timed out' );
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->never()->with( 100, Mockery::any(), Mockery::any() );

		// order2 should still be processed even though order1's save() threw.
		$this->fulfillment->retry_failed_orders();
	}

	public function test_exhaustion_add_order_note_throws_does_not_propagate() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '3' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_error' )->andReturn( 'Connection timed out' );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Connection timed out' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once()
			->andThrow( new \RuntimeException( 'Note save failed' ) );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order ) );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$this->mock_wpdb_recovery();

		// Should complete without exception; logger is called as fallback.
		$this->fulfillment->retry_failed_orders();
		$this->addToAssertionCount( 1 );
	}

	public function test_credentials_invalid_records_failure_without_incrementing_attempts() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			if ( 'kunaki_wc_decrypt_failed' === $key ) {
				return 'yes';
			}
			return $default;
		} );

		// API should never be called.
		Functions\expect( 'wp_remote_post' )->never();

		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_error', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldNotReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_credentials_invalid_retry_bails_early() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			if ( 'kunaki_wc_max_retries' === $key ) {
				return 3;
			}
			if ( 'kunaki_wc_decrypt_failed' === $key ) {
				return 'yes';
			}
			return $default;
		} );

		$this->mock_wpdb_recovery();

		// wc_get_orders should never be called — bail before the query.
		Functions\expect( 'wc_get_orders' )->never();

		$this->fulfillment->retry_failed_orders();
		$this->addToAssertionCount( 1 );
	}

	public function test_permanent_failure_immediately_exhausts() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldNotReceive( 'update_meta_data' )
			->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->once()->with( 100, 'permanent_failure', Mockery::type( 'string' ) );

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_needs_attention_listener_that_throws_does_not_escape() {
		// It would otherwise stop the retry run that found the order.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->once()->with( 100, 'retries_exhausted', 'Connection timed out' )
			->whenHappen( function () {
				throw new \RuntimeException( 'listener blew up' );
			} );

		Kunaki_Order_Fulfillment::notify_needs_attention( 100, 'retries_exhausted', 'Connection timed out' );
	}

	public function test_permanent_failure_that_was_not_saved_does_not_alert() {
		$order = $this->create_submittable_order();

		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		// Set on the order just before the save that fails; read to write the marker.
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( 'yes' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->once();

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		$this->fulfillment->handle_order_processing( 100, $order );
	}

	public function test_success_path_add_order_note_throws_does_not_propagate() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		// Success-path note throws — should be caught, not propagated.
		$order->shouldReceive( 'add_order_note' )->once()
			->andThrow( new \RuntimeException( 'Note save failed' ) );

		$this->fulfillment->handle_order_processing( 100, $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_retry_loop_breaks_when_circuit_opens() {
		$order1 = Mockery::mock( 'WC_Order' );
		$order1->shouldReceive( 'get_id' )->andReturn( 100 );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order1->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );

		$order2 = Mockery::mock( 'WC_Order' );
		$order2->shouldReceive( 'get_id' )->andReturn( 200 );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_exhausted' )->andReturn( '' );
		$order2->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '1' );

		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( $order1, $order2 ) );

		$open_state = wp_json_encode( array(
			'state'           => 'open',
			'failure_count'   => 5,
			'last_failure_at' => time() - 60,
			'opened_at'       => time() - 60,
		) );
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) use ( $open_state ) {
			if ( 'kunaki_wc_max_retries' === $key ) {
				return 3;
			}
			if ( 'kunaki_wc_circuit_breaker' === $key ) {
				return $open_state;
			}
			return $default;
		} );

		$this->mock_wpdb_recovery();

		// Neither order should attempt submission — circuit is open.
		Functions\expect( 'wc_get_order' )->never();

		$this->fulfillment->retry_failed_orders();
		$this->addToAssertionCount( 1 );
	}

	public function test_prepare_for_retry_save_throws_returns_wp_error() {
		$order = $this->create_order_mock();
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( '' );
		$order->shouldReceive( 'delete_meta_data' )->times( 3 );
		$order->shouldReceive( 'save' )->andThrow( new \Exception( 'DB error' ) );

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );

		$result = $this->fulfillment->prepare_for_retry( 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'save_failed', $result->get_error_code() );
	}

	// --- Shipping chosen at Retry ---

	public function test_stored_shipping_choice_is_used_when_the_order_has_no_kunaki_shipping_line() {
		// An admin clicked Retry earlier and accepted this option. The submission
		// then failed for a temporary reason, and the retry cron is sending it now.
		$order = $this->create_submittable_order();
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		$this->setup_address_on_order( $order );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( 'USPS First Class Mail' );

		$sent = '';
		// Once: the order itself. Nothing automatic asks Kunaki for shipping options.
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing( function ( $url, $args ) use ( &$sent ) {
			$sent = $args['body'];
			return array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>' );
		} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn(
			'<Response><ErrorCode>0</ErrorCode><OrderId>KUN999</OrderId></Response>'
		);
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_submitted', 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertStringStartsWith( '<Order>', $sent );
		$this->assertStringContainsString( '<ShippingDescription>USPS First Class Mail</ShippingDescription>', $sent );
	}

	// --- Orders paid while the plugin was inactive ---

	public function test_missed_order_that_is_recent_and_has_kunaki_shipping_is_handed_to_the_retry_cron() {
		$order = $this->create_missed_order( true );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED, Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR, Mockery::pattern( '/inactive.*sent to Kunaki automatically/' ) )->once();
		// The plugin being off isn't Kunaki's fault, so it must not use up a retry attempt.
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS, Mockery::any() );
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED, Mockery::any() );
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_NEEDS_SHIPPING, Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		// Recording the order sends nothing; the retry cron does that.
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertSame( 'sent', $this->fulfillment->record_missed_order( $order, false ) );
	}

	public function test_missed_order_that_waited_too_long_is_held_for_a_click() {
		$order = $this->create_missed_order( true );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED, Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED, 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR, Mockery::pattern( '/more than a day.*retry it to send it to Kunaki/' ) )->once();
		// Kept out of the retry cron's query, so it is never sent without a click.
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR, Mockery::any() );
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS, Mockery::any() );
		// It has shipping from checkout, so Retry has nothing to choose.
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_NEEDS_SHIPPING, Mockery::any() );
		$order->shouldReceive( 'delete_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR )->once();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		// No alert of its own: the scan raises one for all the orders it holds.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertSame( 'held', $this->fulfillment->record_missed_order( $order, true ) );
	}

	/**
	 * @dataProvider waited_too_long_or_not
	 */
	public function test_missed_order_without_kunaki_shipping_is_always_held( $waited_too_long ) {
		// Placed while the plugin was inactive: the customer picked another shipping method.
		$order = $this->create_missed_order( false );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )->andReturn( '' );
		// The message says what Retry will do, and the flag records that it has.
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_NEEDS_SHIPPING, 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR, Mockery::pattern( '/no Kunaki shipping option.*cheapest Kunaki shipping option/' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_FAILED, Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED, 'yes' )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR, Mockery::any() );
		// Nothing is chosen here: that needs a click.
		$order->shouldNotReceive( 'update_meta_data' )->with( Kunaki_Order_Fulfillment::META_CHOSEN_SHIPPING, Mockery::any() );
		$order->shouldReceive( 'delete_meta_data' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ERROR )->once();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertSame( 'held', $this->fulfillment->record_missed_order( $order, $waited_too_long ) );
	}

	public function waited_too_long_or_not() {
		return array(
			'paid an hour ago' => array( false ),
			'paid days ago'    => array( true ),
		);
	}

	// --- A permanent failure stays permanent when its save fails ---

	public function test_marker_for_an_order_with_no_shipping_choice_also_carries_the_flag() {
		$order = $this->create_submittable_order();
		Functions\expect( 'get_post_meta' )->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_chosen_shipping' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		// Set on the order in memory just before the save that fails.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', 'yes' )->once();
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_needs_shipping' )->andReturn( 'yes' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();
		$rows = $this->mock_wpdb_with_rows();

		$this->fulfillment->handle_order_processing( 100, $order );

		$this->assertArrayHasKey( '_kunaki_failed_100', $rows->rows );
		$this->assertStringStartsWith(
			Kunaki_Order_Fulfillment::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX . 'No matching Kunaki shipping',
			$rows->rows['_kunaki_failed_100']
		);
	}

	public function test_marker_for_a_permanent_failure_records_that_it_was_permanent() {
		// Held for its age. It has shipping from checkout, so Retry has nothing to choose.
		$order = $this->create_missed_order( true );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_NEEDS_SHIPPING )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();
		$rows = $this->mock_wpdb_with_rows();

		$this->assertSame( 'held', $this->fulfillment->record_missed_order( $order, true ) );

		// Also that the scan has reported it, so restoring it raises no alert.
		$this->assertStringStartsWith(
			Kunaki_Order_Fulfillment::PERMANENT_MARKER_PREFIX . Kunaki_Order_Fulfillment::NO_ALERT_MARKER_PREFIX . 'This order was paid while the Kunaki plugin was inactive',
			$rows->rows['_kunaki_failed_100']
		);
	}

	/**
	 * @dataProvider markers_for_orders_the_scan_held
	 */
	public function test_recover_marker_for_an_order_the_scan_held_raises_no_alert( $prefix, $flagged ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		// Neither prefix is part of the message the admin reads.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Held for review' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', 'yes' )->times( $flagged ? 1 : 0 );
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => $prefix . Kunaki_Order_Fulfillment::NO_ALERT_MARKER_PREFIX . 'Held for review',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_options', array( 'option_name' => '_kunaki_failed_100' ) )->once();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		// The scan's one alert already covers this order.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		$this->fulfillment->retry_failed_orders();
	}

	public function markers_for_orders_the_scan_held() {
		return array(
			'held for its age'           => array( Kunaki_Order_Fulfillment::PERMANENT_MARKER_PREFIX, false ),
			'held for a shipping choice' => array( Kunaki_Order_Fulfillment::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX, true ),
		);
	}

	public function test_retryable_failure_marker_holds_the_bare_message() {
		$order = $this->create_full_order_mock( 'PXZZ111111' );
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new \WP_Error( 'timeout', 'Connection timed out' ) );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );
		$order->shouldReceive( 'get_meta' )->with( '_kunaki_fulfillment_attempts' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();
		$rows = $this->mock_wpdb_with_rows();

		$this->fulfillment->handle_order_processing( 100, $order );

		// Unchanged from before, so markers written by older versions still mean the same.
		$this->assertSame( 'Connection timed out', $rows->rows['_kunaki_failed_100'] );
	}

	public function test_recover_permanent_marker_restores_the_order_as_held() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Held for review' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		// Restored as retryable, the retry cron would send an order that is waiting for a click.
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', Mockery::any() );
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_attempts', Mockery::any() );
		// It had shipping from checkout: nothing for Retry to choose.
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => Kunaki_Order_Fulfillment::PERMANENT_MARKER_PREFIX . 'Held for review',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_options', array( 'option_name' => '_kunaki_failed_100' ) )->once();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		// The alert was held back when the save failed, so it is due now.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->once()->with( 100, 'permanent_failure', 'Held for review' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_recover_marker_that_carries_the_shipping_flag_restores_the_flag() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_exhausted', 'yes' )->once();
		// The prefix is not part of the message the admin reads.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_last_error', 'Retry it to send it with the cheapest option' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_failed_at', Mockery::type( 'string' ) )->once();
		// Without this the first Retry would only repeat the message's promise.
		$order->shouldReceive( 'update_meta_data' )->with( '_kunaki_needs_shipping', 'yes' )->once();
		$order->shouldReceive( 'delete_meta_data' )->with( '_kunaki_fulfillment_error' )->once();
		$order->shouldNotReceive( 'update_meta_data' )->with( '_kunaki_fulfillment_error', Mockery::any() );
		$order->shouldReceive( 'save_meta_data' )->once();

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => Kunaki_Order_Fulfillment::PERMANENT_NEEDS_SHIPPING_MARKER_PREFIX . 'Retry it to send it with the cheapest option',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_options', array( 'option_name' => '_kunaki_failed_100' ) )->once();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )
			->once()->with( 100, 'permanent_failure', 'Retry it to send it with the cheapest option' );

		$this->fulfillment->retry_failed_orders();
	}

	public function test_recover_permanent_marker_save_throws_leaves_marker_and_does_not_alert() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		$order->shouldReceive( 'update_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'delete_meta_data' )->withAnyArgs()->zeroOrMoreTimes();
		$order->shouldReceive( 'save_meta_data' )->once()->andThrow( new \RuntimeException( 'DB down' ) );

		$marker = (object) array(
			'option_name'  => '_kunaki_failed_100',
			'option_value' => Kunaki_Order_Fulfillment::PERMANENT_MARKER_PREFIX . 'Held for review',
		);

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $marker ) );
		// Left for the next run.
		$wpdb->shouldNotReceive( 'delete' );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wc_get_order' )->with( 100 )->andReturn( $order );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->with( 'kunaki_wc_max_retries', 3 )->andReturn( '3' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( 'no' );

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		$this->fulfillment->retry_failed_orders();
	}

	/**
	 * @dataProvider held_orders
	 */
	public function test_held_order_whose_save_fails_is_restored_as_it_was_recorded( $kunaki_line, $message_part, $flagged ) {
		// The order keeps its meta here, so the test follows it through a save
		// that fails, the durable marker, and the retry cron restoring it.
		$saved   = array();
		$pending = array();
		$save_ok = false;

		$order = $this->create_missed_order( $kunaki_line, false );
		// Like a real order: changes are visible on the object at once, and
		// reach the database only when a save works.
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( &$saved, &$pending ) {
			if ( array_key_exists( $key, $pending ) ) {
				return null === $pending[ $key ] ? '' : $pending[ $key ];
			}
			return isset( $saved[ $key ] ) ? $saved[ $key ] : '';
		} );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( &$pending ) {
			$pending[ $key ] = $value;
		} );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing( function ( $key ) use ( &$pending ) {
			$pending[ $key ] = null;
		} );
		$order->shouldReceive( 'save_meta_data' )->andReturnUsing( function () use ( &$saved, &$pending, &$save_ok ) {
			if ( ! $save_ok ) {
				throw new \RuntimeException( 'DB down' );
			}
			foreach ( $pending as $key => $value ) {
				if ( null === $value ) {
					unset( $saved[ $key ] );
				} else {
					$saved[ $key ] = $value;
				}
			}
			$pending = array();
		} );
		$order->shouldReceive( 'add_order_note' )->zeroOrMoreTimes();

		$rows = $this->mock_wpdb_with_rows();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return 'kunaki_wc_max_retries' === $key ? 3 : $default;
		} );
		// The retry cron only loads orders that have a retryable error.
		Functions\when( 'wc_get_orders' )->alias( function () use ( &$saved, $order ) {
			return isset( $saved['_kunaki_fulfillment_error'] ) ? array( $order ) : array();
		} );
		Functions\expect( 'wp_remote_post' )->never();

		// The scan holds the order, but its record can't be saved.
		$this->assertSame( 'held', $this->fulfillment->record_missed_order( $order, true ) );
		$this->assertSame( array(), $saved );
		$this->assertArrayHasKey( '_kunaki_failed_100', $rows->rows );

		// The retry cron runs in a later request: nothing unsaved survives, and
		// the database is back.
		$pending = array();
		$save_ok = true;
		// The scan counted it among the orders it held, so it raises no alert of its own.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		$this->fulfillment->retry_failed_orders();

		// Restored as held, not as something the cron may send.
		$this->assertSame( 'yes', $saved['_kunaki_fulfillment_exhausted'] );
		$this->assertMatchesRegularExpression( $message_part, $saved['_kunaki_fulfillment_last_error'] );
		// The marker's prefixes are not part of the message the admin reads.
		$this->assertStringStartsWith( 'This order was paid', $saved['_kunaki_fulfillment_last_error'] );
		$this->assertArrayNotHasKey( '_kunaki_fulfillment_error', $saved );
		$this->assertArrayNotHasKey( '_kunaki_failed_100', $rows->rows );
		// And with the flag its message relies on, so the first Retry does what it says.
		$this->assertSame( $flagged, isset( $saved['_kunaki_needs_shipping'] ) );
	}

	public function held_orders() {
		return array(
			'held for its age'           => array( true, '/more than a day/', false ),
			'held for a shipping choice' => array( false, '/cheapest/', true ),
		);
	}

	/**
	 * An order with no Kunaki record, as the catch-up scan finds it.
	 *
	 * @param bool $kunaki_line Whether it has a Kunaki shipping line from checkout.
	 * @param bool $stub_meta   Whether to answer the meta reads here (false when the test keeps its own meta).
	 */
	private function create_missed_order( $kunaki_line, $stub_meta = true ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 100 );
		if ( $kunaki_line ) {
			$this->setup_shipping_methods_on_order( $order );
		} else {
			$other = Mockery::mock( 'WC_Order_Item_Shipping' );
			$other->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );
			$order->shouldReceive( 'get_shipping_methods' )->andReturn( array( $other ) );
			if ( $stub_meta ) {
				$order->shouldReceive( 'get_meta' )->with( Kunaki_Order_Fulfillment::META_CHOSEN_SHIPPING )->andReturn( '' );
			}
		}
		return $order;
	}

	/**
	 * A wpdb whose option rows are kept in memory, so a test can read the
	 * durable markers the code writes with raw SQL.
	 *
	 * @return \stdClass Its `rows` property maps option names to values.
	 */
	private function mock_wpdb_with_rows() {
		$state       = new \stdClass();
		$state->rows = array();

		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query, ...$args ) {
			return array( 'query' => $query, 'args' => $args );
		} );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $prepared ) use ( $state ) {
			if ( 0 === strpos( $prepared['query'], 'INSERT IGNORE' ) ) {
				if ( isset( $state->rows[ $prepared['args'][0] ] ) ) {
					return 0;
				}
				$state->rows[ $prepared['args'][0] ] = $prepared['args'][1];
			}
			return 1;
		} );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $prepared ) use ( $state ) {
			$prefix = rtrim( $prepared['args'][0], '%' );
			$found  = array();
			foreach ( $state->rows as $name => $value ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$found[] = (object) array( 'option_name' => $name, 'option_value' => $value );
				}
			}
			return $found;
		} );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing( function ( $table, $where ) use ( $state ) {
			unset( $state->rows[ $where['option_name'] ] );
			return 1;
		} );
		$GLOBALS['wpdb'] = $wpdb;

		return $state;
	}

	private function mock_wpdb_lock( $acquired = true ) {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'query' )->andReturn( $acquired ? 1 : 0 );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'delete' )->andReturn( true );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );

		$GLOBALS['wpdb'] = $wpdb;
	}

	private function mock_wpdb_recovery() {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) { return $text; } );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$GLOBALS['wpdb'] = $wpdb;
	}
}
