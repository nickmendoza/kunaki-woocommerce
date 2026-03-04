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

wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
wp_clear_scheduled_hook( 'kunaki_wc_retry_failed_orders' );

// Clean up dynamic per-order options (locks, durable markers).
global $wpdb;
$patterns = array(
	'_kunaki_lock_',
	'_kunaki_sync_lock_',
	'_kunaki_submitted_',
	'_kunaki_failed_',
);
foreach ( $patterns as $prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $prefix ) . '%'
	) );
}

// Clean up dashboard widget transient.
delete_transient( 'kunaki_dashboard_counts' );

// Clean up order meta (postmeta and HPOS custom order tables).
$order_meta_keys = array(
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
);
foreach ( $order_meta_keys as $meta_key ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$meta_key
	) );
}

// HPOS custom order meta table (if WooCommerce created it).
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta_table ) ) === $hpos_meta_table ) {
	foreach ( $order_meta_keys as $meta_key ) {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$hpos_meta_table} WHERE meta_key = %s",
			$meta_key
		) );
	}
}

// Clean up product meta.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
	'_kunaki_product_id'
) );

// Clean up transients (shipping rate cache + validation errors).
$transient_patterns = array(
	'_transient_kunaki_ship_',
	'_transient_timeout_kunaki_ship_',
	'_transient_kunaki_wc_product_meta_error_',
	'_transient_timeout_kunaki_wc_product_meta_error_',
);
foreach ( $transient_patterns as $prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $prefix ) . '%'
	) );
}
