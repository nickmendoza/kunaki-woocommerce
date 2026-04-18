<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Alerts;
use WP_Error;

class AlertsTest extends KunakiTestCase {

	/** @var array Option store: key => value. */
	private $options = array();

	/** @var array Mocked wp_options rows used for the atomic debounce claim. */
	private $claim_rows = array();

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

	/** @var int Response code for wp_remote_retrieve_response_code. */
	private $remote_status = 200;

	/** @var array Captured wp_schedule_single_event calls. */
	private $scheduled_events = array();

	/** @var bool If true, wp_schedule_single_event immediately invokes execute_send. */
	private $schedule_runs_inline = true;

	protected function set_up() {
		parent::set_up();
		$this->options              = array();
		$this->claim_rows           = array();
		$this->mail_calls           = array();
		$this->mail_count           = 0;
		$this->mail_return          = true;
		$this->remote_calls         = array();
		$this->remote_return        = array( 'response' => array( 'code' => 200 ) );
		$this->remote_status        = 200;
		$this->scheduled_events     = array();
		$this->schedule_runs_inline = true;

		$this->wire_mocks();
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	// ── Master toggle ─────────────────────────────────────────────────

	public function test_master_disabled_sends_nothing() {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );
		$alerts->handle_circuit_closed( array() );
		$alerts->handle_retry_exhausted( 42, 3, 'boom' );

		$this->assertSame( 0, $this->mail_count );
		$this->assertCount( 0, $this->remote_calls );
		$this->assertCount( 0, $this->scheduled_events );
	}

	public function test_all_channels_disabled_does_not_send() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'no';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		// dispatch() still schedules the cron (claim succeeded), but execute_send
		// no-ops because every channel is disabled.
		$this->assertSame( 0, $this->mail_count );
		$this->assertCount( 0, $this->remote_calls );
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
		$this->assertCount( 0, $this->remote_calls );
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
		$this->assertCount( 1, $this->remote_calls );
	}

	// ── Slack channel ─────────────────────────────────────────────────

	public function test_circuit_opened_sends_slack_only() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 0, $this->mail_count );
		$this->assertCount( 1, $this->remote_calls );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/XYZ', $this->remote_calls[0]['url'] );
		$this->assertSame( 0, $this->remote_calls[0]['args']['redirection'] );

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

		$this->assertCount( 0, $this->remote_calls );
	}

	public function test_slack_non_2xx_response_is_logged() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );
		$this->remote_status = 500;

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertCount( 1, $this->remote_calls );
	}

	public function test_slack_transport_error_is_logged() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->enable_slack( 'https://hooks.slack.com/services/T/B/XYZ' );
		$this->remote_return = new WP_Error( 'http_request_failed', 'Timeout' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertCount( 1, $this->remote_calls );
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

	public function test_retry_exhausted_truncates_long_error_text() {
		$this->enable_alerts( 'alerts@example.com' );
		$long_error = str_repeat( 'x', 5000 );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 300, 3, $long_error );

		$this->assertSame( 1, $this->mail_count );
		$this->assertLessThan( 2000, strlen( $this->mail_calls[0]['body'] ) );
		$this->assertStringContainsString( '…', $this->mail_calls[0]['body'] );
	}

	public function test_retry_exhausted_strips_control_characters() {
		$this->enable_alerts( 'alerts@example.com' );
		$nasty = "line1\x00null\x1Fescape";

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 400, 3, $nasty );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringNotContainsString( "\x00", $this->mail_calls[0]['body'] );
		$this->assertStringNotContainsString( "\x1F", $this->mail_calls[0]['body'] );
		$this->assertStringContainsString( 'line1nullescape', $this->mail_calls[0]['body'] );
	}

	// ── Slack webhook defense-in-depth ────────────────────────────────

	public function test_slack_send_blocks_non_slack_webhook_at_send_time() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		// Simulate a stored value that somehow bypassed the settings filter
		// (direct SQL, import from another site, WP-CLI without the filter loaded).
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_SLACK_WEBHOOK ] = 'https://evil.example.com/exfiltrate';

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertCount( 0, $this->remote_calls, 'wp_remote_post must not be called for non-slack hosts' );
	}

	// ── Atomic debounce ───────────────────────────────────────────────

	public function test_circuit_opened_debounces_within_window() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, $this->mail_count );
		$this->assertCount( 1, $this->scheduled_events, 'second concurrent fire must not re-schedule' );
	}

	public function test_retry_exhausted_debounces_per_order() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_retry_exhausted( 100, 3, 'err' );
		$alerts->handle_retry_exhausted( 101, 3, 'err' );
		$alerts->handle_retry_exhausted( 100, 3, 'err' ); // Duplicate for 100 is debounced.

		$this->assertSame( 2, $this->mail_count );
	}

	public function test_expired_claim_is_cleared_allowing_new_send() {
		$this->enable_alerts( 'alerts@example.com' );

		// Seed an expired claim row so INSERT IGNORE can reacquire after the cleanup step.
		$this->claim_rows[ Kunaki_Alerts::CLAIM_PREFIX . 'circuit_opened' ] = (string) ( time() - 3600 );

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		$this->assertSame( 1, $this->mail_count );
	}

	// ── Async dispatch ────────────────────────────────────────────────

	public function test_handler_defers_to_cron_instead_of_sending_inline() {
		$this->enable_alerts( 'alerts@example.com' );
		$this->schedule_runs_inline = false; // Capture scheduled event without firing send.

		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened( array( 'failure_count' => 5 ) );

		// The handler must return without doing any I/O — Slack and email happen on the cron.
		$this->assertSame( 0, $this->mail_count );
		$this->assertCount( 0, $this->remote_calls );

		// It scheduled the send for later.
		$this->assertCount( 1, $this->scheduled_events );
		$this->assertSame( Kunaki_Alerts::CRON_SEND_ALERT, $this->scheduled_events[0]['hook'] );
		$this->assertSame( 'circuit_opened', $this->scheduled_events[0]['args'][0] );
		$this->assertArrayHasKey( 'subject', $this->scheduled_events[0]['args'][1] );
	}

	public function test_execute_send_drops_invalid_payload() {
		$this->enable_alerts( 'alerts@example.com' );

		$alerts = new Kunaki_Alerts();
		$alerts->execute_send( 'circuit_opened', 'not-an-array' );

		$this->assertSame( 0, $this->mail_count );
	}

	public function test_execute_send_sends_with_valid_payload() {
		$this->enable_alerts( 'alerts@example.com' );

		$payload = array(
			'subject'      => '[Test] Subject',
			'body_text'    => 'body',
			'slack_title'  => 'title',
			'slack_fields' => array(),
			'severity'     => Kunaki_Alerts::SEVERITY_DANGER,
		);

		$alerts = new Kunaki_Alerts();
		$alerts->execute_send( 'circuit_opened', $payload );

		$this->assertSame( 1, $this->mail_count );
		$this->assertSame( '[Test] Subject', $this->mail_calls[0]['subject'] );
	}

	// ── Hook registration ─────────────────────────────────────────────

	public function test_init_registers_action_hooks() {
		$expect_callback = function ( $method ) {
			return Mockery::on( function ( $callback ) use ( $method ) {
				return is_array( $callback )
					&& isset( $callback[0], $callback[1] )
					&& $callback[0] instanceof Kunaki_Alerts
					&& $callback[1] === $method;
			} );
		};

		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_circuit_breaker_opened', $expect_callback( 'handle_circuit_opened' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_circuit_breaker_closed', $expect_callback( 'handle_circuit_closed' ), 10, 1 );
		Functions\expect( 'add_action' )
			->once()->with( 'kunaki_wc_order_retry_exhausted', $expect_callback( 'handle_retry_exhausted' ), 10, 3 );
		Functions\expect( 'add_action' )
			->once()->with( Kunaki_Alerts::CRON_SEND_ALERT, $expect_callback( 'execute_send' ), 10, 2 );

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
		$options   = &$this->options;
		$claim_rows = &$this->claim_rows;
		$calls     = &$this->mail_calls;
		$count     = &$this->mail_count;
		$ret       = &$this->mail_return;
		$remote    = &$this->remote_calls;
		$r_return  = &$this->remote_return;
		$r_status  = &$this->remote_status;
		$sched     = &$this->scheduled_events;
		$inline    = &$this->schedule_runs_inline;
		$test      = $this;

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

		Functions\when( 'wp_schedule_single_event' )->alias( function ( $timestamp, $hook, $args = array() ) use ( &$sched, &$inline ) {
			$sched[] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args );
			if ( $inline && 'kunaki_wc_send_alert' === $hook ) {
				// Invoke the cron handler synchronously so existing assertions
				// (which inspect mail/remote state) remain meaningful.
				$alerts = new Kunaki_Alerts();
				$alerts->execute_send( $args[0], $args[1] );
			}
			return true;
		} );

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);

		// Mock wpdb for the atomic debounce claim INSERT IGNORE.
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query, ...$args ) {
			return array( 'query' => $query, 'args' => $args );
		} );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $prepared ) use ( &$claim_rows ) {
			$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
			$args  = is_array( $prepared ) ? $prepared['args'] : array();

			if ( false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, 'option_value <' ) ) {
				$key     = $args[0];
				$cutoff  = $args[1];
				if ( isset( $claim_rows[ $key ] ) && $claim_rows[ $key ] < $cutoff ) {
					unset( $claim_rows[ $key ] );
					return 1;
				}
				return 0;
			}

			if ( false !== strpos( $query, 'INSERT IGNORE' ) ) {
				$key    = $args[0];
				$expiry = $args[1];
				if ( isset( $claim_rows[ $key ] ) ) {
					return 0; // Row exists — INSERT IGNORE does nothing.
				}
				$claim_rows[ $key ] = $expiry;
				return 1;
			}

			return 0;
		} );
		$GLOBALS['wpdb'] = $wpdb;
	}
}
