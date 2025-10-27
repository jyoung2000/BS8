<?php
/**
 * Box API Integration - Uninstall Script
 * 
 * This file runs when the plugin is deleted through WordPress admin
 * It removes all plugin data from the database
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all Box API options
$options_to_delete = array(
    // Credentials
    'box_client_id',
    'box_client_secret',
    'box_enterprise_id',
    'box_redirect_uri',
    
    // Tokens
    'box_access_token',
    'box_refresh_token',
    'box_token_expires',
    
    // User info
    'box_user_id',
    'box_user_email',
    
    // Settings
    'box_default_folder_id',
    
    // Transients
    'box_folder_cache',
    'box_file_cache'
);

// Delete options
foreach ($options_to_delete as $option) {
    delete_option($option);
    delete_site_option($option); // For multisite
}

// Delete transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_box_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_box_%'");

// Delete custom database table
$table_name = $wpdb->prefix . 'box_api_logs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Clear any scheduled cron jobs
$timestamp = wp_next_scheduled('box_refresh_token_cron');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'box_refresh_token_cron');
}

// Clear all Box-related cron jobs
wp_clear_scheduled_hook('box_refresh_token_cron');
wp_clear_scheduled_hook('box_cleanup_logs');
wp_clear_scheduled_hook('box_sync_files');

// Remove any user meta related to Box
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'box_%'");

// Remove any post meta related to Box
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_box_%'");

// Clean up any temporary files
$upload_dir = wp_upload_dir();
$box_temp_dir = $upload_dir['basedir'] . '/box-temp';
if (is_dir($box_temp_dir)) {
    // Recursively delete temp directory
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($box_temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    
    rmdir($box_temp_dir);
}

// Clear WordPress cache
wp_cache_flush();

// Log the uninstall (if logging is still available)
error_log('Box API Integration plugin has been uninstalled and all data has been removed.');
