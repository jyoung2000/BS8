<?php
/**
 * Upload Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-upload"></span>
        <?php _e('Upload Files to Box', 'box-api-integration'); ?>
    </h1>
    
    <div class="box-upload-container">
        
        <!-- Upload Destination -->
        <div class="box-section">
            <h2><?php _e('Upload Destination', 'box-api-integration'); ?></h2>
            
            <p>
                <label for="upload-destination"><?php _e('Select folder:', 'box-api-integration'); ?></label>
                <?php Box_Admin::render_folder_select('0', 'upload_destination', 'upload-destination'); ?>
            </p>
        </div>
        
        <!-- Upload Area -->
        <div class="box-section">
            <h2><?php _e('Select Files', 'box-api-integration'); ?></h2>
            
            <?php Box_Admin::render_upload_form(); ?>
        </div>
        
        <!-- Upload Options -->
        <div class="box-section">
            <h2><?php _e('Upload Options', 'box-api-integration'); ?></h2>
            
            <p>
                <label>
                    <input type="checkbox" id="auto-rename" checked />
                    <?php _e('Auto-rename if file exists', 'box-api-integration'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" id="create-folders" />
                    <?php _e('Create folder structure from file paths', 'box-api-integration'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" id="notify-upload" />
                    <?php _e('Send email notification when complete', 'box-api-integration'); ?>
                </label>
            </p>
        </div>
        
        <!-- Upload from URL -->
        <div class="box-section">
            <h2><?php _e('Upload from URL', 'box-api-integration'); ?></h2>
            
            <p><?php _e('Enter URLs to download and upload files directly to Box (one per line):', 'box-api-integration'); ?></p>
            
            <textarea id="upload-urls" rows="5" class="large-text" placeholder="https://example.com/file.pdf"></textarea>
            
            <p>
                <button type="button" class="button button-primary" id="upload-from-url">
                    <?php _e('Upload from URLs', 'box-api-integration'); ?>
                </button>
            </p>
        </div>
        
        <!-- Recent Uploads -->
        <div class="box-section">
            <h2><?php _e('Recent Uploads', 'box-api-integration'); ?></h2>
            
            <div id="recent-uploads">
                <?php
                global $wpdb;
                $table_name = $wpdb->prefix . 'box_api_logs';
                
                $recent_uploads = $wpdb->get_results(
                    "SELECT * FROM $table_name 
                     WHERE action = 'file_upload' AND status = 'success'
                     ORDER BY created_at DESC 
                     LIMIT 10"
                );
                
                if (empty($recent_uploads)) {
                    echo '<p>' . __('No recent uploads', 'box-api-integration') . '</p>';
                } else {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr>';
                    echo '<th>' . __('File Name', 'box-api-integration') . '</th>';
                    echo '<th>' . __('Uploaded', 'box-api-integration') . '</th>';
                    echo '<th>' . __('Actions', 'box-api-integration') . '</th>';
                    echo '</tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($recent_uploads as $upload) {
                        echo '<tr>';
                        echo '<td>' . esc_html($upload->file_name) . '</td>';
                        echo '<td>' . human_time_diff(strtotime($upload->created_at), current_time('timestamp')) . ' ' . __('ago', 'box-api-integration') . '</td>';
                        echo '<td>';
                        echo '<button type="button" class="button button-small view-file" data-file-id="' . esc_attr($upload->file_id) . '">' . __('View', 'box-api-integration') . '</button>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                }
                ?>
            </div>
        </div>
        
        <!-- Upload Tips -->
        <div class="box-section">
            <h2><?php _e('Upload Tips', 'box-api-integration'); ?></h2>
            
            <ul>
                <li><?php _e('Maximum file size for direct upload: 50MB', 'box-api-integration'); ?></li>
                <li><?php _e('Larger files will be uploaded in chunks automatically', 'box-api-integration'); ?></li>
                <li><?php _e('Supported file types: Documents, Images, Videos, Audio, Archives', 'box-api-integration'); ?></li>
                <li><?php _e('You can drag and drop multiple files at once', 'box-api-integration'); ?></li>
                <li><?php _e('File names must be unique within each folder', 'box-api-integration'); ?></li>
            </ul>
        </div>
        
    </div>
</div>
