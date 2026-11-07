<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Circuit_Breaker {

	const OPTION_KEY = 'kunaki_wc_circuit_breaker';

	/**
	 * How long one probe holds the half-open state, in seconds. Longer than the
	 * API timeouts, so a probe that crashed without reporting can't block
	 * recovery but a slow one isn't joined by others.
	 */
	const PROBE_TIMEOUT = 60;

	/**
	 * Error codes that indicate infrastructure failure (trip the circuit).
	 * API business logic errors (kunaki_api_error) are excluded — those
	 * mean the API is responding correctly to a bad request.
	 */
	const TRIPPABLE_ERROR_CODES = array(
		'http_request_failed', // wp_remote_post failure (timeout, DNS, connection)
		'kunaki_http_error',   // Non-200 status code
		'kunaki_xml_error',    // Unparseable response
	);

	/**
	 * Check if the circuit allows a request through.
	 *
	 * @return true|WP_Error True if allowed, WP_Error if circuit is open.
	 */
	public function pre_request() {
		$raw   = get_option( self::OPTION_KEY, '' );
		$state = $this->decode_state( $raw );

		if ( 'closed' === $state['state'] ) {
			return true;
		}

		$wait = $this->seconds_until_probe( $state );

		// Only one request may probe. Others would each wait out a full API
		// timeout against a service that is probably still down — at checkout,
		// that's every customer.
		if ( 0 === $wait && $this->claim_probe( $raw, $state ) ) {
			Kunaki_Logger::info( 'Circuit breaker: half_open (allowing probe request).' );
			return true;
		}

		return new \WP_Error(
			'kunaki_circuit_open',
			sprintf(
				/* translators: %d: seconds until next retry */
				__( 'Kunaki API circuit breaker is open. Next retry in %d seconds.', 'kunaki-woocommerce' ),
				max( $wait, 1 )
			)
		);
	}

	/**
	 * Whether a request could currently go through, without claiming the probe.
	 *
	 * Batch jobs check this rather than the raw state: stopping whenever the
	 * state is "open" would mean they never probe, and a store with no checkout
	 * traffic would stay paused until someone intervened.
	 *
	 * @return bool
	 */
	public function allows_request() {
		$state = $this->get_state();
		return 'closed' === $state['state'] || 0 === $this->seconds_until_probe( $state );
	}

	/**
	 * Seconds until a probe may be sent. 0 if one may be sent now.
	 *
	 * @param array $state Circuit state (open or half_open).
	 * @return int
	 */
	private function seconds_until_probe( $state ) {
		if ( 'half_open' === $state['state'] ) {
			return max( 0, self::PROBE_TIMEOUT - ( time() - $state['probe_at'] ) );
		}

		$cooldown = absint( get_option( 'kunaki_wc_circuit_cooldown', 5 ) ) * MINUTE_IN_SECONDS;
		if ( $cooldown < MINUTE_IN_SECONDS ) {
			$cooldown = 5 * MINUTE_IN_SECONDS;
		}
		return max( 0, $cooldown - ( time() - $state['opened_at'] ) );
	}

	/**
	 * Atomically claim the probe. Exactly one concurrent caller wins.
	 *
	 * The update only matches if the stored state is unchanged since it was read,
	 * so a second process that read the same state updates no rows.
	 *
	 * @param string $raw   Stored option value the caller's decision was based on.
	 * @param array  $state That value, decoded.
	 * @return bool
	 */
	private function claim_probe( $raw, $state ) {
		global $wpdb;

		$state['state']    = 'half_open';
		$state['probe_at'] = time();

		$claimed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => wp_json_encode( $state ) ),
			array(
				'option_name'  => self::OPTION_KEY,
				'option_value' => $raw,
			)
		);
		wp_cache_delete( self::OPTION_KEY, 'options' );

		return 1 === $claimed;
	}

	/**
	 * Record the outcome of a request.
	 *
	 * @param SimpleXMLElement|WP_Error $result The result from send_request().
	 */
	public function post_request( $result ) {
		if ( is_wp_error( $result ) && $this->is_trippable_error( $result ) ) {
			$this->record_failure();
		} else {
			// Any non-infrastructure result (success or business logic error)
			// proves the API is responsive and breaks the failure streak.
			$this->record_success();
		}
	}

	/**
	 * Get the current circuit breaker state.
	 *
	 * @return array
	 */
	public function get_state() {
		return $this->decode_state( get_option( self::OPTION_KEY, '' ) );
	}

	/**
	 * Decode a stored circuit state.
	 *
	 * @param mixed $raw Stored option value.
	 * @return array
	 */
	private function decode_state( $raw ) {
		$default = array(
			'state'           => 'closed',
			'failure_count'   => 0,
			'last_failure_at' => 0,
			'opened_at'       => 0,
			'probe_at'        => 0,
		);

		if ( empty( $raw ) ) {
			return $default;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return $default;
		}

		return array_merge( $default, $decoded );
	}

	/**
	 * Manually reset the circuit breaker to closed.
	 */
	public function reset() {
		delete_option( self::OPTION_KEY );
		Kunaki_Logger::info( 'Circuit breaker: manually reset to closed.' );
	}

	/**
	 * Whether a WP_Error should count as a circuit-trippable failure.
	 *
	 * @param WP_Error $error The error to check.
	 * @return bool
	 */
	private function is_trippable_error( $error ) {
		return in_array( $error->get_error_code(), self::TRIPPABLE_ERROR_CODES, true );
	}

	private function record_failure() {
		$state = $this->get_state();
		$state['failure_count']++;
		$state['last_failure_at'] = time();

		$threshold = absint( get_option( 'kunaki_wc_circuit_threshold', 5 ) );
		if ( $threshold < 1 ) {
			$threshold = 5;
		}

		if ( 'half_open' === $state['state'] ) {
			$state['state']     = 'open';
			$state['opened_at'] = time();
			$this->save_state( $state );
			Kunaki_Logger::info( 'Circuit breaker: half_open -> open (probe request failed).' );
			return;
		}

		if ( 'closed' === $state['state'] && $state['failure_count'] >= $threshold ) {
			$state['state']     = 'open';
			$state['opened_at'] = time();
			$this->save_state( $state );
			Kunaki_Logger::info(
				sprintf( 'Circuit breaker: closed -> open after %d consecutive failures.', $state['failure_count'] )
			);
			return;
		}

		$this->save_state( $state );
	}

	private function record_success() {
		$state = $this->get_state();

		if ( 'closed' === $state['state'] && 0 === $state['failure_count'] ) {
			return;
		}

		$previous = $state['state'];

		$state['state']         = 'closed';
		$state['failure_count'] = 0;

		$this->save_state( $state );

		if ( 'half_open' === $previous ) {
			Kunaki_Logger::info( 'Circuit breaker: half_open -> closed (probe request succeeded).' );
		}
	}

	private function save_state( $state ) {
		update_option( self::OPTION_KEY, wp_json_encode( $state ), false );
	}
}
