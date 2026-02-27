<?php
/**
 * Plugin Name: Kunaki WooCommerce
 * Plugin URI:  https://mobilecodemedia.com/kunaki-woocommerce
 * Description: Integrates Kunaki CD/DVD manufacturing and fulfillment with WooCommerce — live shipping rates, automatic order submission, and order status tracking.
 * Version:     1.1.0
 * Author:      MobileCodeMedia
 * Author URI:  https://mobilecodemedia.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kunaki-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'KUNAKI_WC_VERSION', '1.1.0' );
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

// Load password encrypt/decrypt helpers.
require_once __DIR__ . '/includes/encryption.php';

// Register password encrypt/decrypt filters early so they're active in all contexts.
add_filter( 'pre_update_option_kunaki_wc_password', 'kunaki_wc_encrypt_password', 10, 1 );
add_filter( 'option_kunaki_wc_password', 'kunaki_wc_decrypt_password', 10, 1 );

// Show admin notice when password decryption fails (e.g. after salt rotation).
add_action( 'admin_notices', function () {
	if ( 'yes' !== get_option( 'kunaki_wc_decrypt_failed' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=kunaki' );
	echo '<div class="error"><p>';
	echo wp_kses_post( sprintf(
		/* translators: %s: URL to Kunaki settings page */
		__( '<strong>Kunaki WooCommerce:</strong> Your stored password could not be decrypted — this usually means your WordPress security salts have changed. Please <a href="%s">re-enter your Kunaki password</a> to restore order fulfillment.', 'kunaki-woocommerce' ),
		esc_url( $settings_url )
	) );
	echo '</p></div>';
} );

// Register custom cron schedule early so it exists during activation.
add_filter( 'cron_schedules', function ( $schedules ) {
	$interval = absint( get_option( 'kunaki_wc_sync_interval', 15 ) );
	if ( $interval < 5 ) {
		$interval = 15;
	}
	$schedules['kunaki_sync_interval'] = array(
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

// Reschedule cron when sync interval changes.
add_action( 'update_option_kunaki_wc_sync_interval', function () {
	wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
	wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
} );

// Migrate cron from old schedule name (kunaki_every_15_minutes → kunaki_sync_interval).
add_action( 'init', function () {
	$scheduled = wp_get_schedule( 'kunaki_wc_sync_order_status' );
	if ( 'kunaki_every_15_minutes' === $scheduled ) {
		wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
		wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
	}
} );

// Activation: schedule cron.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'kunaki_wc_sync_order_status' ) ) {
		wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
	}
} );

// Deactivation: unschedule cron.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
} );
