<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Logger {

	const SOURCE = 'kunaki-woocommerce';

	public static function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	public static function info( $message ) {
		self::log( 'info', $message );
	}

	public static function error( $message ) {
		self::log( 'error', $message );
	}

	public static function debug( $message ) {
		if ( 'yes' !== get_option( 'kunaki_wc_debug_log', 'no' ) ) {
			return;
		}
		self::log( 'debug', $message );
	}
}
