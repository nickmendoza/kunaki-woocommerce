<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Logger {

	const SOURCE = 'kunaki-woocommerce';

	public static function log( $level, $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$entry  = array_merge( array( 'message' => $message ), $context );
		$logger = wc_get_logger();
		$logger->log( $level, wp_json_encode( $entry ), array( 'source' => self::SOURCE ) );
	}

	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	public static function debug( $message, $context = array() ) {
		if ( 'yes' !== get_option( 'kunaki_wc_debug_log', 'no' ) ) {
			return;
		}
		self::log( 'debug', $message, $context );
	}
}
