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
		$iv  = openssl_random_pseudo_bytes( 12 ); // GCM uses 12-byte nonce.
		$tag = '';

		$encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
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

		// Too short to be any encrypted format — likely a plaintext password.
		if ( strlen( $data ) <= 16 ) {
			return $value;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		// Try AES-256-GCM first (current format: 12-byte IV + 16-byte tag + ciphertext).
		if ( strlen( $data ) > 28 ) {
			$iv        = substr( $data, 0, 12 );
			$tag       = substr( $data, 12, 16 );
			$encrypted = substr( $data, 28 );

			$decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		// Legacy fallback: AES-256-CBC (16-byte IV + ciphertext, no tag).
		$cbc_iv_length = 16;
		if ( strlen( $data ) > $cbc_iv_length ) {
			$iv        = substr( $data, 0, $cbc_iv_length );
			$encrypted = substr( $data, $cbc_iv_length );

			$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		// Both failed — flag for admin notice.
		update_option( 'kunaki_wc_decrypt_failed', 'yes', 'no' );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'Failed to decrypt Kunaki password. Auth salts may have changed.', array( 'source' => 'kunaki-woocommerce' ) );
		}
		return '';
	}
}
