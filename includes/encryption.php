<?php
/**
 * Kunaki WooCommerce password encryption/decryption helpers.
 *
 * Extracted into a standalone file so it can be loaded without triggering
 * the main plugin bootstrap side effects (constants, hooks, cron, etc.).
 *
 * @package Kunaki_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'kunaki_wc_encrypt_password' ) ) {

	function kunaki_wc_encrypt_password( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );

		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $encrypted );
	}

	function kunaki_wc_decrypt_password( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $value, true );
		if ( false === $data ) {
			return $value;
		}
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( strlen( $data ) <= $iv_length ) {
			return $value;
		}
		$iv        = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );
		$key       = hash( 'sha256', wp_salt( 'auth' ), true );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $decrypted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Failed to decrypt Kunaki password. Auth salts may have changed.', array( 'source' => 'kunaki-woocommerce' ) );
			}
			return '';
		}
		return $decrypted;
	}
}
