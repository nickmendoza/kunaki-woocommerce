<?php
/**
 * Plugin Name: Kunaki WooCommerce
 * Plugin URI:  https://github.com/nickmendoza/kunaki-woocommerce
 * Description: Integrates Kunaki CD/DVD manufacturing and fulfillment with WooCommerce — live shipping rates, automatic order submission, and order status tracking.
 * Version:     1.3.4
 * Author:      MobileCodeMedia
 * Author URI:  https://mobilecodemedia.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kunaki-woocommerce
 * Domain Path:
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'KUNAKI_WC_VERSION', '1.3.4' );
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

// Load password encrypt/decrypt helpers.
require_once __DIR__ . '/includes/encryption.php';

// Register password encrypt/decrypt filters early so they're active in all contexts.
add_filter( 'pre_update_option_kunaki_wc_password', 'kunaki_wc_encrypt_password', 10, 1 );
add_filter( 'option_kunaki_wc_password', 'kunaki_wc_decrypt_password', 10, 1 );

// Server-side clamping for numeric settings (HTML min/max is client-side only).
function kunaki_wc_clamp_option( $value, $min, $max, $default ) {
	$value = absint( $value );
	if ( $value < $min || $value > $max ) {
		return $default;
	}
	return $value;
}
add_filter( 'pre_update_option_kunaki_wc_sync_interval', function ( $value ) {
	return kunaki_wc_clamp_option( $value, 5, 1440, 15 );
} );
add_filter( 'pre_update_option_kunaki_wc_max_retries', function ( $value ) {
	return kunaki_wc_clamp_option( $value, 1, 10, 3 );
} );
add_filter( 'pre_update_option_kunaki_wc_circuit_threshold', function ( $value ) {
	return kunaki_wc_clamp_option( $value, 2, 20, 5 );
} );
add_filter( 'pre_update_option_kunaki_wc_circuit_cooldown', function ( $value ) {
	return kunaki_wc_clamp_option( $value, 1, 60, 5 );
} );

// Validate Slack webhook URL — accept only hooks.slack.com hosts to prevent SSRF via arbitrary URLs.
function kunaki_wc_validate_slack_webhook( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	if ( ! wp_http_validate_url( $value ) ) {
		return '';
	}
	$host = wp_parse_url( $value, PHP_URL_HOST );
	if ( ! is_string( $host ) || 'hooks.slack.com' !== strtolower( $host ) ) {
		return '';
	}
	return esc_url_raw( $value );
}
add_filter( 'pre_update_option_kunaki_wc_alert_slack_webhook', 'kunaki_wc_validate_slack_webhook' );
// Also validate on first save (when the option row doesn't yet exist) so
// WP-CLI, imports, or direct add_option() calls can't bypass the allowlist.
add_filter( 'pre_add_option_kunaki_wc_alert_slack_webhook', 'kunaki_wc_validate_slack_webhook' );

// Show admin notice when Kunaki is in test mode.
add_action( 'admin_notices', function () {
	if ( 'TEST' !== strtoupper( get_option( 'kunaki_wc_mode', 'TEST' ) ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=kunaki' );
	echo '<div class="notice notice-warning"><p>';
	echo wp_kses_post( sprintf(
		/* translators: %s: URL to Kunaki settings page */
		__( '<strong>Kunaki WooCommerce:</strong> Test mode is active — orders will not be manufactured. Make sure your payment gateway is also in test mode to avoid real charges. <a href="%s">Change mode</a>', 'kunaki-woocommerce' ),
		esc_url( $settings_url )
	) );
	echo '</p></div>';
} );

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

// Show admin notice when circuit breaker is open.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	// Read option directly — class may not be loaded yet during early admin_notices.
	$raw = get_option( 'kunaki_wc_circuit_breaker', '' );
	if ( empty( $raw ) ) {
		return;
	}
	$state = json_decode( $raw, true );
	if ( ! is_array( $state ) || 'open' !== ( $state['state'] ?? '' ) ) {
		return;
	}
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=kunaki' );
	$opened_ago   = human_time_diff( $state['opened_at'] ?? time() );
	echo '<div class="error"><p>';
	echo wp_kses_post( sprintf(
		/* translators: 1: time since circuit opened, 2: URL to settings page */
		__( '<strong>Kunaki WooCommerce:</strong> The API circuit breaker has been open for %1$s — order submissions and status syncs are paused. The plugin will automatically probe for recovery. <a href="%2$s">View settings</a>', 'kunaki-woocommerce' ),
		esc_html( $opened_ago ),
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

	// Register WP-CLI commands.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-cli-command.php';
		WP_CLI::add_command( 'kunaki', 'Kunaki_CLI_Command' );
	}
}, 10 );

// Reschedule cron when sync interval changes.
add_action( 'update_option_kunaki_wc_sync_interval', function () {
	wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
	wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
	wp_clear_scheduled_hook( 'kunaki_wc_retry_failed_orders' );
	wp_schedule_event( time() + 60, 'kunaki_sync_interval', 'kunaki_wc_retry_failed_orders' );
} );

// Migrate cron from old schedule name (kunaki_every_15_minutes → kunaki_sync_interval).
// Also auto-schedule retry cron for existing installs that update without deactivating.
add_action( 'init', function () {
	$scheduled = wp_get_schedule( 'kunaki_wc_sync_order_status' );
	if ( 'kunaki_every_15_minutes' === $scheduled ) {
		wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
		wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
	}
	if ( ! wp_next_scheduled( 'kunaki_wc_retry_failed_orders' ) ) {
		wp_schedule_event( time() + 60, 'kunaki_sync_interval', 'kunaki_wc_retry_failed_orders' );
	}
} );

// Activation: schedule cron.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'kunaki_wc_sync_order_status' ) ) {
		wp_schedule_event( time(), 'kunaki_sync_interval', 'kunaki_wc_sync_order_status' );
	}
	if ( ! wp_next_scheduled( 'kunaki_wc_retry_failed_orders' ) ) {
		wp_schedule_event( time() + 60, 'kunaki_sync_interval', 'kunaki_wc_retry_failed_orders' );
	}
} );

// Deactivation: unschedule cron and reset circuit breaker.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
	wp_clear_scheduled_hook( 'kunaki_wc_retry_failed_orders' );
	delete_option( 'kunaki_wc_circuit_breaker' );
} );
