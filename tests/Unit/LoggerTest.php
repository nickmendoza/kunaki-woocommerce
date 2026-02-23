<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Logger;

class LoggerTest extends KunakiTestCase {

	public function test_info() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'info', 'Test info message', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::info( 'Test info message' );
	}

	public function test_error() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'error', 'Something broke', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::error( 'Something broke' );
	}

	public function test_warning() {
		$logger = Mockery::mock( 'WC_Logger' );
		$logger->shouldReceive( 'log' )
			->once()->with( 'warning', 'Watch out', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::warning( 'Watch out' );
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
			->once()->with( 'debug', 'Debug message', array( 'source' => 'kunaki-woocommerce' ) );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $logger );

		Kunaki_Logger::debug( 'Debug message' );
	}
}
