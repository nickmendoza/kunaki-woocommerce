<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Alerts {

	const OPT_ENABLED       = 'kunaki_wc_alerts_enabled';
	const OPT_EMAIL_ENABLED = 'kunaki_wc_alert_email_enabled';
	const OPT_EMAIL_ADDRESS = 'kunaki_wc_alert_email_address';
	const OPT_SLACK_ENABLED = 'kunaki_wc_alert_slack_enabled';
	const OPT_SLACK_WEBHOOK = 'kunaki_wc_alert_slack_webhook';

	// Per-event claim row lives in wp_options (not a transient) so INSERT IGNORE
	// gives us atomic debounce across concurrent PHP-FPM workers. The value is
	// the claim expiry timestamp.
	const CLAIM_PREFIX = 'kunaki_wc_alert_claim_';
	const CLAIM_TTL    = 30 * MINUTE_IN_SECONDS;

	const CRON_SEND_ALERT = 'kunaki_wc_send_alert';

	const SEVERITY_DANGER = 'danger';
	const SEVERITY_GOOD   = 'good';

	// Cap arbitrary third-party strings (Kunaki error bodies) before splicing
	// them into email or Slack payloads.
	const MAX_ERROR_CHARS = 500;

	public function init() {
		add_action( 'kunaki_wc_circuit_breaker_opened', array( $this, 'handle_circuit_opened' ), 10, 1 );
		add_action( 'kunaki_wc_circuit_breaker_closed', array( $this, 'handle_circuit_closed' ), 10, 1 );
		add_action( 'kunaki_wc_order_retry_exhausted', array( $this, 'handle_retry_exhausted' ), 10, 3 );
		add_action( self::CRON_SEND_ALERT, array( $this, 'execute_send' ), 10, 2 );
	}

	public function handle_circuit_opened( $state ) {
		$state    = is_array( $state ) ? $state : array();
		$failures = isset( $state['failure_count'] ) ? (int) $state['failure_count'] : 0;
		$cooldown = absint( get_option( 'kunaki_wc_circuit_cooldown', 5 ) );
		if ( $cooldown < 1 ) {
			$cooldown = 5;
		}

		$subject = $this->site_tag() . ' ' . __( 'Kunaki API outage detected', 'kunaki-woocommerce' );
		$body    = sprintf(
			/* translators: 1: consecutive failure count, 2: cooldown minutes, 3: logs URL */
			__( "The Kunaki API circuit breaker has tripped after %1\$d consecutive infrastructure failures.\n\nOrder submissions, status syncs, and shipping rate lookups are paused. The plugin will automatically probe for recovery after a %2\$d-minute cooldown.\n\nReview detailed errors: %3\$s", 'kunaki-woocommerce' ),
			$failures,
			$cooldown,
			$this->logs_url()
		);

		$slack_fields = array(
			array( 'title' => __( 'Consecutive failures', 'kunaki-woocommerce' ), 'value' => (string) $failures, 'short' => true ),
			array( 'title' => __( 'Cooldown (minutes)', 'kunaki-woocommerce' ), 'value' => (string) $cooldown, 'short' => true ),
		);

		$this->dispatch( 'circuit_opened', $subject, $body, __( 'Kunaki API outage detected', 'kunaki-woocommerce' ), $slack_fields, self::SEVERITY_DANGER );
	}

	public function handle_circuit_closed( $state ) {
		$subject = $this->site_tag() . ' ' . __( 'Kunaki API recovered', 'kunaki-woocommerce' );
		$body    = __( "The Kunaki API circuit breaker has closed — the API is responding normally again.\n\nQueued retries will resume on the next scheduled cron run.", 'kunaki-woocommerce' );

		$this->dispatch( 'circuit_closed', $subject, $body, __( 'Kunaki API recovered', 'kunaki-woocommerce' ), array(), self::SEVERITY_GOOD );
	}

	public function handle_retry_exhausted( $order_id, $attempts, $last_error ) {
		$order_id   = (int) $order_id;
		$attempts   = (int) $attempts;
		$last_error = $this->sanitize_error_text( (string) $last_error );

		$subject = sprintf(
			/* translators: 1: site tag, 2: order ID */
			__( '%1$s Order #%2$d failed Kunaki submission', 'kunaki-woocommerce' ),
			$this->site_tag(),
			$order_id
		);

		$body = sprintf(
			/* translators: 1: order ID, 2: attempt count, 3: last error, 4: order edit URL */
			__( "Order #%1\$d exhausted all %2\$d automatic retries and will not be submitted to Kunaki without manual intervention.\n\nLast error: %3\$s\n\nReview and retry: %4\$s", 'kunaki-woocommerce' ),
			$order_id,
			$attempts,
			'' === $last_error ? __( '(not recorded)', 'kunaki-woocommerce' ) : $last_error,
			$this->order_edit_url( $order_id )
		);

		$slack_fields = array(
			array( 'title' => __( 'Order ID', 'kunaki-woocommerce' ), 'value' => '#' . $order_id, 'short' => true ),
			array( 'title' => __( 'Attempts', 'kunaki-woocommerce' ), 'value' => (string) $attempts, 'short' => true ),
			array( 'title' => __( 'Last error', 'kunaki-woocommerce' ), 'value' => '' === $last_error ? '—' : $last_error, 'short' => false ),
		);

		$this->dispatch(
			'retry_exhausted_' . $order_id,
			$subject,
			$body,
			sprintf( __( 'Order #%d failed Kunaki submission', 'kunaki-woocommerce' ), $order_id ),
			$slack_fields,
			self::SEVERITY_DANGER
		);
	}

	private function dispatch( $event_key, $subject, $body_text, $slack_title, $slack_fields, $severity ) {
		if ( 'yes' !== get_option( self::OPT_ENABLED, 'no' ) ) {
			return;
		}

		// Atomic debounce: only one process can claim the slot. Subsequent fires
		// within the TTL window lose the INSERT IGNORE and bail.
		if ( ! $this->claim_debounce_slot( $event_key ) ) {
			Kunaki_Logger::debug( 'Alert debounced.', array( 'event' => $event_key ) );
			return;
		}

		// Defer the actual send to background cron so customer-facing requests
		// that trip the circuit breaker don't pay wp_mail/Slack latency inline.
		$payload = array(
			'subject'      => $subject,
			'body_text'    => $body_text,
			'slack_title'  => $slack_title,
			'slack_fields' => $slack_fields,
			'severity'     => $severity,
		);
		wp_schedule_single_event( time(), self::CRON_SEND_ALERT, array( $event_key, $payload ) );
	}

	/**
	 * Atomically claim the debounce slot for this event.
	 *
	 * Uses INSERT IGNORE on a row in wp_options so exactly one concurrent
	 * process wins. Expired rows are cleaned up atomically first so the
	 * claim naturally expires after CLAIM_TTL.
	 *
	 * @param string $event_key Debounce bucket (e.g. "circuit_opened").
	 * @return bool True if this process acquired the claim, false otherwise.
	 */
	private function claim_debounce_slot( $event_key ) {
		global $wpdb;
		$option_name = self::CLAIM_PREFIX . $event_key;
		$expiry      = time() + self::CLAIM_TTL;

		// Atomically delete an expired claim (no SELECT-then-DELETE race).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s",
			$option_name,
			(string) time()
		) );

		// Atomic acquire — INSERT IGNORE on a single row, only one process wins.
		$acquired = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$option_name,
			(string) $expiry
		) );

		return (bool) $acquired;
	}

	/**
	 * Cron handler — does the actual email + Slack dispatch outside the
	 * customer-facing request path. Fires via wp_schedule_single_event()
	 * from dispatch().
	 *
	 * @param string $event_key The debounce bucket (for logging only).
	 * @param mixed  $payload   Serialized content built in dispatch(); defensively
	 *                          validated because it round-trips through WP-Cron
	 *                          serialization and could be mangled/replayed.
	 */
	public function execute_send( $event_key, $payload ) {
		if ( ! is_array( $payload ) ) {
			Kunaki_Logger::error( 'Alert cron payload invalid — dropping.', array( 'event' => $event_key ) );
			return;
		}

		$subject      = isset( $payload['subject'] ) ? (string) $payload['subject'] : '';
		$body_text    = isset( $payload['body_text'] ) ? (string) $payload['body_text'] : '';
		$slack_title  = isset( $payload['slack_title'] ) ? (string) $payload['slack_title'] : '';
		$slack_fields = isset( $payload['slack_fields'] ) && is_array( $payload['slack_fields'] ) ? $payload['slack_fields'] : array();
		$severity     = isset( $payload['severity'] ) ? (string) $payload['severity'] : self::SEVERITY_DANGER;

		if ( 'yes' === get_option( self::OPT_EMAIL_ENABLED, 'yes' ) ) {
			$this->send_email( $subject, $body_text );
		}

		if ( 'yes' === get_option( self::OPT_SLACK_ENABLED, 'no' ) ) {
			$this->send_slack( $slack_title, $body_text, $slack_fields, $severity );
		}
	}

	private function send_email( $subject, $body_text ) {
		$to = $this->recipient_email();
		if ( empty( $to ) ) {
			Kunaki_Logger::error( 'Alert email skipped: no recipient configured.' );
			return false;
		}

		$result = wp_mail( $to, $subject, $body_text );

		if ( ! $result ) {
			Kunaki_Logger::error( 'Alert email failed to send.', array( 'recipient' => $to ) );
			return false;
		}

		Kunaki_Logger::info( 'Alert email sent.', array( 'recipient' => $to ) );
		return true;
	}

	private function send_slack( $title, $body_text, $fields, $severity ) {
		$webhook = (string) get_option( self::OPT_SLACK_WEBHOOK, '' );
		if ( '' === $webhook ) {
			Kunaki_Logger::error( 'Alert Slack skipped: no webhook URL configured.' );
			return false;
		}

		// Defense-in-depth: re-validate the webhook host at send time in case
		// the stored option bypassed the pre_update_option filter (WP-CLI,
		// direct SQL, a migration import, etc.).
		$host = wp_parse_url( $webhook, PHP_URL_HOST );
		if ( ! is_string( $host ) || 'hooks.slack.com' !== strtolower( $host ) ) {
			Kunaki_Logger::error( 'Alert Slack blocked: stored webhook URL is not on hooks.slack.com.' );
			return false;
		}

		$payload = array(
			'text'        => $title,
			'attachments' => array(
				array(
					'color'  => $severity,
					'title'  => $title,
					'text'   => $body_text,
					'fields' => array_values( $fields ),
					'footer' => 'Kunaki WooCommerce — ' . get_bloginfo( 'name' ),
					'ts'     => time(),
				),
			),
		);

		$response = wp_remote_post( $webhook, array(
			'timeout'     => 5,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			Kunaki_Logger::error( 'Alert Slack transport failed.', array( 'error' => $response->get_error_message() ) );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Kunaki_Logger::error( 'Alert Slack returned non-2xx.', array( 'status' => $code ) );
			return false;
		}

		Kunaki_Logger::info( 'Alert Slack posted.' );
		return true;
	}

	private function recipient_email() {
		$configured = trim( (string) get_option( self::OPT_EMAIL_ADDRESS, '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		$fallback = get_option( 'admin_email', '' );
		return is_email( $fallback ) ? $fallback : '';
	}

	private function site_tag() {
		$name = trim( (string) get_bloginfo( 'name' ) );
		if ( '' === $name ) {
			$name = __( 'Kunaki WooCommerce', 'kunaki-woocommerce' );
		}
		return '[' . $name . ']';
	}

	private function logs_url() {
		return admin_url( 'admin.php?page=wc-status&tab=logs' );
	}

	private function order_edit_url( $order_id ) {
		// Legacy post.php URL works under HPOS too — WooCommerce redirects it to the
		// HPOS edit screen automatically, so we skip the HPOS container indirection.
		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}

	private function sanitize_error_text( $text ) {
		// Strip control characters (newlines/tabs preserved) so an error body
		// from Kunaki can't embed Slack formatting tricks or break email headers.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > self::MAX_ERROR_CHARS ) {
			return mb_substr( $text, 0, self::MAX_ERROR_CHARS ) . '…';
		}
		if ( strlen( $text ) > self::MAX_ERROR_CHARS ) {
			return substr( $text, 0, self::MAX_ERROR_CHARS ) . '…';
		}
		return $text;
	}
}
