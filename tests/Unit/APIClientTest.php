<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_API_Client;
use WP_Error;

class APIClientTest extends KunakiTestCase {

	public function test_constructor_uses_injected_credentials() {
		$client = new Kunaki_API_Client( 'user@test.com', 'secret', 'LIVE' );
		$this->assertInstanceOf( Kunaki_API_Client::class, $client );
	}

	public function test_constructor_falls_back_to_options() {
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_user_id', '' )->andReturn( 'wp@user.com' );
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_password', '' )->andReturn( 'wp_pass' );
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_mode', 'TEST' )->andReturn( 'TEST' );

		$client = new Kunaki_API_Client();
		$this->assertInstanceOf( Kunaki_API_Client::class, $client );
	}

	public function test_shipping_options_parses_multiple_options() {
		$xml = '<Response>
			<ErrorCode>0</ErrorCode>
			<Option>
				<Description>USPS First Class</Description>
				<DeliveryTime>3-5 business days</DeliveryTime>
				<Price>3.99</Price>
			</Option>
			<Option>
				<Description>USPS Priority</Description>
				<DeliveryTime>1-3 business days</DeliveryTime>
				<Price>7.99</Price>
			</Option>
		</Response>';

		$this->mock_successful_http_response( $xml );

		$client  = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$options = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 2 ),
		) );

		$this->assertCount( 2, $options );
		$this->assertSame( 'USPS First Class', $options[0]['description'] );
		$this->assertSame( '3-5 business days', $options[0]['delivery_time'] );
		$this->assertSame( 3.99, $options[0]['price'] );
		$this->assertSame( 'USPS Priority', $options[1]['description'] );
		$this->assertSame( 7.99, $options[1]['price'] );
	}

	public function test_shipping_options_returns_empty_when_none() {
		$this->mock_successful_http_response( '<Response><ErrorCode>0</ErrorCode></Response>' );

		$client  = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$options = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ),
		) );

		$this->assertEmpty( $options );
	}

	public function test_shipping_options_http_failure() {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_shipping_options_non_200() {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'response' => array( 'code' => 500 ), 'body' => 'Server Error' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 500 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'Server Error' );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kunaki_http_error', $result->get_error_code() );
	}

	public function test_shipping_options_malformed_xml() {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( array( 'body' => 'not xml at all <<<' ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'not xml at all <<<' );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kunaki_xml_error', $result->get_error_code() );
	}

	public function test_shipping_options_api_error_code() {
		$xml = '<Response><ErrorCode>1</ErrorCode><ErrorText>Invalid Product ID</ErrorText></Response>';
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $xml ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $xml );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_shipping_options( 'United States', 'CA', '90210', array(
			array( 'product_id' => 'INVALID', 'quantity' => 1 ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kunaki_api_error', $result->get_error_code() );
		$this->assertSame( 'Invalid Product ID', $result->get_error_message() );
	}

	public function test_shipping_options_html_wrapped_response() {
		$html = '<html><body><Response><ErrorCode>0</ErrorCode>
			<Option><Description>Standard</Description><DeliveryTime>5-7 days</DeliveryTime><Price>4.50</Price></Option>
		</Response></body></html>';

		$this->mock_successful_http_response( $html );

		$client  = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$options = $client->get_shipping_options( 'United States', 'NY', '10001', array(
			array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ),
		) );

		$this->assertCount( 1, $options );
		$this->assertSame( 'Standard', $options[0]['description'] );
		$this->assertSame( 4.50, $options[0]['price'] );
	}

	public function test_submit_order_success() {
		$xml = '<Response><ErrorCode>0</ErrorCode><OrderId>KUN123456</OrderId></Response>';
		$this->mock_successful_http_response( $xml );

		$client = new Kunaki_API_Client( 'user@test.com', 'pass', 'LIVE' );
		$result = $client->submit_order(
			array(
				'name' => 'John Doe', 'company' => '', 'address_1' => '123 Main St',
				'address_2' => '', 'city' => 'Beverly Hills', 'state' => 'CA',
				'postcode' => '90210', 'country' => 'United States',
			),
			'USPS Priority',
			array( array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ) )
		);

		$this->assertSame( 'KUN123456', $result );
	}

	public function test_submit_order_http_failure() {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->submit_order(
			array(
				'name' => 'Jane', 'address_1' => '1 St', 'city' => 'LA',
				'state' => 'CA', 'postcode' => '90210', 'country' => 'US',
			),
			'USPS',
			array( array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_submit_order_empty_order_id() {
		$xml = '<Response><ErrorCode>0</ErrorCode><OrderId></OrderId></Response>';
		$this->mock_successful_http_response( $xml );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->submit_order(
			array(
				'name' => 'Jane', 'address_1' => '1 St', 'city' => 'LA',
				'state' => 'CA', 'postcode' => '90210', 'country' => 'US',
			),
			'USPS',
			array( array( 'product_id' => 'PXZZ111111', 'quantity' => 1 ) )
		);

		$this->assertSame( '', $result );
	}

	public function test_order_status_with_tracking() {
		$xml = '<Response>
			<ErrorCode>0</ErrorCode>
			<OrderId>KUN123456</OrderId>
			<OrderStatus>Shipped</OrderStatus>
			<TrackingType>USPS</TrackingType>
			<TrackingId>9400111899223100001234</TrackingId>
		</Response>';

		$this->mock_successful_http_response( $xml );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_order_status( 'KUN123456' );

		$this->assertSame( 'KUN123456', $result['order_id'] );
		$this->assertSame( 'SHIPPED', $result['status'] );
		$this->assertSame( 'USPS', $result['tracking_type'] );
		$this->assertSame( '9400111899223100001234', $result['tracking_id'] );
	}

	public function test_order_status_http_failure() {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( new WP_Error( 'http_request_failed', 'DNS error' ) );

		$client = new Kunaki_API_Client( 'u', 'p', 'TEST' );
		$result = $client->get_order_status( 'KUN123456' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_product_image_url_default_view() {
		$url = Kunaki_API_Client::get_product_image_url( 'PXZZ111111' );
		$this->assertSame( 'https://kunaki.com/ProductImage.ASP?T=I&ST=BX&PID=PXZZ111111', $url );
	}

	public function test_product_image_url_custom_view() {
		$url = Kunaki_API_Client::get_product_image_url( 'PXZZ111111', 'FO' );
		$this->assertSame( 'https://kunaki.com/ProductImage.ASP?T=I&ST=FO&PID=PXZZ111111', $url );
	}

	public function test_submit_order_xml_contains_credentials() {
		$captured_body = null;
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()
			->andReturnUsing( function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];
				return array( 'body' => '<Response><ErrorCode>0</ErrorCode><OrderId>X</OrderId></Response>' );
			} );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->andReturnUsing( function () {
			return '<Response><ErrorCode>0</ErrorCode><OrderId>X</OrderId></Response>';
		} );

		$client = new Kunaki_API_Client( 'me@test.com', 'mypass', 'LIVE' );
		$client->submit_order(
			array(
				'name' => 'Test', 'address_1' => '1 A', 'city' => 'C',
				'state' => 'S', 'postcode' => '11111', 'country' => 'US',
			),
			'Standard',
			array( array( 'product_id' => 'ABCDEFGHIJ', 'quantity' => 3 ) )
		);

		$this->assertStringContainsString( '<UserId>me@test.com</UserId>', $captured_body );
		$this->assertStringContainsString( '<Password>mypass</Password>', $captured_body );
		$this->assertStringContainsString( '<Mode>LIVE</Mode>', $captured_body );
		$this->assertStringContainsString( '<ProductId>ABCDEFGHIJ</ProductId>', $captured_body );
		$this->assertStringContainsString( '<Quantity>3</Quantity>', $captured_body );
	}

	private function mock_successful_http_response( $body ) {
		$this->mock_logger();

		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'body' => $body ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $body );
	}

	private function mock_logger() {
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return 'kunaki_wc_debug_log' === $key ? 'no' : $default;
		} );
		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}
}
