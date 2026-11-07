<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_CLI_Command;

class CLICommandTest extends KunakiTestCase {

	/** @var Kunaki_CLI_Command */
	private $command;

	/** @var \Mockery\MockInterface */
	private $cli;

	protected function set_up() {
		parent::set_up();
		$this->cli     = Mockery::mock( 'alias:WP_CLI' );
		$this->command = new Kunaki_CLI_Command();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
			return 'kunaki_wc_debug_log' === $key ? 'no' : $default;
		} );
	}

	public function test_sync_no_pending_orders() {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		$this->cli->shouldReceive( 'success' )
			->with( Mockery::pattern( '/No pending/' ) )->once();

		$this->command->sync( array(), array() );
	}

	public function test_sync_dry_run_lists_orders() {
		Functions\when( 'wc_get_orders' )->justReturn( array( 100, 200, 300 ) );

		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/Found 3/' ) )->once();
		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/#100/' ) )->once();
		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/#200/' ) )->once();
		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/#300/' ) )->once();

		$this->command->sync( array(), array( 'dry-run' => true ) );
	}

	public function test_sync_reports_what_actually_happened() {
		$this->mock_orders( array( 100 => 'PENDING', 200 => 'PROCESSING' ) );

		$this->cli->shouldReceive( 'log' )->with( Mockery::pattern( '/Found 2/' ) )->once();
		// Both checked, but only #100 changed status. Neither left "processing".
		$this->cli->shouldReceive( 'success' )->with( Mockery::pattern( '/2 order.*checked.*1 status/' ) )->once();

		$this->command->sync( array(), array() );
	}

	public function test_sync_limit_counts_only_orders_checked() {
		$this->mock_orders( array( 100 => 'PROCESSING', 200 => 'PROCESSING', 300 => 'PROCESSING' ) );

		$this->cli->shouldReceive( 'log' )->with( Mockery::pattern( '/Found 3/' ) )->once();
		$this->cli->shouldReceive( 'success' )->with( Mockery::pattern( '/2 order.*checked.*0 status/' ) )->once();

		$this->command->sync( array(), array( 'limit' => '2' ) );
	}

	public function test_dry_run_excludes_orders_with_final_status() {
		Functions\expect( 'wc_get_orders' )->andReturnUsing( function ( $args ) {
			if ( isset( $args['meta_value'] ) ) {
				return array( 200 );
			}
			return '_kunaki_sync_note_pending' === ( $args['meta_key'] ?? '' ) ? array() : array( 100, 200 );
		} );

		$this->cli->shouldReceive( 'log' )->with( Mockery::pattern( '/Found 1/' ) )->once();
		$this->cli->shouldReceive( 'log' )->with( Mockery::pattern( '/#100/' ) )->once();

		$this->command->sync( array(), array( 'dry-run' => true ) );
	}

	/**
	 * Mock orders and an API that reports them all as PROCESSING.
	 *
	 * @param array $previous_statuses Order ID => stored Kunaki status.
	 */
	private function mock_orders( $previous_statuses ) {
		$this->mock_wpdb_lock();

		Functions\when( 'wc_get_orders' )->alias( function ( $args ) use ( $previous_statuses ) {
			return isset( $args['meta_value'] ) || '_kunaki_sync_note_pending' === ( $args['meta_key'] ?? '' )
				? array()
				: array_keys( $previous_statuses );
		} );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $previous_statuses ) {
			$order = Mockery::mock( 'WC_Order' )->shouldIgnoreMissing();
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_id' )->andReturn( 'KUN' . $id );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_order_status' )->andReturn( $previous_statuses[ $id ] );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_sync_note_pending' )->andReturn( '' );
			return $order;
		} );

		$xml = '<Response><ErrorCode>0</ErrorCode><OrderStatus>PROCESSING</OrderStatus><TrackingType>NA</TrackingType><TrackingId>NA</TrackingId></Response>';
		Functions\when( 'wp_remote_post' )->justReturn( array( 'body' => $xml ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $xml );
	}

	// --- Helpers ---

	private function mock_wpdb_lock() {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return 'prepared_query'; } );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'delete' )->andReturn( true );

		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}
}
