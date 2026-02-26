<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'kunaki_wc_user_id' );
delete_option( 'kunaki_wc_password' );
delete_option( 'kunaki_wc_mode' );
delete_option( 'kunaki_wc_debug_log' );
delete_option( 'kunaki_wc_sync_interval' );
delete_option( 'kunaki_wc_decrypt_failed' );

wp_clear_scheduled_hook( 'kunaki_wc_sync_order_status' );
