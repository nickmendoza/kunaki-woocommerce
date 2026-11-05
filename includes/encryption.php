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
		if ( '' === $value ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'OpenSSL not available — Kunaki password stored unencrypted.', array( 'source' => 'kunaki-woocommerce' ) );
			}
			return $value;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( 12 ); // GCM uses 12-byte nonce.
		$tag = '';

		$encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'openssl_encrypt failed — Kunaki password stored unencrypted.', array( 'source' => 'kunaki-woocommerce' ) );
			}
			return $value;
		}

		// Password re-saved successfully — clear any previous decrypt failure flag.
		delete_option( 'kunaki_wc_decrypt_failed' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $tag . $encrypted );
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

		// Encrypted values are a 12-byte IV + 16-byte tag + at least 1 byte of
		// ciphertext. Anything shorter is a plaintext password that happens to
		// be valid base64, not a failed decryption.
		if ( strlen( $data ) <= 28 ) {
			return $value;
		}

		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$decrypted = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
		$flagged   = 'yes' === get_option( 'kunaki_wc_decrypt_failed' );

		if ( false !== $decrypted ) {
			// The password works again (e.g. the previous salts were restored).
			if ( $flagged ) {
				delete_option( 'kunaki_wc_decrypt_failed' );
			}
			return $decrypted;
		}

		// This runs on every read of the option, so only report it once.
		if ( ! $flagged ) {
			update_option( 'kunaki_wc_decrypt_failed', 'yes', 'no' );

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Failed to decrypt Kunaki password. Auth salts may have changed.', array( 'source' => 'kunaki-woocommerce' ) );
			}
		}
		return '';
	}
}
