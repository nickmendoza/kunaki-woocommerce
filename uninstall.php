<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'kunaki_wc_user_id' );
delete_option( 'kunaki_wc_password' );
delete_option( 'kunaki_wc_mode' );
delete_option( 'kunaki_wc_debug_log' );
delete_option( 'kunaki_wc_sync_interval' );
delete_option( 'kunaki_wc_decrypt_failed' );
delete_option( 'kunaki_wc_max_retries' );
delete_option( 'kunaki_wc_circuit_breaker' );
delete_option( 'kunaki_wc_circuit_threshold' );
delete_option( 'kunaki_wc_circuit_cooldown' );
delete_option( 'kunaki_wc_alerts_enabled' );
delete_option( 'kunaki_wc_alert_email_enabled' );
delete_option( 'kunaki_wc_alert_email_address' );
delete_option( 'kunaki_wc_alert_slack_enabled' );
delete_option( 'kunaki_wc_alert_slack_webhook' );

wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
wp_clear_scheduled_hook( 'kunaki_wc_retry_failed_orders' );
wp_clear_scheduled_hook( 'kunaki_wc_send_alert' );

// Clean up dynamic per-order options (locks, durable markers, alert debounce claims).
global $wpdb;
$kunaki_wc_patterns = array(
	'_kunaki_lock_',
	'_kunaki_sync_lock_',
	'_kunaki_submitted_',
	'_kunaki_failed_',
	'kunaki_wc_alert_claim_',
);
foreach ( $kunaki_wc_patterns as $kunaki_wc_prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $kunaki_wc_prefix ) . '%'
	) );
}

// Clean up Plugin Update Checker data.
delete_option( 'external_updates-kunaki-woocommerce' );
delete_site_transient( 'puc_manual_check_errors-kunaki-woocommerce' );

// Clean up dashboard widget transient.
delete_transient( 'kunaki_dashboard_counts' );

// Clean up delivery estimator transient.
delete_transient( 'kunaki_manufacturing_stats' );

// Clean up order meta (postmeta and HPOS custom order tables).
$kunaki_wc_order_meta_keys = array(
	'_kunaki_order_id',
	'_kunaki_submitted',
	'_kunaki_fulfillment_error',
	'_kunaki_fulfillment_failed_at',
	'_kunaki_fulfillment_attempts',
	'_kunaki_fulfillment_exhausted',
	'_kunaki_all_items',
	'_kunaki_order_status',
	'_kunaki_tracking_type',
	'_kunaki_tracking_id',
	'_kunaki_sync_note_pending',
	'_kunaki_submitted_at',
	'_kunaki_shipped_at',
);
foreach ( $kunaki_wc_order_meta_keys as $kunaki_wc_meta_key ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$kunaki_wc_meta_key
	) );
}

// HPOS custom order meta table (if WooCommerce created it).
$kunaki_wc_hpos_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kunaki_wc_hpos_table ) ) === $kunaki_wc_hpos_table ) {
	foreach ( $kunaki_wc_order_meta_keys as $kunaki_wc_meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM `' . esc_sql( $kunaki_wc_hpos_table ) . '` WHERE meta_key = %s',
			$kunaki_wc_meta_key
		) );
	}
}

// Clean up product meta.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
	'_kunaki_product_id'
) );

// Clean up transients (shipping rate cache + validation errors).
$kunaki_wc_transient_patterns = array(
	'_transient_kunaki_ship_',
	'_transient_timeout_kunaki_ship_',
	'_transient_kunaki_wc_product_meta_error_',
	'_transient_timeout_kunaki_wc_product_meta_error_',
);
foreach ( $kunaki_wc_transient_patterns as $kunaki_wc_prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $kunaki_wc_prefix ) . '%'
	) );
}
