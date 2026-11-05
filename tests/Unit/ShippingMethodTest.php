<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use WC_Shipping_Kunaki;

class ShippingMethodTest extends KunakiTestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return 'kunaki_wc_debug_log' === $key ? 'no' : $default;
		} );

		// Delivery estimator: no historical data available → estimates disabled.
		Functions\when( 'wc_get_orders' )->justReturn( array() );
	}

	public function test_no_kunaki_products_yields_no_rates() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$this->assertEmpty( $shipping->get_rates_added() );
	}

	public function test_only_kunaki_products_sent_to_api() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return ( '_kunaki_product_id' === $key && 10 === $id ) ? 'PXZZ111111' : '';
		} );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->mock_wc_countries();

		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured ) {
				$captured = $args['body'];
				return array( 'body' => '<Response><ErrorCode>0</ErrorCode>
					<Option><Description>Standard</Description><DeliveryTime>5 days</DeliveryTime><Price>5.00</Price></Option>
				</Response>' );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturnUsing( function () {
			return '<Response><ErrorCode>0</ErrorCode>
				<Option><Description>Standard</Description><DeliveryTime>5 days</DeliveryTime><Price>5.00</Price></Option>
			</Response>';
		} );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 2 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) ) );

		$this->assertCount( 1, $shipping->get_rates_added() );
		$this->assertStringContainsString( 'PXZZ111111', $captured );
		$this->assertStringContainsString( '<Quantity>2</Quantity>', $captured );
	}

	public function test_cache_hit_skips_api_call() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		$shipping_cache = array(
			array( 'description' => 'Cached Rate', 'delivery_time' => '2 days', 'price' => 3.50 ),
		);
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( $shipping_cache ) {
			if ( 0 === strpos( $key, 'kunaki_ship_' ) ) {
				return $shipping_cache;
			}
			return false;
		} );
		$this->mock_wc_countries();

		Functions\expect( 'wp_remote_post' )->never();

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$rates = $shipping->get_rates_added();
		$this->assertCount( 1, $rates );
		$this->assertStringContainsString( 'Cached Rate', $rates[0]['label'] );
	}

	public function test_cache_miss_stores_transient() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\when( 'get_transient' )->justReturn( false );
		$this->mock_wc_countries();

		$xml = '<Response><ErrorCode>0</ErrorCode>
			<Option><Description>Express</Description><DeliveryTime>1 day</DeliveryTime><Price>12.00</Price></Option>
		</Response>';
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $xml ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $xml );

		$set_transient_calls = array();
		Functions\when( 'set_transient' )->alias( function ( $key, $value, $expiration ) use ( &$set_transient_calls ) {
			$set_transient_calls[ $key ] = array( 'value' => $value, 'expiration' => $expiration );
			return true;
		} );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		// Verify the shipping cache transient was stored with correct TTL.
		$shipping_keys = array_filter( array_keys( $set_transient_calls ), function ( $k ) {
			return 0 === strpos( $k, 'kunaki_ship_' );
		} );
		$this->assertCount( 1, $shipping_keys );
		$shipping_key = reset( $shipping_keys );
		$this->assertIsArray( $set_transient_calls[ $shipping_key ]['value'] );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, $set_transient_calls[ $shipping_key ]['expiration'] );
	}

	public function test_empty_country_yields_no_rates() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );

		$wc_countries = Mockery::mock( 'WC_Countries' );
		$wc_countries->shouldReceive( 'get_countries' )->andReturn( array() );
		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $wc_countries;
		Functions\when( 'WC' )->justReturn( $wc );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package(
			array( array( 'product_id' => 10, 'quantity' => 1 ) ), 'XX', '', '90210'
		) );

		$this->assertEmpty( $shipping->get_rates_added() );
	}

	public function test_empty_postcode_still_queries_api() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->mock_wc_countries();

		$xml = '<Response><ErrorCode>0</ErrorCode>
			<Option><Description>Standard</Description><DeliveryTime>10 days</DeliveryTime><Price>7.00</Price></Option>
		</Response>';
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $xml ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $xml );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package(
			array( array( 'product_id' => 10, 'quantity' => 1 ) ), 'US', 'CA', ''
		) );

		$this->assertCount( 1, $shipping->get_rates_added() );
	}

	public function test_non_us_ca_sends_empty_state() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->mock_wc_countries();

		$captured_xml = null;
		$xml = '<Response><ErrorCode>0</ErrorCode>
			<Option><Description>Standard</Description><DeliveryTime>7 days</DeliveryTime><Price>8.00</Price></Option>
		</Response>';

		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_xml, $xml ) {
				$captured_xml = $args['body'];
				return array( 'body' => $xml );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $xml );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package(
			array( array( 'product_id' => 10, 'quantity' => 1 ) ), 'GB', 'London', 'SW1A 1AA'
		) );

		$this->assertStringContainsString( '<State_Province></State_Province>', $captured_xml );
	}

	public function test_rate_label_format() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		$shipping_cache = array(
			array( 'description' => 'Priority Mail', 'delivery_time' => '2-3 days', 'price' => 9.99 ),
		);
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( $shipping_cache ) {
			return 0 === strpos( $key, 'kunaki_ship_' ) ? $shipping_cache : false;
		} );
		$this->mock_wc_countries();

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$rates = $shipping->get_rates_added();
		$this->assertSame( 'Priority Mail (2-3 days)', $rates[0]['label'] );
	}

	public function test_rate_meta_has_shipping_description() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		$shipping_cache = array(
			array( 'description' => 'Express', 'delivery_time' => '1 day', 'price' => 15.00 ),
		);
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( $shipping_cache ) {
			return 0 === strpos( $key, 'kunaki_ship_' ) ? $shipping_cache : false;
		} );
		$this->mock_wc_countries();

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$rates = $shipping->get_rates_added();
		$this->assertSame( 'Express', $rates[0]['meta_data']['kunaki_shipping_description'] );
		$this->assertSame( '1 day', $rates[0]['meta_data']['kunaki_delivery_time'] );
	}

	public function test_country_name_strips_parenthetical_code() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$wc_countries = Mockery::mock( 'WC_Countries' );
		$wc_countries->shouldReceive( 'get_countries' )->andReturn( array(
			'US' => 'United States (US)', 'CA' => 'Canada (CA)', 'GB' => 'United Kingdom (GB)',
		) );
		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $wc_countries;
		Functions\when( 'WC' )->justReturn( $wc );

		$captured_xml = null;
		$xml = '<Response><ErrorCode>0</ErrorCode>
			<Option><Description>Standard</Description><DeliveryTime>5 days</DeliveryTime><Price>5.00</Price></Option>
		</Response>';

		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_xml, $xml ) {
				$captured_xml = $args['body'];
				return array( 'body' => $xml );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $xml );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$this->assertStringContainsString( '<Country>United States</Country>', $captured_xml );
		$this->assertStringNotContainsString( '(US)', $captured_xml );
	}

	public function test_outage_yields_no_rates_and_flags_rates_unavailable() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\expect( 'get_transient' )->twice()->andReturn( false );
		$this->mock_wc_countries();

		Functions\expect( 'wp_remote_post' )->once()
			->with( Mockery::any(), Mockery::on( function ( $args ) {
				return 10 === $args['timeout'];
			} ) )
			->andReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$set = array();
		Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl ) use ( &$set ) {
			$set[ $key ] = $ttl;
			return true;
		} );
		Functions\when( 'update_option' )->justReturn( true );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$this->assertEmpty( $shipping->get_rates_added() );
		$this->assertArrayHasKey( 'kunaki_wc_rates_unavailable', $set );
		$error_keys = array_filter( array_keys( $set ), function ( $key ) {
			return 0 === strpos( $key, 'kunaki_ship_err_' );
		} );
		$this->assertCount( 1, $error_keys );
		$this->assertSame( 120, $set[ reset( $error_keys ) ] );
	}

	public function test_bad_address_error_is_cached_but_not_flagged_as_outage() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		Functions\expect( 'get_transient' )->twice()->andReturn( false );
		$this->mock_wc_countries();

		$xml = '<Response><ErrorCode>500</ErrorCode><ErrorText>Invalid postal code</ErrorText></Response>';
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $xml ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $xml );

		$set = array();
		Functions\when( 'set_transient' )->alias( function ( $key ) use ( &$set ) {
			$set[] = $key;
			return true;
		} );

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$this->assertEmpty( $shipping->get_rates_added() );
		$this->assertNotContains( 'kunaki_wc_rates_unavailable', $set );
		$this->assertStringStartsWith( 'kunaki_ship_err_', $set[0] );
	}

	public function test_recent_failure_skips_api_call() {
		Functions\when( 'get_post_meta' )->justReturn( 'PXZZ111111' );
		$this->mock_wc_countries();
		Functions\when( 'get_transient' )->alias( function ( $key ) {
			return 0 === strpos( $key, 'kunaki_ship_err_' ) ? 1 : false;
		} );
		Functions\expect( 'wp_remote_post' )->never();

		$shipping = new WC_Shipping_Kunaki();
		$shipping->calculate_shipping( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$this->assertEmpty( $shipping->get_rates_added() );
	}

	// --- Helpers ---

	private function build_package( $items, $country = 'US', $state = 'CA', $postcode = '90210' ) {
		$contents = array();
		foreach ( $items as $item ) {
			$contents[] = array(
				'product_id' => $item['product_id'],
				'quantity'   => $item['quantity'],
			);
		}
		return array(
			'contents'    => $contents,
			'destination' => array( 'country' => $country, 'state' => $state, 'postcode' => $postcode ),
		);
	}

	private function mock_wc_countries() {
		$wc_countries = Mockery::mock( 'WC_Countries' );
		$wc_countries->shouldReceive( 'get_countries' )->andReturn( array(
			'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
		) );
		$wc = Mockery::mock( 'WooCommerce' );
		$wc->countries = $wc_countries;
		Functions\when( 'WC' )->justReturn( $wc );
	}
}
