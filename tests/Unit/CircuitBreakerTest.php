<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Circuit_Breaker;
use WP_Error;

class CircuitBreakerTest extends KunakiTestCase {

	/** @var array State to return from get_option for circuit breaker key. */
	private $option_store = array();

	/** @var array Captured update_option calls. */
	private $updated_options = array();

	/** @var array Captured delete_option calls. */
	private $deleted_options = array();

	protected function set_up() {
		parent::set_up();
		$this->option_store    = array();
		$this->updated_options = array();
		$this->deleted_options = array();
		$this->wire_mocks();
	}

	// ── State management ──────────────────────────────────────────────

	public function test_default_state_is_closed() {
		$cb    = new Kunaki_Circuit_Breaker();
		$state = $cb->get_state();

		$this->assertSame( 'closed', $state['state'] );
		$this->assertSame( 0, $state['failure_count'] );
	}

	public function test_get_state_parses_stored_json() {
		$this->set_circuit_state( 'open', 5, time() - 100 );

		$cb    = new Kunaki_Circuit_Breaker();
		$state = $cb->get_state();

		$this->assertSame( 'open', $state['state'] );
		$this->assertSame( 5, $state['failure_count'] );
	}

	public function test_get_state_handles_corrupt_json() {
		$this->option_store[ Kunaki_Circuit_Breaker::OPTION_KEY ] = 'not json{{{';

		$cb    = new Kunaki_Circuit_Breaker();
		$state = $cb->get_state();

		$this->assertSame( 'closed', $state['state'] );
		$this->assertSame( 0, $state['failure_count'] );
	}

	// ── pre_request() ─────────────────────────────────────────────────

	public function test_pre_request_allows_when_closed() {
		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->pre_request() );
	}

	public function test_pre_request_blocks_when_open_within_cooldown() {
		$this->set_circuit_state( 'open', 5, time() - 60 ); // 60s ago, cooldown 5min

		$cb     = new Kunaki_Circuit_Breaker();
		$result = $cb->pre_request();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kunaki_circuit_open', $result->get_error_code() );
	}

	public function test_pre_request_transitions_open_to_half_open_after_cooldown() {
		$this->set_circuit_state( 'open', 5, time() - 400 ); // 400s ago, cooldown 300s

		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->pre_request() );

		$this->assertNotEmpty( $this->updated_options );
		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'half_open', $saved['state'] );
	}

	public function test_pre_request_allows_when_half_open() {
		$this->set_circuit_state( 'half_open', 5, time() - 400 );

		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->pre_request() );
	}

	public function test_pre_request_uses_configured_cooldown() {
		// 10 min cooldown, opened 8 min ago → still blocked
		$this->option_store['kunaki_wc_circuit_cooldown'] = 10;
		$this->set_circuit_state( 'open', 5, time() - 480 );

		$cb     = new Kunaki_Circuit_Breaker();
		$result = $cb->pre_request();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_pre_request_invalid_cooldown_defaults_to_five() {
		// cooldown = 0 → falls back to 5 min, opened 6 min ago → should transition
		$this->option_store['kunaki_wc_circuit_cooldown'] = 0;
		$this->set_circuit_state( 'open', 5, time() - 360 );

		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->pre_request() );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'half_open', $saved['state'] );
	}

	// ── post_request() success ────────────────────────────────────────

	public function test_success_skips_write_when_already_clean() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( simplexml_load_string( '<R><ErrorCode>0</ErrorCode></R>' ) );

		$this->assertArrayNotHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_success_resets_failure_count_when_closed() {
		$this->set_circuit_state( 'closed', 3 );

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( simplexml_load_string( '<R><ErrorCode>0</ErrorCode></R>' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'closed', $saved['state'] );
		$this->assertSame( 0, $saved['failure_count'] );
	}

	public function test_success_closes_circuit_from_half_open() {
		$this->set_circuit_state( 'half_open', 5, time() - 400 );

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( simplexml_load_string( '<R><ErrorCode>0</ErrorCode></R>' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'closed', $saved['state'] );
		$this->assertSame( 0, $saved['failure_count'] );
	}

	// ── post_request() failure ────────────────────────────────────────

	public function test_failure_increments_count_below_threshold() {
		$this->set_circuit_state( 'closed', 2 );

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'closed', $saved['state'] );
		$this->assertSame( 3, $saved['failure_count'] );
	}

	public function test_failure_trips_circuit_at_threshold() {
		$this->set_circuit_state( 'closed', 4 ); // Will become 5 = threshold

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_http_error', 'HTTP 500' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'open', $saved['state'] );
		$this->assertSame( 5, $saved['failure_count'] );
		$this->assertGreaterThan( 0, $saved['opened_at'] );
	}

	public function test_failure_in_half_open_reopens_circuit() {
		$this->set_circuit_state( 'half_open', 5, time() - 400 );

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_xml_error', 'Parse failed' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'open', $saved['state'] );
	}

	public function test_api_business_error_does_not_trip_circuit() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_api_error', 'Invalid Product', array( 'error_code' => 1 ) ) );

		$this->assertArrayNotHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_api_business_error_does_not_reset_failures() {
		$this->set_circuit_state( 'closed', 3 );

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_api_error', 'Bad request', array( 'error_code' => 2 ) ) );

		$this->assertArrayNotHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_uses_configured_threshold() {
		$this->option_store['kunaki_wc_circuit_threshold'] = 10;
		$this->set_circuit_state( 'closed', 9 ); // Will become 10 = custom threshold

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'open', $saved['state'] );
		$this->assertSame( 10, $saved['failure_count'] );
	}

	public function test_below_configured_threshold_stays_closed() {
		$this->option_store['kunaki_wc_circuit_threshold'] = 10;
		$this->set_circuit_state( 'closed', 7 ); // Will become 8, threshold is 10

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'closed', $saved['state'] );
		$this->assertSame( 8, $saved['failure_count'] );
	}

	public function test_invalid_threshold_defaults_to_five() {
		$this->option_store['kunaki_wc_circuit_threshold'] = 0;
		$this->set_circuit_state( 'closed', 4 ); // Will become 5 = fallback threshold

		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$saved = json_decode( $this->updated_options[ Kunaki_Circuit_Breaker::OPTION_KEY ], true );
		$this->assertSame( 'open', $saved['state'] );
	}

	// ── is_available() ────────────────────────────────────────────────

	public function test_is_available_true_when_closed() {
		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->is_available() );
	}

	public function test_is_available_true_when_half_open() {
		$this->set_circuit_state( 'half_open', 5, time() - 400 );

		$cb = new Kunaki_Circuit_Breaker();
		$this->assertTrue( $cb->is_available() );
	}

	public function test_is_available_false_when_open() {
		$this->set_circuit_state( 'open', 5, time() - 60 );

		$cb = new Kunaki_Circuit_Breaker();
		$this->assertFalse( $cb->is_available() );
	}

	// ── reset() ───────────────────────────────────────────────────────

	public function test_reset_deletes_option() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->reset();

		$this->assertContains( Kunaki_Circuit_Breaker::OPTION_KEY, $this->deleted_options );
	}

	// ── Error classification ──────────────────────────────────────────

	public function test_http_request_failed_is_trippable() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'http_request_failed', 'Timeout' ) );

		$this->assertArrayHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_kunaki_http_error_is_trippable() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_http_error', 'HTTP 503' ) );

		$this->assertArrayHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_kunaki_xml_error_is_trippable() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_xml_error', 'Parse failed' ) );

		$this->assertArrayHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	public function test_kunaki_api_error_is_not_trippable() {
		$cb = new Kunaki_Circuit_Breaker();
		$cb->post_request( new WP_Error( 'kunaki_api_error', 'Invalid Product' ) );

		$this->assertArrayNotHasKey( Kunaki_Circuit_Breaker::OPTION_KEY, $this->updated_options );
	}

	// ── Helpers ───────────────────────────────────────────────────────

	private function wire_mocks() {
		$store   = &$this->option_store;
		$updated = &$this->updated_options;
		$deleted = &$this->deleted_options;

		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) use ( &$store ) {
			if ( 'kunaki_wc_debug_log' === $key ) {
				return 'no';
			}
			if ( array_key_exists( $key, $store ) ) {
				return $store[ $key ];
			}
			return $default;
		} );

		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updated, &$store ) {
			$updated[ $key ] = $value;
			$store[ $key ]   = $value;
			return true;
		} );

		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted, &$store ) {
			$deleted[] = $key;
			unset( $store[ $key ] );
			return true;
		} );

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}

	private function set_circuit_state( $state, $failure_count, $opened_at = 0 ) {
		$this->option_store[ Kunaki_Circuit_Breaker::OPTION_KEY ] = wp_json_encode( array(
			'state'           => $state,
			'failure_count'   => $failure_count,
			'last_failure_at' => $opened_at ?: 0,
			'opened_at'       => $opened_at ?: 0,
		) );
	}
}
