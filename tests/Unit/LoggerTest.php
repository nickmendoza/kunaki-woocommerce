<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Logger;

class LoggerTest extends KunakiTestCase {

	public function test_info() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'info', '{"message":"Test info message"}', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::info( 'Test info message' );
	}

	public function test_error() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'error', '{"message":"Something broke"}', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::error( 'Something broke' );
	}

	public function test_debug_suppressed_when_disabled() {
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_debug_log', 'no' )->andReturn( 'no' );
		Functions\expect( 'wc_get_logger' )->never();

		Kunaki_Logger::debug( 'This should not be logged' );
	}

	public function test_debug_logged_when_enabled() {
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_debug_log', 'no' )->andReturn( 'yes' );

		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'debug', '{"message":"Debug message"}', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::debug( 'Debug message' );
	}

	public function test_context_merged_into_json() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()
			->with(
				'info',
				Mockery::on( function ( $json ) {
					$data = json_decode( $json, true );
					return 'Kunaki status changed.' === $data['message']
						&& 123 === $data['order_id']
						&& 'PROCESSING' === $data['from']
						&& 'SHIPPED' === $data['to'];
				} ),
				array( 'source' => 'kunaki-woocommerce' )
			);
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::info( 'Kunaki status changed.', array(
			'order_id' => 123,
			'from'     => 'PROCESSING',
			'to'       => 'SHIPPED',
		) );
	}

	public function test_context_passed_through_debug() {
		Functions\expect( 'get_option' )
			->once()->with( 'kunaki_wc_debug_log', 'no' )->andReturn( 'yes' );

		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()
			->with(
				'debug',
				Mockery::on( function ( $json ) {
					$data = json_decode( $json, true );
					return 'API request.' === $data['message']
						&& '<xml/>' === $data['body'];
				} ),
				array( 'source' => 'kunaki-woocommerce' )
			);
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::debug( 'API request.', array( 'body' => '<xml/>' ) );
	}

	public function test_context_message_key_does_not_overwrite_message() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()
			->with(
				'info',
				Mockery::on( function ( $json ) {
					$data = json_decode( $json, true );
					return 'Real message.' === $data['message'];
				} ),
				array( 'source' => 'kunaki-woocommerce' )
			);
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::info( 'Real message.', array( 'message' => 'should be ignored' ) );
	}

	public function test_invalid_utf8_falls_back_to_plain_message() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()
			->with( 'error', 'Parse error.', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::error( 'Parse error.', array( 'body' => "\xc3\x28" ) );
	}
}
