<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'kunaki_wc_user_id' );
delete_option( 'kunaki_wc_password' );
delete_option( 'kunaki_wc_mode' );
delete_option( 'kunaki_wc_debug_log' );
delete_option( 'kunaki_wc_sync_interval' );
delete_option( 'kunaki_wc_decrypt_failed' );
delete_option( 'kunaki_wc_max_retries' );

wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
wp_clear_scheduled_hook( 'kunaki_wc_retry_failed_orders' );

// Clean up dynamic per-order options (locks, durable markers).
global $wpdb;
$patterns = array(
	'\_kunaki\_lock\_%',
	'\_kunaki\_sync\_lock\_%',
	'\_kunaki\_submitted\_%',
	'\_kunaki\_failed\_%',
);
foreach ( $patterns as $pattern ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$pattern
	) );
}

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
