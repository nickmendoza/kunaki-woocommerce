<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Circuit_Breaker {

	const OPTION_KEY = 'kunaki_wc_circuit_breaker';

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
		$state = $this->get_state();

		if ( 'closed' === $state['state'] ) {
			return true;
		}

		if ( 'open' === $state['state'] ) {
			$cooldown = absint( get_option( 'kunaki_wc_circuit_cooldown', 5 ) ) * MINUTE_IN_SECONDS;
			if ( $cooldown < MINUTE_IN_SECONDS ) {
				$cooldown = 5 * MINUTE_IN_SECONDS;
			}
			$elapsed = time() - $state['opened_at'];

			if ( $elapsed >= $cooldown ) {
				$state['state'] = 'half_open';
				$this->save_state( $state );
				Kunaki_Logger::info( 'Circuit breaker: open -> half_open (allowing probe request).' );
				return true;
			}

			return new \WP_Error(
				'kunaki_circuit_open',
				sprintf(
					/* translators: %d: seconds until next retry */
					__( 'Kunaki API circuit breaker is open. Next retry in %d seconds.', 'kunaki-woocommerce' ),
					$cooldown - $elapsed
				)
			);
		}

		// half_open: allow the probe through.
		return true;
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
		$default = array(
			'state'           => 'closed',
			'failure_count'   => 0,
			'last_failure_at' => 0,
			'opened_at'       => 0,
		);

		$raw = get_option( self::OPTION_KEY, '' );

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
