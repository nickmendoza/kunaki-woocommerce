<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Alerts;
use WP_Error;

class AlertsTest extends KunakiTestCase {

	/** @var array Option store: key => value. */
	private $options = array();

	/** @var array Transient store: key => value (values reset between tests). */
	private $transients = array();

	/** @var array Captured wp_mail calls. */
	private $mail_calls = array();

	/** @var int Number of times wp_mail was invoked. */
	private $mail_count = 0;

	/** @var bool Return value for wp_mail. */
	private $mail_return = true;

	/** @var array Captured wp_remote_post calls. */
	private $remote_calls = array();

	/** @var mixed Return value for wp_remote_post. */
	private $remote_return = null;

	/** @var int|WP_Error Response code for wp_remote_retrieve_response_code. */
	private $remote_status = 200;

	protected function set_up() {
		parent::set_up();
		$this->options       = array();
		$this->transients    = array();
		$this->mail_calls    = array();
		$this->mail_count    = 0;
		$this->mail_return   = true;
		$this->remote_calls  = array();
		$this->remote_return = array( 'response' => array( 'code' => 200 ) );
		$this->remote_status = 200;

		$this->wire_mocks();
	}

	// ── Master toggle ─────────────────────────────────────────────────

	public function test_master_disabled_sends_nothing() {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );
		$alerts->handle_circuit_closed( array() );
		$alerts->handle_retry_exhausted( 42, 3, 'boom' );

		$this->assertSame( 0, $this->mail_count );
		$this->assertSame( 0, count( $this->remote_calls ) );
	}

	public function test_all_channels_disabled_sends_nothing() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'no';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 0, $this->mail_count );
		$this->assertSame( 0, count( $this->remote_calls ) );
	}

	// ── Email channel ─────────────────────────────────────────────────

	public function test_circuit_opened_sends_email_only() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, $this->mail_count );
		$this->assertSame( 'alerts@example.com', $this->mail_calls[0]['to'] );
		$this->assertStringContainsString( 'Kunaki API outage detected', $this->mail_calls[0]['subject'] );
		$this->assertStringContainsString( '5 consecutive', $this->mail_calls[0]['body'] );
		$this->assertSame( 0, count( $this->remote_calls ) );
	}

	public function test_empty_email_falls_back_to_admin_email() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = '';
		$this->options['admin_email']                      = 'admin@example.com';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_closed( array() );

		$this->assertSame( 1, $this->mail_count );
		$this->assertSame( 'admin@example.com', $this->mail_calls[0]['to'] );
	}

	public function test_invalid_email_and_no_admin_skips_send() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = 'not-an-email';
		$this->options['admin_email']                      = '';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_closed( array() );

		$this->assertSame( 0, $this->mail_count );
	}

	public function test_email_failure_does_not_prevent_slack() {
		$this->enable_alerts( 'alerts@example.com' );
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );
		$this->mail_return = false;

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, $this->mail_count );
		$this->assertSame( 1, count( $this->remote_calls ) );
	}

	// ── Slack channel ─────────────────────────────────────────────────

	public function test_circuit_opened_sends_slack_only() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 0, $this->mail_count );
		$this->assertSame( 1, count( $this->remote_calls ) );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/XYZ', $this->remote_calls[0]['url'] );

		$payload = json_decode( $this->remote_calls[0]['args']['body'], true );
		$this->assertSame( 'danger', $payload['attachments'][0]['color'] );
		$this->assertStringContainsString( 'outage', $payload['text'] );
	}

	public function test_circuit_closed_slack_uses_good_color() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_closed( array() );

		$payload = json_decode( $this->remote_calls[0]['args']['body'], true );
		$this->assertSame( 'good', $payload['attachments'][0]['color'] );
		$this->assertStringContainsString( 'recovered', $payload['text'] );
	}

	public function test_slack_enabled_but_empty_webhook_skips_post() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_SLACK_WEBHOOK ] = '';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 0, count( $this->remote_calls ) );
	}

	public function test_slack_non_2xx_response_is_logged() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );
		$this->remote_status = 500;

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		// The request was attempted; no exception.
		$this->assertSame( 1, count( $this->remote_calls ) );
	}

	public function test_slack_transport_error_is_logged() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );
		$this->remote_return = new WP_Error( 'http_request_failed', 'Timeout' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, count( $this->remote_calls ) );
	}

	// ── Retry exhausted ───────────────────────────────────────────────

	public function test_retry_exhausted_email_body_contains_order_context() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 100, 3, 'HTTP 500 from Kunaki' );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringContainsString( '#100', $this->mail_calls[0]['subject'] );
		$this->assertStringContainsString( 'HTTP 500 from Kunaki', $this->mail_calls[0]['body'] );
		$this->assertStringContainsString( 'post.php?post=100', $this->mail_calls[0]['body'] );
	}

	public function test_retry_exhausted_empty_error_shows_placeholder() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 200, 3, '' );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringContainsString( '(not recorded)', $this->mail_calls[0]['body'] );
	}

	// ── Debounce ──────────────────────────────────────────────────────

	public function test_circuit_opened_debounces_within_window() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, $this->mail_count );
	}

	public function test_retry_exhausted_debounces_per_order() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 100, 3, 'err' );
		$alerts->handle_retry_exhausted( 101, 3, 'err' );
		$alerts->handle_retry_exhausted( 100, 3, 'err' ); // Duplicate for 100 is debounced.

		$this->assertSame( 2, $this->mail_count );
	}

	// ── Hook registration ─────────────────────────────────────────────

	public function test_init_registers_three_action_hooks() {
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_circuit_breaker_opened', Mockery::type( 'array' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_circuit_breaker_closed', Mockery::type( 'array' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_order_retry_exhausted', Mockery::type( 'array' ), 10, 3 );

		$alerts = new Kunaki_Alerts();
		$alerts->init();
	}

	// ── Helpers ───────────────────────────────────────────────────────

	private function enable_alerts( $email = '' ) {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ]       = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = $email;
	}

	private function enable_slack( $webhook ) {
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_SLACK_WEBHOOK ] = $webhook;
	}

	private function wire_mocks() {
		$options  = &$this->options;
		$trans    = &$this->transients;
		$calls    = &$this->mail_calls;
		$count    = &$this->mail_count;
		$ret      = &$this->mail_return;
		$remote   = &$this->remote_calls;
		$r_return = &$this->remote_return;
		$r_status = &$this->remote_status;

		Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) use ( &$options ) {
			if ( array_key_exists( $key, $options ) ) {
				return $options[ $key ];
			}
			return $default;
		} );

		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$options ) {
			$options[ $key ] = $value;
			return true;
		} );

		Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$trans ) {
			return array_key_exists( $key, $trans ) ? $trans[ $key ] : false;
		} );

		Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl ) use ( &$trans ) {
			$trans[ $key ] = $value;
			return true;
		} );

		Functions\when( 'is_email' )->alias( function ( $email ) {
			return is_string( $email ) && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
		} );

		Functions\when( 'get_bloginfo' )->alias( function ( $field ) {
			return 'name' === $field ? 'Test Store' : '';
		} );

		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
		} );

		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) use ( &$calls, &$count, &$ret ) {
			$calls[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
			$count++;
			return $ret;
		} );

		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args = array() ) use ( &$remote, &$r_return ) {
			$remote[] = array( 'url' => $url, 'args' => $args );
			return $r_return;
		} );

		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) use ( &$r_status ) {
			return $r_status;
		} );

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}
}
