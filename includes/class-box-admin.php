<?php
/**
 * Box Admin Class
 * Handles admin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Admin {
    
    /**
     * Get connection status HTML
     */
    public static function get_connection_status() {
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $auth_status = Box_Auth::get_auth_status();

        ob_start();
        ?>
        <div class="box-connection-status">
            <?php if ($auth_status['authenticated']) : ?>
                <div class="notice notice-success inline">
                    <p>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php echo esc_html($auth_status['message']); ?>
                        <?php
                        $user_email = get_option('box_user_email');
                        if ($user_email) {
                            echo ' - ' . esc_html($user_email);
                        }
                        ?>
                    </p>
                    <p>
                        <?php if ($auth_status['expired']) : ?>
                            <button type="button" class="button" id="refresh-token">
                                <?php _e('Refresh Token', 'box-api-integration'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button button-secondary" id="box-logout" style="margin-left: 5px;">
                            <span class="dashicons dashicons-exit" style="margin-top: 3px;"></span>
                            <?php _e('Logout from Box', 'box-api-integration'); ?>
                        </button>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html($auth_status['message']); ?>
                    </p>
                    <?php if (!empty($credentials['client_id']) && !empty($credentials['client_secret'])) : ?>
                        <p>
                            <a href="<?php echo esc_url(self::get_auth_url()); ?>" class="button button-primary">
                                <?php _e('Authorize with Box', 'box-api-integration'); ?>
                            </a>
                        </p>
                    <?php else : ?>
                        <p><?php _e('Please configure your API credentials first.', 'box-api-integration'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get authorization URL
     */
    public static function get_auth_url() {
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $auth = new Box_Auth($credentials);
        return $auth->get_authorization_url();
    }
    
    /**
     * Render folder tree select
     */
    public static function render_folder_select($selected = '0', $name = 'folder_id', $id = null) {
        if (!$id) {
            $id = $name;
        }

        // Check if user is authenticated before making API calls
        $auth_status = Box_Auth::get_auth_status();

        ?>
        <select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" class="box-folder-select">
            <option value="0"><?php _e('Root Folder', 'box-api-integration'); ?></option>
            <?php
            // Only load folder tree if authenticated and not expired
            if ($auth_status['authenticated'] && !$auth_status['expired']) {
                $credentials = Box_API_Integration::get_instance()->get_credentials();
                $folder_manager = new Box_Folder_Manager($credentials);
                $folders = $folder_manager->get_folder_tree();

                // Handle errors gracefully
                if (!is_wp_error($folders) && is_array($folders)) {
                    self::render_folder_options($folders, $selected);
                }
            }
            ?>
        </select>
        <?php
    }
    
    /**
     * Render folder options recursively
     */
    private static function render_folder_options($folders, $selected, $indent = 0) {
        foreach ($folders as $folder) {
            $padding = str_repeat('&nbsp;', $indent * 4);
            ?>
            <option value="<?php echo esc_attr($folder['id']); ?>" <?php selected($selected, $folder['id']); ?>>
                <?php echo $padding . '📁 ' . esc_html($folder['name']); ?>
            </option>
            <?php
            if (!empty($folder['children'])) {
                self::render_folder_options($folder['children'], $selected, $indent + 1);
            }
        }
    }
    
    /**
     * Render file browser
     */
    public static function render_file_browser($folder_id = '0') {
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);
        
        $items = $client->list_folder_items($folder_id);
        
        if (is_wp_error($items)) {
            echo '<div class="notice notice-error"><p>' . esc_html($items->get_error_message()) . '</p></div>';
            return;
        }
        
        ?>
        <div class="box-file-browser">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" class="check-all">
                        </th>
                        <th><?php _e('Name', 'box-api-integration'); ?></th>
                        <th><?php _e('Size', 'box-api-integration'); ?></th>
                        <th><?php _e('Modified', 'box-api-integration'); ?></th>
                        <th><?php _e('Actions', 'box-api-integration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items['entries'])) : ?>
                        <tr>
                            <td colspan="5" class="no-items">
                                <?php _e('No files or folders found', 'box-api-integration'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($items['entries'] as $item) : ?>
                            <?php self::render_file_row($item); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render file row
     */
    private static function render_file_row($item) {
        $is_folder = $item['type'] === 'folder';
        $icon = $is_folder ? 'dashicons-category' : Box_File_Manager::get_file_icon($item['name']);
        
        ?>
        <tr data-id="<?php echo esc_attr($item['id']); ?>" data-type="<?php echo esc_attr($item['type']); ?>">
            <th scope="row" class="check-column">
                <input type="checkbox" name="items[]" value="<?php echo esc_attr($item['id']); ?>">
            </th>
            <td class="name column-name">
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <?php if ($is_folder) : ?>
                    <a href="#" class="folder-link" data-folder-id="<?php echo esc_attr($item['id']); ?>">
                        <strong><?php echo esc_html($item['name']); ?></strong>
                    </a>
                <?php else : ?>
                    <strong><?php echo esc_html($item['name']); ?></strong>
                <?php endif; ?>
            </td>
            <td class="size column-size">
                <?php echo $is_folder ? '-' : size_format($item['size']); ?>
            </td>
            <td class="modified column-modified">
                <?php echo human_time_diff(strtotime($item['modified_at']), current_time('timestamp')) . ' ' . __('ago', 'box-api-integration'); ?>
            </td>
            <td class="actions column-actions">
                <?php if (!$is_folder) : ?>
                    <a href="#" class="button button-small download-file" data-file-id="<?php echo esc_attr($item['id']); ?>">
                        <?php _e('Download', 'box-api-integration'); ?>
                    </a>
                    <a href="#" class="button button-small share-file" data-file-id="<?php echo esc_attr($item['id']); ?>">
                        <?php _e('Share', 'box-api-integration'); ?>
                    </a>
                <?php endif; ?>
                <a href="#" class="button button-small delete-item" data-id="<?php echo esc_attr($item['id']); ?>" data-type="<?php echo esc_attr($item['type']); ?>">
                    <?php _e('Delete', 'box-api-integration'); ?>
                </a>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render upload form
     */
    public static function render_upload_form($folder_id = '0') {
        ?>
        <div class="box-upload-form">
            <form id="box-file-upload" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('box_upload_file', 'box_upload_nonce'); ?>
                
                <input type="hidden" name="folder_id" value="<?php echo esc_attr($folder_id); ?>">
                
                <div class="upload-area">
                    <div class="drag-drop-area">
                        <p class="drag-drop-info">
                            <?php _e('Drop files here to upload', 'box-api-integration'); ?>
                        </p>
                        <p><?php _e('or', 'box-api-integration'); ?></p>
                        <p>
                            <input type="file" name="files[]" id="box-file-input" multiple>
                            <label for="box-file-input" class="button">
                                <?php _e('Select Files', 'box-api-integration'); ?>
                            </label>
                        </p>
                        <p class="max-upload-size">
                            <?php printf(__('Maximum upload file size: %s', 'box-api-integration'), size_format(wp_max_upload_size())); ?>
                        </p>
                    </div>
                </div>
                
                <div class="upload-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <p class="progress-text"></p>
                </div>
                
                <div class="upload-results" style="display: none;">
                    <h3><?php _e('Upload Results', 'box-api-integration'); ?></h3>
                    <ul class="results-list"></ul>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render recent activity
     */
    public static function render_recent_activity($limit = 20) {
        $logs = Box_API_Client::get_recent_logs($limit);
        
        ?>
        <div class="box-recent-activity">
            <h3><?php _e('Recent Activity', 'box-api-integration'); ?></h3>
            
            <?php if (empty($logs)) : ?>
                <p><?php _e('No activity yet', 'box-api-integration'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Time', 'box-api-integration'); ?></th>
                            <th><?php _e('Action', 'box-api-integration'); ?></th>
                            <th><?php _e('File/Folder', 'box-api-integration'); ?></th>
                            <th><?php _e('Status', 'box-api-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo human_time_diff(strtotime($log->created_at), current_time('timestamp')) . ' ' . __('ago', 'box-api-integration'); ?></td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td><?php echo esc_html($log->file_name ?: '-'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render storage info
     */
    public static function render_storage_info() {
        // Check if user is authenticated before making API calls
        $auth_status = Box_Auth::get_auth_status();

        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            // Don't make API calls if not authenticated or token expired
            return;
        }

        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);
        $storage = $client->get_storage_info();

        // Gracefully handle API errors - don't disrupt the page or authentication
        if (empty($storage) || is_wp_error($storage)) {
            return;
        }

        $used = $storage['space_used'];
        $total = $storage['space_amount'];
        $percentage = $total > 0 ? round(($used / $total) * 100) : 0;

        ?>
        <div class="box-storage-info">
            <h3><?php _e('Storage Usage', 'box-api-integration'); ?></h3>

            <div class="storage-bar">
                <div class="storage-used" style="width: <?php echo $percentage; ?>%"></div>
            </div>

            <p>
                <?php printf(
                    __('%s of %s used (%d%%)', 'box-api-integration'),
                    size_format($used),
                    size_format($total),
                    $percentage
                ); ?>
            </p>

            <?php if ($storage['max_upload_size'] > 0) : ?>
                <p>
                    <?php printf(
                        __('Maximum file size: %s', 'box-api-integration'),
                        size_format($storage['max_upload_size'])
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
