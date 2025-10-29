<?php
/**
 * Uninstall script for Meta Conversions API.
 *
 * @package Meta_Conversions_API
 */

// Exit if accessed directly or not uninstalling.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options.
delete_option('meta_capi_pixel_id');
delete_option('meta_capi_access_token');
delete_option('meta_capi_test_event_code');
delete_option('meta_capi_enable_page_view');
delete_option('meta_capi_enable_form_tracking');
delete_option('meta_capi_enable_logging');

// Delete log files.
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/meta-capi-logs';

if (is_dir($log_dir)) {
    // Delete all log files.
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            wp_delete_file($file);
        }
    }
    
    // Remove the directory.
    @rmdir($log_dir);
}

// Clear any scheduled cron jobs.
wp_clear_scheduled_hook('meta_capi_cleanup_logs');

