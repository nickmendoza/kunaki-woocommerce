<?php
/**
 * Plugin Name: Disc Fulfillment for WooCommerce
 * Plugin URI:  https://mobilecodemedia.com/kunaki-woocommerce
 * Description: Integrates Kunaki CD/DVD manufacturing and fulfillment with WooCommerce — live shipping rates, automatic order submission, and order status tracking.
 * Version:     1.0.0
 * Author:      MobileCodeMedia
 * Author URI:  https://mobilecodemedia.com
 * License:     Proprietary
 * License URI:
 * Text Domain: kunaki-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'KUNAKI_WC_VERSION', '1.0.0' );
define( 'KUNAKI_WC_PLUGIN_FILE', __FILE__ );
define( 'KUNAKI_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KUNAKI_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KUNAKI_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Declare HPOS compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// Check WooCommerce dependency.
function kunaki_wc_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>';
			esc_html_e( 'Kunaki WooCommerce Integration requires WooCommerce to be installed and active.', 'kunaki-woocommerce' );
			echo '</p></div>';
		} );
		return false;
	}
	return true;
}

// esc_xml polyfill for WordPress < 5.5.
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $text ) {
		$safe_text = wp_check_invalid_utf8( $text );
		$safe_text = str_replace(
			array( '&', '<', '>', '"', "'" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '&apos;' ),
			$safe_text
		);
		return $safe_text;
	}
}

// Register password encrypt/decrypt filters early so they're active in all contexts.
// Cannot call Kunaki_Settings directly here because it extends WC_Settings_Page
// which isn't loaded yet. Use inline closures that delegate to the class once available.
add_filter( 'pre_update_option_kunaki_wc_password', 'kunaki_wc_encrypt_password', 10, 1 );
add_filter( 'option_kunaki_wc_password', 'kunaki_wc_decrypt_password', 10, 1 );

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
		return $value;
	}
	return $decrypted;
}

// Register custom cron schedule early so it exists during activation.
add_filter( 'cron_schedules', function ( $schedules ) {
	$interval = absint( get_option( 'kunaki_wc_sync_interval', 15 ) );
	if ( $interval < 5 ) {
		$interval = 15;
	}
	$schedules['kunaki_every_15_minutes'] = array(
		'interval' => $interval * 60,
		'display'  => sprintf(
			/* translators: %d: number of minutes */
			__( 'Every %d Minutes (Kunaki)', 'kunaki-woocommerce' ),
			$interval
		),
	);
	return $schedules;
} );

// Initialize the plugin.
add_action( 'plugins_loaded', function () {
	if ( ! kunaki_wc_check_woocommerce() ) {
		return;
	}
	require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-woocommerce.php';
	Kunaki_WooCommerce::instance();
}, 10 );

// Activation: schedule cron.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'kunaki_wc_sync_order_status' ) ) {
		wp_schedule_event( time(), 'kunaki_every_15_minutes', 'kunaki_wc_sync_order_status' );
	}
} );

// Deactivation: unschedule cron.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
} );
