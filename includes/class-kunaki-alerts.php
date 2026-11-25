<?php
defined( 'ABSPATH' ) || exit;

/**
 * Email and Slack alerts for problems that need a person: Kunaki API outages,
 * and orders that can't be fulfilled without someone acting on them.
 *
 * Other code fires public actions after the state change is saved. This class
 * listens to them, queues a small background job, and builds the message when
 * the job runs, so customer-facing requests never wait on email or Slack.
 */
class Kunaki_Alerts {

	const OPT_ENABLED       = 'kunaki_wc_alerts_enabled';
	const OPT_EMAIL_ENABLED = 'kunaki_wc_alert_email_enabled';
	const OPT_EMAIL_ADDRESS = 'kunaki_wc_alert_email_address';
	const OPT_SLACK_ENABLED = 'kunaki_wc_alert_slack_enabled';
	const OPT_SLACK_WEBHOOK = 'kunaki_wc_alert_slack_webhook';

	const SEND_ACTION  = 'kunaki_wc_send_alert';
	const ACTION_GROUP = 'kunaki-woocommerce';

	const EVENT_CIRCUIT_OPENED = 'circuit_opened';
	const EVENT_CIRCUIT_CLOSED = 'circuit_closed';
	const EVENT_ORDER          = 'order_needs_attention';

	const REASON_RETRIES_EXHAUSTED = 'retries_exhausted';
	const REASON_PERMANENT_FAILURE = 'permanent_failure';
	const REASON_DECLINED          = 'declined';

	// One outage alert per outage, however often the circuit flaps. This row is
	// written with INSERT IGNORE when the outage alert is sent, so only one
	// concurrent request wins, and deleted when the recovery alert is sent.
	// Its value is when it was set; after a day it counts as stale.
	const OUTAGE_FLAG       = 'kunaki_wc_alert_outage_open';
	const OUTAGE_FLAG_STALE = DAY_IN_SECONDS;

	// Recovery is only reported once the API has stayed up this long.
	const RECOVERY_DELAY = 15 * MINUTE_IN_SECONDS;

	// Only Slack incoming webhooks on this host are allowed, both when the URL
	// is saved and when a message is sent.
	const SLACK_WEBHOOK_HOST = 'hooks.slack.com';

	// Cap error text from Kunaki before putting it in an email or Slack message.
	const MAX_ERROR_CHARS = 500;

	const SEVERITY_DANGER = 'danger';
	const SEVERITY_GOOD   = 'good';

	public function init() {
		add_action( 'kunaki_wc_circuit_breaker_opened', array( $this, 'handle_circuit_opened' ), 10, 0 );
		add_action( 'kunaki_wc_circuit_breaker_closed', array( $this, 'handle_circuit_closed' ), 10, 0 );
		add_action( 'kunaki_wc_order_needs_attention', array( $this, 'handle_order_needs_attention' ), 10, 2 );
		add_action( self::SEND_ACTION, array( $this, 'execute_send' ), 10, 3 );
	}

	/**
	 * Sanitize a Slack webhook URL before it's saved. Anything that isn't an
	 * https URL on hooks.slack.com is stored as ''. Registered on
	 * sanitize_option, which add_option() and update_option() both run, so
	 * WP-CLI and imports are checked too.
	 *
	 * Deliberately no DNS lookup (as wp_http_validate_url() does): this runs on
	 * every save of the settings page, and a DNS hiccup would wipe a working URL.
	 *
	 * @param mixed $value Value being saved.
	 * @return string
	 */
	public static function validate_webhook_option( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return self::is_slack_webhook( $value ) ? esc_url_raw( $value ) : '';
	}

	private static function is_slack_webhook( $url ) {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& self::SLACK_WEBHOOK_HOST === strtolower( $parts['host'] )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] );
	}

	public function handle_circuit_opened() {
		// Only the first trip of an outage alerts. A failed recovery check, or
		// a trip soon after a recovery that wasn't reported yet, is the same outage.
		if ( ! $this->alerts_enabled() || ! $this->claim_outage_flag() ) {
			return;
		}
		$this->dispatch( self::EVENT_CIRCUIT_OPENED );
	}

	public function handle_circuit_closed() {
		// Nothing to report if no outage alert went out.
		if ( ! $this->alerts_enabled() || ! $this->outage_flag_exists() ) {
			return;
		}

		// Check again later, and only report recovery if the API is still up.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$queued = as_schedule_single_action( time() + self::RECOVERY_DELAY, self::SEND_ACTION, array( self::EVENT_CIRCUIT_CLOSED, 0, '' ), self::ACTION_GROUP );
			if ( $queued ) {
				return;
			}
		}
		$this->execute_send( self::EVENT_CIRCUIT_CLOSED );
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $reason   One of the REASON_* constants.
	 */
	public function handle_order_needs_attention( $order_id, $reason ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! in_array( $reason, array( self::REASON_RETRIES_EXHAUSTED, self::REASON_PERMANENT_FAILURE, self::REASON_DECLINED ), true ) ) {
			return;
		}
		$this->dispatch( self::EVENT_ORDER, $order_id, (string) $reason );
	}

	/**
	 * Queue an alert, or send it now if Action Scheduler isn't available.
	 *
	 * @param string $event    One of the EVENT_* constants.
	 * @param int    $order_id Order ID, for order events.
	 * @param string $reason   Reason, for order events.
	 */
	private function dispatch( $event, $order_id = 0, $reason = '' ) {
		if ( ! $this->alerts_enabled() ) {
			return;
		}

		// A list, not keys: Action Scheduler passes the args positionally.
		$args = array( $event, (int) $order_id, (string) $reason );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$queued = as_enqueue_async_action( self::SEND_ACTION, $args, self::ACTION_GROUP );
			if ( $queued ) {
				return;
			}
			Kunaki_Logger::info( 'Alert could not be queued. Sending it now.', array( 'event' => $event ) );
		}

		$this->execute_send( ...$args );
	}

	/**
	 * Build and send an alert. Runs as a background job.
	 *
	 * @param string $event    One of the EVENT_* constants.
	 * @param int    $order_id Order ID, for order events.
	 * @param string $reason   Reason, for order events.
	 */
	public function execute_send( $event, $order_id = 0, $reason = '' ) {
		// The admin may have turned alerts off since this was queued.
		if ( ! $this->alerts_enabled() ) {
			Kunaki_Logger::debug( 'Alert skipped: alerts were turned off after it was queued.', array( 'event' => $event ) );
			return;
		}

		if ( self::EVENT_CIRCUIT_CLOSED === $event ) {
			// Down again since the recovery: the outage alert still stands.
			$state = ( new Kunaki_Circuit_Breaker() )->get_state();
			if ( 'closed' !== $state['state'] ) {
				return;
			}
			// Several recoveries may each have queued a check; only one reports.
			if ( ! $this->release_outage_flag() ) {
				return;
			}
		}

		$message = $this->build_message( (string) $event, (int) $order_id, (string) $reason );
		if ( null === $message ) {
			Kunaki_Logger::info( 'Alert not sent: the order no longer needs attention, or no longer exists.', array( 'event' => $event, 'order_id' => $order_id ) );
			return;
		}

		if ( 'yes' === get_option( self::OPT_EMAIL_ENABLED, 'yes' ) ) {
			$this->send_email( $this->site_tag() . ' ' . $message['title'], $message['body'] );
		}

		if ( 'yes' === get_option( self::OPT_SLACK_ENABLED, 'no' ) ) {
			$this->send_slack( $message['title'], $message['body'], $message['fields'], $message['severity'] );
		}
	}

	/**
	 * @return array{title: string, body: string, fields: array, severity: string}|null
	 */
	private function build_message( $event, $order_id, $reason ) {
		switch ( $event ) {
			case self::EVENT_CIRCUIT_OPENED:
				$cooldown = absint( get_option( 'kunaki_wc_circuit_cooldown', 5 ) );
				$cooldown = $cooldown < 1 ? 5 : $cooldown;
				return array(
					'title'    => __( 'Kunaki API outage detected', 'kunaki-woocommerce' ),
					'body'     => sprintf(
						/* translators: 1: cooldown minutes, 2: logs URL */
						__( "Kunaki's API has stopped responding, so the plugin has paused order submission, status updates and shipping rates. Carts with discs show that shipping rates are temporarily unavailable.\n\nThe plugin checks again every %1\$d minutes and resumes on its own when Kunaki responds. Orders aren't lost: they're retried once the API is back.\n\nErrors: %2\$s", 'kunaki-woocommerce' ),
						$cooldown,
						admin_url( 'admin.php?page=wc-status&tab=logs' )
					),
					'fields'   => array(
						array( 'title' => __( 'Check again every (minutes)', 'kunaki-woocommerce' ), 'value' => (string) $cooldown, 'short' => true ),
					),
					'severity' => self::SEVERITY_DANGER,
				);

			case self::EVENT_CIRCUIT_CLOSED:
				return array(
					'title'    => __( 'Kunaki API recovered', 'kunaki-woocommerce' ),
					'body'     => __( "Kunaki's API is responding again. Order submission, status updates and shipping rates have resumed, and queued retries will run on the next scheduled check.", 'kunaki-woocommerce' ),
					'fields'   => array(),
					'severity' => self::SEVERITY_GOOD,
				);

			case self::EVENT_ORDER:
				return $this->build_order_message( $order_id, $reason );
		}
		return null;
	}

	private function build_order_message( $order_id, $reason ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return null;
		}

		// The admin may have dealt with it before this job ran, which also
		// clears the details below. Nothing to report then.
		$still_needed = self::REASON_DECLINED === $reason
			? 'DECLINED' === $order->get_meta( Kunaki_Order_Fulfillment::META_KUNAKI_STATUS )
			: 'yes' === $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_EXHAUSTED );
		if ( ! $still_needed ) {
			return null;
		}

		$number = $order->get_order_number();
		$url    = $order->get_edit_order_url();
		$error  = $this->sanitize_error_text( (string) $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_LAST_ERROR ) );
		$error  = '' === $error ? __( '(not recorded)', 'kunaki-woocommerce' ) : $error;

		switch ( $reason ) {
			case self::REASON_RETRIES_EXHAUSTED:
				$attempts = (int) $order->get_meta( Kunaki_Order_Fulfillment::META_FULFILLMENT_ATTEMPTS );
				$title    = sprintf( /* translators: %s: order number */ __( 'Order #%s could not be sent to Kunaki', 'kunaki-woocommerce' ), $number );
				$body     = sprintf(
					/* translators: 1: order number, 2: attempt count, 3: error, 4: order URL */
					__( "Order #%1\$s still wasn't accepted by Kunaki after %2\$d attempts, and won't be retried automatically.\n\nLast error: %3\$s\n\nFix the problem, then retry from the order page: %4\$s", 'kunaki-woocommerce' ),
					$number,
					$attempts,
					$error,
					$url
				);
				$fields = array(
					array( 'title' => __( 'Attempts', 'kunaki-woocommerce' ), 'value' => (string) $attempts, 'short' => true ),
					array( 'title' => __( 'Last error', 'kunaki-woocommerce' ), 'value' => $error, 'short' => false ),
				);
				break;

			case self::REASON_PERMANENT_FAILURE:
				$title  = sprintf( /* translators: %s: order number */ __( 'Order #%s can\'t be sent to Kunaki', 'kunaki-woocommerce' ), $number );
				$body   = sprintf(
					/* translators: 1: order number, 2: error, 3: order URL */
					__( "Order #%1\$s can't be sent to Kunaki until the problem below is fixed. It won't be retried automatically.\n\nError: %2\$s\n\nFix the problem, then retry from the order page: %3\$s", 'kunaki-woocommerce' ),
					$number,
					$error,
					$url
				);
				$fields = array(
					array( 'title' => __( 'Error', 'kunaki-woocommerce' ), 'value' => $error, 'short' => false ),
				);
				break;

			case self::REASON_DECLINED:
				$kunaki_id = $this->sanitize_error_text( (string) $order->get_meta( Kunaki_Order_Fulfillment::META_KUNAKI_ORDER_ID ) );
				$title     = sprintf( /* translators: %s: order number */ __( 'Kunaki declined order #%s', 'kunaki-woocommerce' ), $number );
				$body      = sprintf(
					/* translators: 1: order number, 2: Kunaki order ID, 3: order URL */
					__( "Kunaki declined order #%1\$s (Kunaki order %2\$s), and it's now on hold. Check your Kunaki account for the reason.\n\nTo try again, move the order back to Processing and retry it. That submits a new Kunaki order, which is manufactured and billed separately.\n\nOrder: %3\$s", 'kunaki-woocommerce' ),
					$number,
					$kunaki_id,
					$url
				);
				$fields = array(
					array( 'title' => __( 'Kunaki order', 'kunaki-woocommerce' ), 'value' => $kunaki_id, 'short' => true ),
				);
				break;

			default:
				return null;
		}

		array_unshift( $fields, array( 'title' => __( 'Order', 'kunaki-woocommerce' ), 'value' => '#' . $number, 'short' => true ) );

		return array(
			'title'    => $title,
			'body'     => $body,
			'fields'   => $fields,
			'severity' => self::SEVERITY_DANGER,
		);
	}

	private function alerts_enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/**
	 * Atomically set the outage flag. Only one concurrent request wins the
	 * INSERT IGNORE. A stale flag (left by an outage that never recovered while
	 * alerts were on) is deleted first, so it can't silence alerts for good.
	 *
	 * @return bool Whether this request set the flag.
	 */
	private function claim_outage_flag() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value <= %s",
			self::OUTAGE_FLAG,
			(string) ( time() - self::OUTAGE_FLAG_STALE )
		) );

		return (bool) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::OUTAGE_FLAG,
			(string) time()
		) );
	}

	private function outage_flag_exists() {
		global $wpdb;
		// Read the table directly: the row is written with raw SQL, so the options cache doesn't know it.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::OUTAGE_FLAG
		) );
	}

	/**
	 * @return bool Whether this request deleted the flag.
	 */
	private function release_outage_flag() {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			self::OUTAGE_FLAG
		) );
	}

	private function send_email( $subject, $body ) {
		$to = $this->recipient_email();
		if ( '' === $to ) {
			Kunaki_Logger::error( 'Alert email skipped: no recipient configured.' );
			return false;
		}

		if ( wp_mail( $to, $subject, $body ) ) {
			Kunaki_Logger::info( 'Alert email sent.', array( 'recipient' => $to ) );
			return true;
		}

		Kunaki_Logger::error( 'Alert email failed to send.', array( 'recipient' => $to ) );

		// Try the site admin once, so a typo in the alert address doesn't
		// silently stop every alert.
		$admin_email = (string) get_option( 'admin_email', '' );
		if ( is_email( $admin_email ) && $admin_email !== $to ) {
			if ( wp_mail( $admin_email, $subject, $body ) ) {
				Kunaki_Logger::info( 'Alert email sent to the site admin instead.', array( 'recipient' => $admin_email ) );
				return true;
			}
			Kunaki_Logger::error( 'Alert email to the site admin also failed.', array( 'recipient' => $admin_email ) );
		}

		return false;
	}

	private function send_slack( $title, $body, $fields, $severity ) {
		$webhook = (string) get_option( self::OPT_SLACK_WEBHOOK, '' );
		if ( '' === $webhook ) {
			Kunaki_Logger::error( 'Alert Slack skipped: no webhook URL configured.' );
			return false;
		}

		// Checked again here in case the option was written without the save filter.
		if ( ! self::is_slack_webhook( $webhook ) ) {
			Kunaki_Logger::error( 'Alert Slack blocked: the webhook URL is not an https URL on ' . self::SLACK_WEBHOOK_HOST . '.' );
			return false;
		}

		// Slack reads <...> as links and mentions (<!channel>), so error text
		// from Kunaki or order data must be escaped.
		$title  = self::slack_escape( $title );
		$body   = self::slack_escape( $body );
		$fields = array_map( function ( $field ) {
			$field['title'] = self::slack_escape( (string) $field['title'] );
			$field['value'] = self::slack_escape( (string) $field['value'] );
			return $field;
		}, $fields );

		$response = wp_remote_post( $webhook, array(
			'timeout'     => 5,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( array(
				'text'        => $title,
				'attachments' => array(
					array(
						'color'  => $severity,
						'title'  => $title,
						'text'   => $body,
						'fields' => array_values( $fields ),
						'footer' => 'Kunaki WooCommerce: ' . self::slack_escape( (string) get_bloginfo( 'name' ) ),
						'ts'     => time(),
					),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			Kunaki_Logger::error( 'Alert Slack request failed.', array( 'error' => $response->get_error_message() ) );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Kunaki_Logger::error( 'Alert Slack returned an error status.', array( 'status' => $code ) );
			return false;
		}

		Kunaki_Logger::info( 'Alert Slack posted.' );
		return true;
	}

	private static function slack_escape( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	private function recipient_email() {
		$configured = trim( (string) get_option( self::OPT_EMAIL_ADDRESS, '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		$fallback = (string) get_option( 'admin_email', '' );
		return is_email( $fallback ) ? $fallback : '';
	}

	private function site_tag() {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '[' . ( '' === $name ? 'Kunaki WooCommerce' : $name ) . ']';
	}

	/**
	 * Strip control characters (keeping newlines and tabs) and cap the length.
	 *
	 * @param string $text Text from Kunaki or order meta.
	 * @return string
	 */
	private function sanitize_error_text( $text ) {
		// With /u, invalid UTF-8 makes preg_replace return null, so fall back to bytes.
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		if ( null === $stripped ) {
			$stripped = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $stripped ) > self::MAX_ERROR_CHARS ) {
			return mb_substr( $stripped, 0, self::MAX_ERROR_CHARS ) . '…';
		}
		if ( strlen( $stripped ) > self::MAX_ERROR_CHARS ) {
			return substr( $stripped, 0, self::MAX_ERROR_CHARS ) . '…';
		}
		return $stripped;
	}
}
