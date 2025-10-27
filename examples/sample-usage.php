<?php
/**
 * Box API Integration - Sample Usage
 * 
 * This file contains examples of how to use the Box API plugin
 * in your WordPress themes and plugins
 */

// Example 1: Check if plugin is active and get credentials
// ==========================================================
if (class_exists('Box_API_Integration')) {
    $box_integration = Box_API_Integration::get_instance();
    $credentials = $box_integration->get_credentials();
    
    if (!empty($credentials['access_token'])) {
        echo 'Box API is connected and ready to use!';
    } else {
        echo 'Please authorize Box API first';
    }
}

// Example 2: Upload a file programmatically
// ==========================================
function my_upload_to_box($local_file_path, $box_folder_id = '0') {
    if (!class_exists('Box_File_Manager')) {
        return false;
    }
    
    $credentials = Box_API_Integration::get_instance()->get_credentials();
    $file_manager = new Box_File_Manager($credentials);
    
    // Prepare file array
    $file = array(
        'name' => basename($local_file_path),
        'tmp_name' => $local_file_path,
        'type' => mime_content_type($local_file_path),
        'size' => filesize($local_file_path)
    );
    
    $result = $file_manager->upload_file($file, $box_folder_id);
    
    if ($result['success']) {
        return $result['file']['id']; // Return Box file ID
    }
    
    return false;
}

// Example 3: Create a shortcode to display Box files
// ====================================================
add_shortcode('box_gallery', 'render_box_gallery');

function render_box_gallery($atts) {
    $atts = shortcode_atts(array(
        'folder' => '0',
        'limit' => 20,
        'type' => 'image' // image, document, all
    ), $atts);
    
    if (!class_exists('Box_API_Client')) {
        return '<p>Box API plugin is not active</p>';
    }
    
    $credentials = Box_API_Integration::get_instance()->get_credentials();
    $client = new Box_API_Client($credentials);
    
    $items = $client->list_folder_items($atts['folder'], $atts['limit']);
    
    if (is_wp_error($items)) {
        return '<p>Unable to fetch files from Box</p>';
    }
    
    $output = '<div class="box-gallery">';
    
    foreach ($items['entries'] as $item) {
        if ($item['type'] !== 'file') continue;
        
        $extension = pathinfo($item['name'], PATHINFO_EXTENSION);
        
        // Filter by type if specified
        if ($atts['type'] === 'image') {
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) continue;
        } elseif ($atts['type'] === 'document') {
            if (!in_array($extension, ['pdf', 'doc', 'docx'])) continue;
        }
        
        $output .= '<div class="box-item">';
        $output .= '<h3>' . esc_html($item['name']) . '</h3>';
        $output .= '<p>Size: ' . size_format($item['size']) . '</p>';
        $output .= '<a href="#" class="box-download" data-file-id="' . esc_attr($item['id']) . '">Download</a>';
        $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}

// Example 4: Sync WordPress media library with Box
// ==================================================
add_action('add_attachment', 'sync_attachment_to_box');

function sync_attachment_to_box($attachment_id) {
    // Get attachment file path
    $file_path = get_attached_file($attachment_id);
    
    if (!$file_path || !file_exists($file_path)) {
        return;
    }
    
    // Upload to Box
    $box_file_id = my_upload_to_box($file_path, '0'); // Upload to root folder
    
    if ($box_file_id) {
        // Store Box file ID in post meta
        update_post_meta($attachment_id, '_box_file_id', $box_file_id);
        
        // Log success
        error_log('Synced attachment ' . $attachment_id . ' to Box: ' . $box_file_id);
    }
}

// Example 5: Create a Box upload form
// =====================================
function render_box_upload_form() {
    if (!is_user_logged_in()) {
        return '<p>Please log in to upload files</p>';
    }
    
    ob_start();
    ?>
    <form id="box-upload-form" enctype="multipart/form-data">
        <div class="form-group">
            <label for="box-file">Select File:</label>
            <input type="file" id="box-file" name="box_file" required>
        </div>
        
        <div class="form-group">
            <label for="box-folder">Upload to folder:</label>
            <select id="box-folder" name="box_folder">
                <option value="0">Root Folder</option>
                <!-- Add more folders dynamically -->
            </select>
        </div>
        
        <button type="submit" class="button">Upload to Box</button>
        
        <div id="upload-status"></div>
    </form>
    
    <script>
    jQuery(document).ready(function($) {
        $('#box-upload-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            formData.append('action', 'box_upload_file');
            formData.append('nonce', '<?php echo wp_create_nonce('box_api_nonce'); ?>');
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#upload-status').html('<p class="success">File uploaded successfully!</p>');
                    } else {
                        $('#upload-status').html('<p class="error">Upload failed: ' + response.message + '</p>');
                    }
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('box_upload', 'render_box_upload_form');

// Example 6: WP-CLI Command for Box operations
// ==============================================
if (defined('WP_CLI') && WP_CLI) {
    
    class Box_CLI_Command {
        
        /**
         * List files in a Box folder
         * 
         * ## OPTIONS
         * 
         * [--folder=<folder_id>]
         * : The Box folder ID (default: 0 for root)
         * 
         * ## EXAMPLES
         * 
         *     wp box list --folder=12345
         */
        public function list($args, $assoc_args) {
            $folder_id = isset($assoc_args['folder']) ? $assoc_args['folder'] : '0';
            
            $credentials = Box_API_Integration::get_instance()->get_credentials();
            $client = new Box_API_Client($credentials);
            
            $items = $client->list_folder_items($folder_id);
            
            if (is_wp_error($items)) {
                WP_CLI::error('Failed to fetch files: ' . $items->get_error_message());
            }
            
            $table = array();
            foreach ($items['entries'] as $item) {
                $table[] = array(
                    'ID' => $item['id'],
                    'Name' => $item['name'],
                    'Type' => $item['type'],
                    'Size' => $item['type'] === 'file' ? size_format($item['size']) : '-'
                );
            }
            
            WP_CLI\Utils\format_items('table', $table, array('ID', 'Name', 'Type', 'Size'));
        }
        
        /**
         * Upload a file to Box
         * 
         * ## OPTIONS
         * 
         * <file>
         * : Path to the file to upload
         * 
         * [--folder=<folder_id>]
         * : The Box folder ID (default: 0 for root)
         * 
         * ## EXAMPLES
         * 
         *     wp box upload /path/to/file.pdf --folder=12345
         */
        public function upload($args, $assoc_args) {
            $file_path = $args[0];
            $folder_id = isset($assoc_args['folder']) ? $assoc_args['folder'] : '0';
            
            if (!file_exists($file_path)) {
                WP_CLI::error('File not found: ' . $file_path);
            }
            
            $result = my_upload_to_box($file_path, $folder_id);
            
            if ($result) {
                WP_CLI::success('File uploaded successfully. Box file ID: ' . $result);
            } else {
                WP_CLI::error('Upload failed');
            }
        }
    }
    
    WP_CLI::add_command('box', 'Box_CLI_Command');
}

// Example 7: AJAX handler for frontend file operations
// ======================================================
add_action('wp_ajax_get_box_files', 'ajax_get_box_files');
add_action('wp_ajax_nopriv_get_box_files', 'ajax_get_box_files');

function ajax_get_box_files() {
    // Check nonce
    if (!wp_verify_nonce($_POST['nonce'], 'box_frontend_nonce')) {
        wp_die('Security check failed');
    }
    
    $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '0';
    
    $credentials = Box_API_Integration::get_instance()->get_credentials();
    $client = new Box_API_Client($credentials);
    
    $items = $client->list_folder_items($folder_id);
    
    if (is_wp_error($items)) {
        wp_send_json_error('Failed to fetch files');
    }
    
    wp_send_json_success($items);
}

// Example 8: Gutenberg Block for Box Files
// ==========================================
add_action('init', 'register_box_files_block');

function register_box_files_block() {
    if (!function_exists('register_block_type')) {
        return;
    }
    
    wp_register_script(
        'box-files-block',
        plugins_url('blocks/box-files.js', __FILE__),
        array('wp-blocks', 'wp-element', 'wp-editor'),
        '1.0.0'
    );
    
    register_block_type('box/files', array(
        'editor_script' => 'box-files-block',
        'render_callback' => 'render_box_files_block'
    ));
}

function render_box_files_block($attributes) {
    $folder_id = isset($attributes['folderId']) ? $attributes['folderId'] : '0';
    $limit = isset($attributes['limit']) ? $attributes['limit'] : 10;
    
    // Use the shortcode function
    return render_box_gallery(array(
        'folder' => $folder_id,
        'limit' => $limit
    ));
}

// Example 9: Schedule automatic backup to Box
// ============================================
add_action('init', 'schedule_box_backup');

function schedule_box_backup() {
    if (!wp_next_scheduled('box_backup_cron')) {
        wp_schedule_event(time(), 'daily', 'box_backup_cron');
    }
}

add_action('box_backup_cron', 'perform_box_backup');

function perform_box_backup() {
    // Create backup of uploads directory
    $upload_dir = wp_upload_dir();
    $backup_file = '/tmp/wordpress-backup-' . date('Y-m-d') . '.zip';
    
    // Create ZIP archive (requires ZipArchive)
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($upload_dir['basedir']),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($upload_dir['basedir']) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
        
        // Upload to Box
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $folder_manager = new Box_Folder_Manager($credentials);
        
        // Create backup folder if not exists
        $backup_folder = $folder_manager->create_folder('WordPress Backups', '0');
        $folder_id = $backup_folder['success'] ? $backup_folder['folder']['id'] : '0';
        
        // Upload backup file
        $result = my_upload_to_box($backup_file, $folder_id);
        
        if ($result) {
            error_log('Backup uploaded to Box successfully: ' . $result);
        }
        
        // Clean up temp file
        unlink($backup_file);
    }
}

// Example 10: Custom REST API endpoint for Box operations
// =========================================================
add_action('rest_api_init', function() {
    register_rest_route('box/v1', '/files/(?P<folder_id>[a-zA-Z0-9]+)', array(
        'methods' => 'GET',
        'callback' => 'rest_get_box_files',
        'permission_callback' => function() {
            return current_user_can('read');
        },
        'args' => array(
            'folder_id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return !empty($param);
                }
            )
        )
    ));
    
    register_rest_route('box/v1', '/upload', array(
        'methods' => 'POST',
        'callback' => 'rest_upload_to_box',
        'permission_callback' => function() {
            return current_user_can('upload_files');
        }
    ));
});

function rest_get_box_files($request) {
    $folder_id = $request['folder_id'];
    
    $credentials = Box_API_Integration::get_instance()->get_credentials();
    $client = new Box_API_Client($credentials);
    
    $items = $client->list_folder_items($folder_id);
    
    if (is_wp_error($items)) {
        return new WP_Error('box_error', 'Failed to fetch files', array('status' => 500));
    }
    
    return rest_ensure_response($items);
}

function rest_upload_to_box($request) {
    $files = $request->get_file_params();
    
    if (empty($files['file'])) {
        return new WP_Error('no_file', 'No file provided', array('status' => 400));
    }
    
    $folder_id = $request->get_param('folder_id') ?: '0';
    
    $credentials = Box_API_Integration::get_instance()->get_credentials();
    $file_manager = new Box_File_Manager($credentials);
    
    $result = $file_manager->upload_file($files['file'], $folder_id);
    
    if (!$result['success']) {
        return new WP_Error('upload_failed', $result['message'], array('status' => 500));
    }
    
    return rest_ensure_response($result);
}
