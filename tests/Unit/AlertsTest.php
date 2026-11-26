<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Alerts;

class AlertsTest extends KunakiTestCase {

	/** @var array Option store. */
	private $options = array();

	/** @var array Rows written with raw SQL (the outage flag). */
	private $rows = array();

	/** @var array Captured wp_mail calls. */
	private $mails = array();

	/** @var bool|callable What wp_mail returns. */
	private $mail_return = true;

	/** @var array Captured wp_remote_post calls. */
	private $posts = array();

	/** @var int Slack response status. */
	private $slack_status = 200;

	/** @var bool Whether the Slack request itself fails (timeout, DNS). */
	private $slack_error = false;

	/** @var bool Whether the Slack request throws, as a filter on it might. */
	private $slack_throws = false;

	/** @var bool Whether queueing a job throws. */
	private $enqueue_throws = false;

	/** @var array Queued Action Scheduler jobs: [ 'hook', 'args', 'group', 'when' ]. */
	private $jobs = array();

	/** @var int What as_enqueue_async_action returns. */
	private $enqueue_return = 123;

	/** @var int What as_schedule_single_action returns. */
	private $schedule_return = 456;

	/** @var string[] Captured log lines. */
	private $logs = array();

	/** @var array Orders returned by wc_get_order, by ID. */
	private $orders = array();

	protected function set_up() {
		parent::set_up();
		$this->options         = array();
		$this->rows            = array();
		$this->mails           = array();
		$this->mail_return     = true;
		$this->posts           = array();
		$this->slack_status    = 200;
		$this->slack_error     = false;
		$this->slack_throws    = false;
		$this->enqueue_throws  = false;
		$this->jobs            = array();
		$this->enqueue_return  = 123;
		$this->schedule_return = 456;
		$this->logs            = array();
		$this->orders          = array();
		$this->wire_mocks();
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	// ── Master switch ──────────────────────────────────────────────────

	public function test_nothing_is_queued_or_sent_when_alerts_are_off() {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';
		$this->add_order( 42 );
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$alerts->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_DECLINED );
		$alerts->handle_password_decrypt_failed();

		$this->assertSame( array(), $this->jobs );
		$this->assertSame( array(), $this->rows, 'An outage alerts are off for must not set the flag.' );
		$this->run_jobs();
		$this->assertSame( array(), $this->mails );
	}

	public function test_alert_turned_off_after_queueing_is_not_sent() {
		$this->enable_alerts();
		( new Kunaki_Alerts() )->handle_circuit_opened();
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';

		$this->run_jobs();

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->rows, 'Nobody was told, so the outage must not count as reported.' );
	}

	public function test_no_channel_enabled_sends_nothing() {
		$this->enable_alerts();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';

		( new Kunaki_Alerts() )->handle_circuit_opened();
		$this->run_jobs();

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->posts );
		$this->assertSame( array(), $this->rows, 'Nobody was told, so the outage must not count as reported.' );
	}

	// ── Background delivery ────────────────────────────────────────────

	public function test_alert_is_queued_in_the_background_with_small_positional_args() {
		$this->enable_alerts();
		$this->add_order( 42 );

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );

		$this->assertSame( array(), $this->mails, 'Nothing is sent inside the triggering request.' );
		$this->assertCount( 1, $this->jobs );
		$this->assertSame( Kunaki_Alerts::SEND_ACTION, $this->jobs[0]['hook'] );
		$this->assertSame( array( 'order_needs_attention', 42, 'permanent_failure' ), $this->jobs[0]['args'] );
		$this->assertSame( 'kunaki-woocommerce', $this->jobs[0]['group'] );
	}

	public function test_sends_immediately_when_the_job_cannot_be_queued() {
		$this->enable_alerts();
		$this->enqueue_return = 0;

		( new Kunaki_Alerts() )->handle_circuit_opened();

		$this->assertCount( 1, $this->mails );
	}

	// ── Outage alerts ──────────────────────────────────────────────────

	public function test_outage_sends_one_email() {
		$this->enable_alerts( 'alerts@example.com' );

		( new Kunaki_Alerts() )->handle_circuit_opened();
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'alerts@example.com', $this->mails[0]['to'] );
		$this->assertSame( '[Test Store] Kunaki API outage detected', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'every 5 minutes', $this->mails[0]['body'] );
		$this->assertSame( array(), $this->posts );
	}

	public function test_repeated_trips_in_one_outage_alert_once() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$alerts->handle_circuit_opened(); // A failed recovery check re-opens the circuit.
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
	}

	public function test_stale_outage_flag_does_not_silence_a_new_outage() {
		$this->enable_alerts();
		$this->rows[ Kunaki_Alerts::OUTAGE_FLAG ] = (string) ( time() - DAY_IN_SECONDS - 1 );

		( new Kunaki_Alerts() )->handle_circuit_opened();
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
	}

	public function test_recovery_without_an_outage_alert_sends_nothing() {
		$this->enable_alerts();

		( new Kunaki_Alerts() )->handle_circuit_closed();
		$this->run_jobs();

		$this->assertSame( array(), $this->jobs );
		$this->assertSame( array(), $this->mails );
	}

	public function test_recovery_is_reported_once_the_api_stays_up() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();

		$this->assertCount( 1, $this->jobs );
		$this->assertGreaterThanOrEqual( time() + Kunaki_Alerts::RECOVERY_DELAY, $this->jobs[0]['when'] );
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
		$this->assertSame( '[Test Store] Kunaki API recovered', $this->mails[1]['subject'] );
		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );
	}

	public function test_recovery_is_reported_at_once_when_the_check_cannot_be_queued() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->schedule_return = 0;
		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();

		$this->assertCount( 2, $this->mails, 'Reported now, because nothing would report it later.' );
		$this->assertSame( '[Test Store] Kunaki API recovered', $this->mails[1]['subject'] );
		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );
	}

	public function test_flapping_api_sends_one_outage_and_one_recovery() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();          // Down.
		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();          // Up: recovery check queued.
		$this->set_circuit( 'open' );
		$alerts->handle_circuit_opened();          // Down again before the check.
		$this->run_jobs();                         // The check finds it down.

		$this->assertCount( 1, $this->mails, 'Only the outage alert so far.' );
		$this->assertArrayHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );

		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();
		$alerts->handle_circuit_closed();          // Two recoveries queue two checks.
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'recovered', $this->mails[1]['subject'] );

		$alerts->handle_circuit_opened();          // A new outage alerts again.
		$this->run_jobs();
		$this->assertCount( 3, $this->mails );
	}

	public function test_undelivered_outage_alert_is_sent_again_at_the_next_failed_check() {
		$this->enable_alerts();
		$this->mail_return = false;
		$alerts            = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows, 'An alert nobody received must not count as sent.' );

		$this->mail_return = true;
		$alerts->handle_circuit_opened();          // The next failed recovery check.
		$this->run_jobs();

		$this->assertCount( 2, $this->mails, 'One failed attempt, then the alert.' );
		$this->assertArrayHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );

		$alerts->handle_circuit_opened();          // Delivered now, so later checks stay quiet.
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
	}

	/**
	 * @dataProvider one_channel_failing
	 */
	public function test_outage_alert_that_reached_one_channel_counts_as_sent( $mail_ok, $slack_status ) {
		$this->enable_slack();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = 'alerts@example.com';
		$this->mail_return  = $mail_ok;
		$this->slack_status = $slack_status;
		$alerts             = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$this->run_jobs();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Both channels were tried once.' );
		$this->assertCount( 1, $this->posts, 'The second trip of the same outage stays quiet.' );
		$this->assertArrayHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );
	}

	public function one_channel_failing() {
		return array(
			'email fails, Slack delivers' => array( false, 200 ),
			'email delivers, Slack fails' => array( true, 500 ),
		);
	}

	public function test_recovery_is_not_reported_for_an_outage_nobody_was_told_about() {
		$this->enable_alerts();
		$this->mail_return = false;
		$alerts            = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->mail_return = true;
		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Only the failed outage attempt.' );
	}

	public function test_recovery_while_alerts_are_off_forgets_the_outage() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';
		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();

		$this->assertSame( array(), $this->jobs, 'No recovery is reported while alerts are off.' );
		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );

		// Turned back on the same day: the next outage must alert, not only its recovery.
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'yes';
		$this->set_circuit( 'open' );
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'outage', $this->mails[1]['subject'] );
	}

	public function test_recovery_check_that_runs_with_alerts_off_forgets_the_outage() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();
		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();          // Recovery check queued.

		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Only the outage alert.' );
		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );
	}

	public function test_alerts_turned_off_and_on_during_an_outage_still_report_its_recovery() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();
		$alerts->handle_circuit_opened();
		$this->run_jobs();

		// Nothing recovers while alerts are off, so the outage stays flagged.
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';
		$alerts->handle_circuit_opened();          // A failed recovery check.
		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'yes';

		$this->set_circuit( 'closed' );
		$alerts->handle_circuit_closed();
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'recovered', $this->mails[1]['subject'] );
	}

	public function test_undelivered_order_alert_leaves_the_outage_flag_alone() {
		$this->enable_alerts();
		$this->rows[ Kunaki_Alerts::OUTAGE_FLAG ] = (string) time();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_DECLINED );

		$this->assertArrayHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows );
	}

	// ── Order alerts ───────────────────────────────────────────────────

	public function test_retries_exhausted_email_has_attempts_error_and_link() {
		$this->enable_alerts();
		$this->add_order( 42, array(
			'_kunaki_fulfillment_attempts'   => 3,
			'_kunaki_fulfillment_last_error' => 'Kunaki error: invalid product',
		) );

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( '[Test Store] Order #1042 could not be sent to Kunaki', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'after 3 attempts', $this->mails[0]['body'] );
		$this->assertStringContainsString( 'Kunaki error: invalid product', $this->mails[0]['body'] );
		$this->assertStringContainsString( 'https://example.test/edit/42', $this->mails[0]['body'] );
	}

	public function test_permanent_failure_email_names_the_problem() {
		$this->enable_alerts();
		$this->add_order( 42, array( '_kunaki_fulfillment_last_error' => 'No Kunaki shipping method on order.' ) );

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );
		$this->run_jobs();

		$this->assertSame( "[Test Store] Order #1042 can't be sent to Kunaki", $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'No Kunaki shipping method on order.', $this->mails[0]['body'] );
	}

	public function test_declined_email_explains_resubmission_is_billed_separately() {
		$this->enable_alerts();
		// Status sync puts a declined order on hold before raising the alert.
		$this->add_order( 42, array( '_kunaki_order_id' => 'KUN777' ), 'on-hold' );

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_DECLINED );
		$this->run_jobs();

		$this->assertSame( '[Test Store] Kunaki declined order #1042', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Kunaki order KUN777', $this->mails[0]['body'] );
		$this->assertStringContainsString( 'billed separately', $this->mails[0]['body'] );
	}

	public function test_every_order_gets_its_own_alert() {
		$this->enable_alerts();
		$this->add_order( 1 );
		$this->add_order( 2 );
		$alerts = new Kunaki_Alerts();

		$alerts->handle_order_needs_attention( 1, Kunaki_Alerts::REASON_DECLINED );
		$alerts->handle_order_needs_attention( 2, Kunaki_Alerts::REASON_DECLINED );
		$this->run_jobs();

		$this->assertCount( 2, $this->mails );
	}

	public function test_unknown_reason_or_bad_order_id_is_ignored() {
		$this->enable_alerts();
		$alerts = new Kunaki_Alerts();

		$alerts->handle_order_needs_attention( 0, Kunaki_Alerts::REASON_DECLINED );
		$alerts->handle_order_needs_attention( 42, 'something_else' );

		$this->assertSame( array(), $this->jobs );
	}

	public function test_order_dealt_with_before_sending_is_not_alerted() {
		$this->enable_alerts();
		// The admin retried both before the jobs ran: exhausted flag and DECLINED status cleared.
		$this->add_order( 1, array( '_kunaki_fulfillment_exhausted' => '' ) );
		$this->add_order( 2, array( '_kunaki_order_status' => '' ) );
		$alerts = new Kunaki_Alerts();

		$alerts->execute_send( Kunaki_Alerts::EVENT_ORDER, 1, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$alerts->execute_send( Kunaki_Alerts::EVENT_ORDER, 1, Kunaki_Alerts::REASON_PERMANENT_FAILURE );
		$alerts->execute_send( Kunaki_Alerts::EVENT_ORDER, 2, Kunaki_Alerts::REASON_DECLINED );

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * @dataProvider orders_nobody_can_retry
	 */
	public function test_order_cancelled_or_refunded_before_sending_is_not_alerted( $reason, $status ) {
		$this->enable_alerts();
		// Still marked as failed or declined, but there is nothing left to retry.
		$this->add_order( 42, array(), $status );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, $reason );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->jobs );
	}

	public function orders_nobody_can_retry() {
		// The same statuses the dashboard leaves out of "Action needed".
		return array(
			'out of retries, cancelled'   => array( Kunaki_Alerts::REASON_RETRIES_EXHAUSTED, 'cancelled' ),
			'permanent failure, refunded' => array( Kunaki_Alerts::REASON_PERMANENT_FAILURE, 'refunded' ),
			'permanent failure, on hold'  => array( Kunaki_Alerts::REASON_PERMANENT_FAILURE, 'on-hold' ),
			'declined, cancelled'         => array( Kunaki_Alerts::REASON_DECLINED, 'cancelled' ),
			'declined, refunded'          => array( Kunaki_Alerts::REASON_DECLINED, 'refunded' ),
		);
	}

	public function test_declined_order_moved_back_to_processing_is_still_alerted() {
		$this->enable_alerts();
		// Back in Processing but not retried yet, so it is still declined.
		$this->add_order( 42, array(), 'processing' );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_DECLINED );

		$this->assertCount( 1, $this->mails );
	}

	public function test_order_cancelled_before_a_resend_is_not_alerted() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );
		$this->run_jobs();

		// Mail works again, but the admin cancelled the order before the resend ran.
		$this->mail_return = true;
		$this->add_order( 42, array(), 'cancelled' );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Only the failed first attempt.' );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_order_deleted_before_sending_is_dropped() {
		$this->enable_alerts();

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 999, Kunaki_Alerts::REASON_DECLINED );

		$this->assertSame( array(), $this->mails );
	}

	public function test_undelivered_order_alert_is_sent_again_later() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		$this->assertCount( 1, $this->jobs, 'Nothing else would bring this alert back.' );
		$this->assertSame( Kunaki_Alerts::SEND_ACTION, $this->jobs[0]['hook'] );
		$this->assertSame( array( 'order_needs_attention', 42, 'retries_exhausted', 1 ), $this->jobs[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + Kunaki_Alerts::RESEND_DELAY, $this->jobs[0]['when'] );

		$this->mail_return = true;
		$this->run_jobs();

		$this->assertCount( 2, $this->mails, 'One failed attempt, then the alert.' );
		$this->assertSame( array(), $this->jobs, 'Delivered, so nothing more is queued.' );
	}

	public function test_undelivered_order_alert_is_given_up_on_after_the_last_resend() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_DECLINED );
		for ( $i = 0; $i < Kunaki_Alerts::MAX_RESENDS + 5; $i++ ) {
			$this->run_jobs();
		}

		$this->assertCount( 1 + Kunaki_Alerts::MAX_RESENDS, $this->mails, 'The first attempt, then each resend.' );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_undelivered_order_alert_that_cannot_be_queued_again_is_logged_as_dropped() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return     = false;
		$this->schedule_return = 0;

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_DECLINED );

		$this->assertSame( array(), $this->jobs );
		$this->assertTrue( $this->logged( 'will not be sent again' ), 'The log is the only place a dropped alert shows.' );
		$this->assertFalse( $this->logged( 'will be sent again later' ) );
	}

	public function test_order_dealt_with_before_a_resend_is_not_alerted() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		// Mail works again, but the admin retried the order before the resend ran.
		$this->mail_return = true;
		$this->add_order( 42, array( '_kunaki_fulfillment_exhausted' => '' ) );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Only the failed first attempt.' );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_resend_is_not_queued_once_alerts_are_turned_off() {
		$this->enable_alerts();
		$this->add_order( 42 );
		$this->mail_return = false;

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		$this->options[ Kunaki_Alerts::OPT_ENABLED ] = 'no';
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_missing_error_shows_placeholder() {
		$this->enable_alerts();
		$this->add_order( 42 );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );

		$this->assertStringContainsString( 'Error: (not recorded)', $this->mails[0]['body'] );
	}

	public function test_error_text_is_capped_and_stripped_of_control_characters() {
		$this->enable_alerts();
		$this->add_order( 42, array( '_kunaki_fulfillment_last_error' => "bad\x01\x1B[31m\ttext" . str_repeat( 'x', 600 ) ) );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );

		$body = $this->mails[0]['body'];
		$this->assertStringContainsString( "bad[31m\ttext", $body );
		$this->assertStringNotContainsString( "\x01", $body );
		$this->assertStringNotContainsString( str_repeat( 'x', 501 ), $body );
		$this->assertStringContainsString( '…', $body );
	}

	public function test_invalid_utf8_error_text_is_kept() {
		$this->enable_alerts();
		$this->add_order( 42, array( '_kunaki_fulfillment_last_error' => "caf\xE9\x01" ) );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );

		$this->assertStringContainsString( "caf\xE9", $this->mails[0]['body'] );
		$this->assertStringNotContainsString( "\x01", $this->mails[0]['body'] );
	}

	// ── Password alerts ────────────────────────────────────────────────

	public function test_unreadable_password_sends_one_email_with_the_settings_link() {
		$this->enable_alerts();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';

		( new Kunaki_Alerts() )->handle_password_decrypt_failed();

		$this->assertSame( array(), $this->mails, 'Nothing is sent inside the request that read the password.' );
		$this->assertCount( 1, $this->jobs );
		$this->assertSame( array( 'password_unreadable', 0, '' ), $this->jobs[0]['args'] );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( "[Test Store] Kunaki password can't be read", $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'https://example.test/wp-admin/admin.php?page=wc-settings&tab=kunaki', $this->mails[0]['body'] );
		$this->assertSame( array(), $this->jobs, 'Delivered, so nothing more is queued.' );
	}

	public function test_unreadable_password_is_posted_to_slack() {
		$this->enable_slack();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_PASSWORD );

		$payload = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertSame( "Kunaki password can't be read", $payload['text'] );
		$this->assertSame( 'danger', $payload['attachments'][0]['color'] );
		$this->assertSame( array(), $payload['attachments'][0]['fields'] );
	}

	public function test_password_saved_again_before_sending_is_not_alerted() {
		$this->enable_alerts();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';
		( new Kunaki_Alerts() )->handle_password_decrypt_failed();

		// Saving the password again clears the flag.
		unset( $this->options['kunaki_wc_decrypt_failed'] );
		$this->run_jobs();

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_undelivered_password_alert_is_sent_again_later() {
		$this->enable_alerts();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';
		$this->mail_return                         = false;

		( new Kunaki_Alerts() )->handle_password_decrypt_failed();
		$this->run_jobs();

		$this->assertCount( 1, $this->jobs, 'Nothing else would bring this alert back.' );
		$this->assertSame( array( 'password_unreadable', 0, '', 1 ), $this->jobs[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + Kunaki_Alerts::RESEND_DELAY, $this->jobs[0]['when'] );

		$this->mail_return = true;
		$this->run_jobs();

		$this->assertCount( 2, $this->mails, 'One failed attempt, then the alert.' );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_undelivered_password_alert_is_given_up_on_after_the_last_resend() {
		$this->enable_alerts();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';
		$this->mail_return                         = false;

		( new Kunaki_Alerts() )->handle_password_decrypt_failed();
		for ( $i = 0; $i < Kunaki_Alerts::MAX_RESENDS + 5; $i++ ) {
			$this->run_jobs();
		}

		$this->assertCount( 1 + Kunaki_Alerts::MAX_RESENDS, $this->mails, 'The first attempt, then each resend.' );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_password_saved_again_before_a_resend_is_not_alerted() {
		$this->enable_alerts();
		$this->options['kunaki_wc_decrypt_failed'] = 'yes';
		$this->mail_return                         = false;
		( new Kunaki_Alerts() )->handle_password_decrypt_failed();
		$this->run_jobs();

		$this->mail_return = true;
		unset( $this->options['kunaki_wc_decrypt_failed'] );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails, 'Only the failed first attempt.' );
		$this->assertSame( array(), $this->jobs );
	}

	// ── Email ──────────────────────────────────────────────────────────

	public function test_blank_alert_address_uses_admin_email() {
		$this->enable_alerts( '' );
		$this->options['admin_email'] = 'admin@example.com';

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertSame( 'admin@example.com', $this->mails[0]['to'] );
	}

	public function test_no_valid_recipient_sends_nothing() {
		$this->enable_alerts( 'not-an-email' );
		$this->options['admin_email'] = '';

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertSame( array(), $this->mails );
	}

	public function test_failed_email_is_retried_once_to_admin_email() {
		$this->enable_alerts( 'typo@example.con' );
		$this->options['admin_email'] = 'admin@example.com';
		$this->mail_return = function ( $to ) {
			return 'admin@example.com' === $to;
		};

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertSame( array( 'typo@example.con', 'admin@example.com' ), array_column( $this->mails, 'to' ) );
	}

	public function test_failed_email_to_admin_is_not_sent_twice() {
		$this->enable_alerts( 'admin@example.com' );
		$this->options['admin_email'] = 'admin@example.com';
		$this->mail_return            = false;

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertCount( 1, $this->mails );
	}

	// ── Slack ──────────────────────────────────────────────────────────

	public function test_slack_post_goes_to_the_webhook_without_following_redirects() {
		$this->enable_slack();
		$this->add_order( 42, array( '_kunaki_order_id' => 'KUN777' ) );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_DECLINED );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/X', $this->posts[0]['url'] );
		$this->assertSame( 0, $this->posts[0]['args']['redirection'] );
		$payload = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertSame( 'Kunaki declined order #1042', $payload['text'] );
		$this->assertSame( 'danger', $payload['attachments'][0]['color'] );
		$this->assertSame( '#1042', $payload['attachments'][0]['fields'][0]['value'] );
	}

	public function test_slack_text_is_escaped_so_it_cannot_mention_or_link() {
		$this->enable_slack();
		$this->add_order( 42, array( '_kunaki_fulfillment_last_error' => 'Bad address <!channel> & <https://evil.example|click>' ) );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_ORDER, 42, Kunaki_Alerts::REASON_PERMANENT_FAILURE );

		$body = $this->posts[0]['args']['body'];
		$this->assertStringNotContainsString( '<!channel>', $body );
		$this->assertStringContainsString( '&lt;!channel&gt; &amp; &lt;https:', json_decode( $body, true )['attachments'][0]['text'] );
		$this->assertStringContainsString( '&lt;!channel&gt;', json_decode( $body, true )['attachments'][0]['fields'][1]['value'] );
	}

	public function test_recovery_slack_message_is_green() {
		$this->enable_slack();
		$this->rows[ Kunaki_Alerts::OUTAGE_FLAG ] = (string) time();

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_CLOSED );

		$payload = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertSame( 'good', $payload['attachments'][0]['color'] );
	}

	public function test_slack_blocks_a_non_slack_url_stored_without_the_save_filter() {
		foreach ( array( 'https://attacker.example/hook', 'http://hooks.slack.com/services/T/B/X' ) as $url ) {
			$this->enable_slack( $url );
			( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );
		}

		$this->assertSame( array(), $this->posts );
	}

	public function test_slack_without_webhook_posts_nothing() {
		$this->enable_slack( '' );

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertSame( array(), $this->posts );
	}

	public function test_slack_request_that_fails_does_not_count_as_delivered() {
		$this->enable_slack();
		$this->slack_error = true;
		$this->add_order( 42 );
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$alerts->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		$this->assertCount( 2, $this->posts, 'Both alerts were tried.' );
		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows, 'The outage alert must go out again at the next failed check.' );
		$this->assertCount( 1, $this->jobs, 'The order alert is queued to be sent again.' );
		$this->assertSame( array( 'order_needs_attention', 42, 'retries_exhausted', 1 ), $this->jobs[0]['args'] );
	}

	public function test_email_failure_does_not_stop_slack() {
		$this->enable_slack();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'yes';
		$this->mail_return = false;

		( new Kunaki_Alerts() )->execute_send( Kunaki_Alerts::EVENT_CIRCUIT_OPENED );

		$this->assertCount( 1, $this->posts );
	}

	/**
	 * @dataProvider webhook_urls
	 */
	public function test_validate_webhook_option( $input, $expected ) {
		$this->assertSame( $expected, Kunaki_Alerts::validate_webhook_option( $input ) );
	}

	public function webhook_urls() {
		return array(
			'slack url'               => array( 'https://hooks.slack.com/services/T/B/X', 'https://hooks.slack.com/services/T/B/X' ),
			'trimmed'                 => array( '  https://hooks.slack.com/services/T/B/X ', 'https://hooks.slack.com/services/T/B/X' ),
			'uppercase host'          => array( 'https://HOOKS.SLACK.COM/services/T/B/X', 'https://HOOKS.SLACK.COM/services/T/B/X' ),
			'other host'              => array( 'https://example.com/hook', '' ),
			'slack as subdomain'      => array( 'https://hooks.slack.com.evil.example/x', '' ),
			'slack as userinfo'       => array( 'https://hooks.slack.com@evil.example/x', '' ),
			'subdomain of slack host' => array( 'https://evil.hooks.slack.com/x', '' ),
			'plain http'              => array( 'http://hooks.slack.com/services/T/B/X', '' ),
			'other port'              => array( 'https://hooks.slack.com:8443/services/T/B/X', '' ),
			'not a url'               => array( 'not a url', '' ),
			'javascript scheme'       => array( 'javascript:alert(1)', '' ),
			'empty'                   => array( '', '' ),
			'not a string'            => array( array( 'x' ), '' ),
		);
	}

	public function test_init_registers_handlers() {
		$alerts = new Kunaki_Alerts();
		$alerts->init();

		$this->assertNotFalse( has_action( 'kunaki_wc_circuit_breaker_opened', array( $alerts, 'handle_circuit_opened' ) ) );
		$this->assertNotFalse( has_action( 'kunaki_wc_circuit_breaker_closed', array( $alerts, 'handle_circuit_closed' ) ) );
		$this->assertNotFalse( has_action( 'kunaki_wc_order_needs_attention', array( $alerts, 'handle_order_needs_attention' ) ) );
		$this->assertNotFalse( has_action( 'kunaki_wc_password_decrypt_failed', array( $alerts, 'handle_password_decrypt_failed' ) ) );
		$this->assertNotFalse( has_action( 'kunaki_wc_send_alert', array( $alerts, 'execute_send' ) ) );
	}

	public function test_send_job_is_passed_the_resend_count() {
		// Registered for fewer arguments, the count would be dropped and an
		// alert that can't be delivered would be queued again for ever.
		Actions\expectAdded( Kunaki_Alerts::SEND_ACTION )->once()->with( Mockery::type( 'array' ), 10, 4 );

		( new Kunaki_Alerts() )->init();
	}

	// ── A send that throws ─────────────────────────────────────────────

	public function test_email_that_throws_does_not_stop_slack() {
		$this->enable_slack();
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = 'alerts@example.com';
		$this->mail_return                                 = function () {
			throw new \RuntimeException( 'mail plugin blew up' );
		};
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->assertCount( 1, $this->posts, 'Slack is still tried.' );
		$this->assertArrayHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows, 'Slack delivered it, so the outage counts as reported.' );
		$this->assertTrue( $this->logged( 'mail plugin blew up' ), 'The log is the only place the failed email shows.' );
	}

	/**
	 * @dataProvider channels_that_throw
	 */
	public function test_outage_alert_whose_send_throws_is_sent_again_at_the_next_failed_check( $channel ) {
		$this->use_only_a_channel_that_throws( $channel );
		$alerts = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();
		$this->run_jobs();

		$this->assertArrayNotHasKey( Kunaki_Alerts::OUTAGE_FLAG, $this->rows, 'An alert nobody received must not count as sent.' );

		$alerts->handle_circuit_opened();          // The next failed recovery check.

		$this->assertCount( 1, $this->jobs, 'So the next failed check alerts again.' );
	}

	/**
	 * @dataProvider channels_that_throw
	 */
	public function test_order_alert_whose_send_throws_is_sent_again_later( $channel ) {
		$this->use_only_a_channel_that_throws( $channel );
		$this->add_order( 42 );

		( new Kunaki_Alerts() )->handle_order_needs_attention( 42, Kunaki_Alerts::REASON_RETRIES_EXHAUSTED );
		$this->run_jobs();

		$this->assertCount( 1, $this->jobs, 'Nothing else would bring this alert back.' );
		$this->assertSame( array( 'order_needs_attention', 42, 'retries_exhausted', 1 ), $this->jobs[0]['args'] );
	}

	public function channels_that_throw() {
		return array(
			'email throws' => array( 'email' ),
			'Slack throws' => array( 'slack' ),
		);
	}

	public function test_outage_alert_that_throws_while_being_queued_does_not_count_as_sent() {
		$this->enable_alerts();
		$this->enqueue_throws = true;
		$alerts               = new Kunaki_Alerts();

		$alerts->handle_circuit_opened();

		$this->assertSame( array(), $this->rows, 'Nothing was queued, so the outage must not count as reported.' );

		$this->enqueue_throws = false;
		$alerts->handle_circuit_opened();          // The next failed recovery check.
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/** Turn on one channel only, and make its send throw, as a broken mail plugin or a filter on the request would. */
	private function use_only_a_channel_that_throws( $channel ) {
		if ( 'email' === $channel ) {
			$this->enable_alerts();
			$this->mail_return = function () {
				throw new \RuntimeException( 'mail plugin blew up' );
			};
			return;
		}
		$this->enable_slack();
		$this->slack_throws = true;
	}

	private function enable_alerts( $email = 'alerts@example.com' ) {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ]       = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ADDRESS ] = $email;
	}

	private function enable_slack( $url = 'https://hooks.slack.com/services/T/B/X' ) {
		$this->options[ Kunaki_Alerts::OPT_ENABLED ]       = 'yes';
		$this->options[ Kunaki_Alerts::OPT_EMAIL_ENABLED ] = 'no';
		$this->options[ Kunaki_Alerts::OPT_SLACK_ENABLED ] = 'yes';
		$this->options[ Kunaki_Alerts::OPT_SLACK_WEBHOOK ] = $url;
	}

	private function set_circuit( $state ) {
		$this->options['kunaki_wc_circuit_breaker'] = json_encode( array( 'state' => $state, 'failure_count' => 'open' === $state ? 5 : 0, 'last_failure_at' => time(), 'opened_at' => time() ) );
	}

	private function add_order( $id, $meta = array(), $status = 'processing' ) {
		$meta += array( '_kunaki_fulfillment_exhausted' => 'yes', '_kunaki_order_status' => 'DECLINED' );
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'has_status' )->andReturnUsing( function ( $statuses ) use ( $status ) {
			return in_array( $status, (array) $statuses, true );
		} );
		$order->shouldReceive( 'get_order_number' )->andReturn( (string) ( 1000 + $id ) );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.test/edit/' . $id );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
		} );
		$this->orders[ $id ] = $order;
	}

	private function logged( $text ) {
		foreach ( $this->logs as $line ) {
			if ( false !== strpos( $line, $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** Run queued jobs the way Action Scheduler would: args positionally. */
	private function run_jobs() {
		$jobs       = $this->jobs;
		$this->jobs = array();
		$alerts     = new Kunaki_Alerts();
		foreach ( $jobs as $job ) {
			$alerts->execute_send( ...$job['args'] );
		}
	}

	private function wire_mocks() {
		$test = $this;

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $test ) {
			return array_key_exists( $key, $test->options ) ? $test->options[ $key ] : $default;
		} );
		Functions\when( 'is_email' )->alias( function ( $email ) {
			return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
		} );
		Functions\when( 'get_bloginfo' )->alias( function ( $field ) {
			return 'name' === $field ? 'Test Store' : '';
		} );
		Functions\when( 'wp_specialchars_decode' )->alias( function ( $text ) {
			return htmlspecialchars_decode( $text, ENT_QUOTES );
		} );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
		} );
		Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
			return parse_url( $url, $component );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $test ) {
			return isset( $test->orders[ $id ] ) ? $test->orders[ $id ] : false;
		} );
		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) use ( $test ) {
			$test->mails[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
			return is_callable( $test->mail_return ) ? call_user_func( $test->mail_return, $to ) : $test->mail_return;
		} );
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( $test ) {
			$test->posts[] = array( 'url' => $url, 'args' => $args );
			if ( $test->slack_throws ) {
				throw new \RuntimeException( 'request filter blew up' );
			}
			if ( $test->slack_error ) {
				return new \WP_Error( 'http_request_failed', 'Could not resolve host: hooks.slack.com' );
			}
			return array( 'response' => array( 'code' => $test->slack_status ) );
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function () use ( $test ) {
			return $test->slack_status;
		} );
		Functions\when( 'as_enqueue_async_action' )->alias( function ( $hook, $args, $group ) use ( $test ) {
			if ( $test->enqueue_throws ) {
				throw new \RuntimeException( 'Error saving action' );
			}
			if ( $test->enqueue_return ) {
				$test->jobs[] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => time() );
			}
			return $test->enqueue_return;
		} );
		Functions\when( 'as_schedule_single_action' )->alias( function ( $when, $hook, $args, $group ) use ( $test ) {
			if ( $test->schedule_return ) {
				$test->jobs[] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => $when );
			}
			return $test->schedule_return;
		} );
		$logger = Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing();
		$logger->shouldReceive( 'log' )->andReturnUsing( function ( $level, $message ) use ( $test ) {
			$test->logs[] = (string) $message;
		} );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// The outage flag is written with raw SQL.
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query, ...$args ) {
			return array( 'query' => $query, 'args' => $args );
		} );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( function ( $prepared ) use ( $test ) {
			$key = $prepared['args'][0];
			return isset( $test->rows[ $key ] ) ? $test->rows[ $key ] : null;
		} );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $prepared ) use ( $test ) {
			list( $query, $args ) = array( $prepared['query'], $prepared['args'] );
			$key                  = $args[0];
			if ( 0 === strpos( $query, 'INSERT IGNORE' ) ) {
				if ( isset( $test->rows[ $key ] ) ) {
					return 0;
				}
				$test->rows[ $key ] = $args[1];
				return 1;
			}
			if ( 0 === strpos( $query, 'DELETE' ) && isset( $test->rows[ $key ] ) ) {
				$conditional = false !== strpos( $query, 'option_value <=' );
				if ( ! $conditional || (int) $test->rows[ $key ] <= (int) $args[1] ) {
					unset( $test->rows[ $key ] );
					return 1;
				}
			}
			return 0;
		} );
		$GLOBALS['wpdb'] = $wpdb;
	}
}
