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
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array( 100, 200, 300 ) );

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

	public function test_sync_calls_sync_all_pending_orders() {
		$this->mock_wpdb_lock();

		// First call: discovery query returns 2 order IDs.
		// Second call: sync_all_pending_orders re-queries and returns order mocks.
		// Third call: post-sync remaining query returns 1 (one order transitioned).
		$call_count = 0;
		Functions\expect( 'wc_get_orders' )->times( 3 )
			->andReturnUsing( function () use ( &$call_count ) {
				$call_count++;
				if ( 1 === $call_count ) {
					return array( 100, 200 );
				}
				if ( 2 === $call_count ) {
					// sync_all_pending_orders query — return empty to skip processing.
					return array();
				}
				// Post-sync remaining query.
				return array( 200 );
			} );

		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/Found 2/' ) )->once();
		$this->cli->shouldReceive( 'success' )
			->with( Mockery::pattern( '/2 order.*checked.*1 status/' ) )->once();

		$this->command->sync( array(), array() );
	}

	public function test_sync_with_limit() {
		$this->mock_wpdb_lock();

		$call_count = 0;
		Functions\expect( 'wc_get_orders' )->times( 3 )
			->andReturnUsing( function ( $args ) use ( &$call_count ) {
				$call_count++;
				if ( 2 === $call_count ) {
					// The sync query fetches all IDs; the limit is applied while
					// iterating (see OrderStatusSyncTest::test_sync_respects_custom_limit).
					$this->assertSame( -1, $args['limit'] );
					$this->assertSame( 'ids', $args['return'] );
					return array();
				}
				return array( 100, 200, 300, 400, 500, 600, 700 );
			} );

		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/Found 7/' ) )->once();
		$this->cli->shouldReceive( 'success' )
			->with( Mockery::pattern( '/5 order.*checked.*0 status/' ) )->once();

		$this->command->sync( array(), array( 'limit' => '5' ) );
	}

	public function test_sync_all_updated() {
		$this->mock_wpdb_lock();

		$call_count = 0;
		Functions\expect( 'wc_get_orders' )->times( 3 )
			->andReturnUsing( function () use ( &$call_count ) {
				$call_count++;
				if ( 1 === $call_count ) {
					return array( 100, 200 );
				}
				// Both sync query and remaining query return empty.
				return array();
			} );

		$this->cli->shouldReceive( 'log' )
			->with( Mockery::pattern( '/Found 2/' ) )->once();
		$this->cli->shouldReceive( 'success' )
			->with( Mockery::pattern( '/2 order.*checked.*2 status/' ) )->once();

		$this->command->sync( array(), array() );
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
