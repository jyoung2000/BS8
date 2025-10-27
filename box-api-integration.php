<?php
/**
 * Plugin Name: Box API Integration
 * Plugin URI: https://yourwebsite.com/
 * Description: Secure Box.com API integration with OAuth 2.0 authentication for WordPress
 * Version: 1.2.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: box-api-integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BOX_API_VERSION', '1.2.0');
define('BOX_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOX_API_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOX_API_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Box API endpoints
define('BOX_API_BASE_URL', 'https://api.box.com/2.0/');
define('BOX_UPLOAD_URL', 'https://upload.box.com/api/2.0/');
define('BOX_AUTH_URL', 'https://account.box.com/api/oauth2/authorize');
define('BOX_TOKEN_URL', 'https://api.box.com/oauth2/token');

// Include required files
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-api-client.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-admin.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-file-manager.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-folder-manager.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-auth.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-search.php';
require_once BOX_API_PLUGIN_DIR . 'includes/class-box-document-viewer.php';

/**
 * Main plugin class
 */
class Box_API_Integration {
    
    private static $instance = null;
    private $client = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Core hooks
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX hooks
        add_action('wp_ajax_box_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_box_list_files', array($this, 'ajax_list_files'));
        add_action('wp_ajax_box_upload_file', array($this, 'ajax_upload_file'));
        add_action('wp_ajax_box_create_folder', array($this, 'ajax_create_folder'));
        add_action('wp_ajax_box_refresh_token', array($this, 'ajax_refresh_token'));
        add_action('wp_ajax_box_logout', array($this, 'ajax_logout'));
        add_action('wp_ajax_box_save_search_box', array($this, 'ajax_save_search_box'));
        add_action('wp_ajax_box_delete_search_box', array($this, 'ajax_delete_search_box'));

        // OAuth callback
        add_action('admin_init', array($this, 'handle_oauth_callback'));

        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Add cron hook for token refresh
        add_action('box_refresh_token_cron', array($this, 'auto_refresh_token'));

        // Hook into option updates to reschedule token refresh AFTER settings are saved
        add_action('update_option_box_token_refresh_interval', array($this, 'reschedule_on_option_update'), 10, 3);
        add_action('update_option_box_keep_alive', array($this, 'reschedule_on_option_update'), 10, 3);
    }
    
    /**
     * Plugin initialization
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('box-api-integration', false, dirname(BOX_API_PLUGIN_BASENAME) . '/languages');

        // Initialize API client if credentials are available
        $this->init_client();

        // Initialize search functionality
        Box_Search::init();

        // Initialize document viewer
        Box_Document_Viewer::init();
    }
    
    /**
     * Initialize Box API client
     */
    private function init_client() {
        $credentials = $this->get_credentials();
        
        if (!empty($credentials['client_id']) && !empty($credentials['client_secret'])) {
            $this->client = new Box_API_Client($credentials);
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('Box Integration', 'box-api-integration'),
            __('Box Integration', 'box-api-integration'),
            'manage_options',
            'box-api-integration',
            array($this, 'render_admin_page'),
            'dashicons-cloud',
            100
        );
        
        // Settings submenu
        add_submenu_page(
            'box-api-integration',
            __('Settings', 'box-api-integration'),
            __('Settings', 'box-api-integration'),
            'manage_options',
            'box-api-integration',
            array($this, 'render_admin_page')
        );
        
        // File Manager submenu
        add_submenu_page(
            'box-api-integration',
            __('File Manager', 'box-api-integration'),
            __('File Manager', 'box-api-integration'),
            'manage_options',
            'box-file-manager',
            array($this, 'render_file_manager_page')
        );
        
        // Upload submenu
        add_submenu_page(
            'box-api-integration',
            __('Upload Files', 'box-api-integration'),
            __('Upload Files', 'box-api-integration'),
            'manage_options',
            'box-upload',
            array($this, 'render_upload_page')
        );

        // Search Boxes submenu
        add_submenu_page(
            'box-api-integration',
            __('Search Boxes', 'box-api-integration'),
            __('Search Boxes', 'box-api-integration'),
            'manage_options',
            'box-search-boxes',
            array($this, 'render_search_boxes_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // OAuth settings - These are in the settings form
        register_setting('box_api_settings', 'box_client_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('box_api_settings', 'box_client_secret', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('box_api_settings', 'box_enterprise_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('box_api_settings', 'box_redirect_uri', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => admin_url('admin.php?page=box-api-integration&box_oauth_callback=1')
        ));

        // Default upload folder
        register_setting('box_api_settings', 'box_default_folder_id', array(
            'type' => 'string',
            'default' => '0' // Root folder
        ));

        // Token refresh interval (in minutes)
        register_setting('box_api_settings', 'box_token_refresh_interval', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_refresh_interval'),
            'default' => 50 // Refresh 10 minutes before expiry (tokens last 60 minutes)
        ));

        // Keep connection alive (continuous auto-refresh)
        register_setting('box_api_settings', 'box_keep_alive', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_keep_alive'),
            'default' => false
        ));

        // Token storage - SEPARATE GROUP to prevent clearing on settings save
        // These are NOT part of the settings form, so they won't be cleared
        register_setting('box_api_tokens', 'box_access_token', array(
            'type' => 'string',
            'default' => ''
        ));

        register_setting('box_api_tokens', 'box_refresh_token', array(
            'type' => 'string',
            'default' => ''
        ));

        register_setting('box_api_tokens', 'box_token_expires', array(
            'type' => 'integer',
            'default' => 0
        ));

        // User information - SEPARATE GROUP to prevent clearing on settings save
        register_setting('box_api_tokens', 'box_user_id', array(
            'type' => 'string',
            'default' => ''
        ));

        register_setting('box_api_tokens', 'box_user_email', array(
            'type' => 'string',
            'default' => ''
        ));

        // Chat customization colors (Box blue: #0061D5)
        register_setting('box_chat_customization', 'box_chat_ai_enabled', array(
            'type' => 'boolean',
            'default' => true
        ));

        register_setting('box_chat_customization', 'box_chat_ai_button_color', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => '#0061D5'
        ));

        register_setting('box_chat_customization', 'box_chat_header_color', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => '#0061D5'
        ));

        register_setting('box_chat_customization', 'box_chat_submit_color', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => '#0061D5'
        ));
    }

    /**
     * Sanitize token refresh interval
     */
    public function sanitize_refresh_interval($value) {
        $value = intval($value);

        // Ensure interval is between 5 and 55 minutes
        if ($value < 5) {
            $value = 5;
        } elseif ($value > 55) {
            $value = 55;
        }

        // Note: Rescheduling happens via update_option hook, not here
        return $value;
    }

    /**
     * Sanitize keep alive setting
     */
    public function sanitize_keep_alive($value) {
        $keep_alive = (bool) $value;

        // Note: Rescheduling happens via update_option hook, not here
        return $keep_alive;
    }

    /**
     * Reschedule token refresh when settings are updated
     * Called after options are saved to database
     */
    public function reschedule_on_option_update($old_value, $new_value, $option) {
        // Only reschedule if we have a refresh token (user is authenticated)
        $refresh_token = get_option('box_refresh_token');
        if (!empty($refresh_token)) {
            $this->schedule_token_refresh();
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        include BOX_API_PLUGIN_DIR . 'templates/admin-settings.php';
    }
    
    /**
     * Render file manager page
     */
    public function render_file_manager_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        include BOX_API_PLUGIN_DIR . 'templates/file-manager.php';
    }
    
    /**
     * Render upload page
     */
    public function render_upload_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include BOX_API_PLUGIN_DIR . 'templates/upload.php';
    }

    /**
     * Render search boxes page
     */
    public function render_search_boxes_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include BOX_API_PLUGIN_DIR . 'templates/search-boxes.php';
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'box-') === false && strpos($hook, '_page_box-') === false) {
            return;
        }
        
        // Styles
        wp_enqueue_style('box-admin', BOX_API_PLUGIN_URL . 'assets/css/admin.css', array(), BOX_API_VERSION);
        
        // Scripts
        wp_enqueue_script('box-admin', BOX_API_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), BOX_API_VERSION, true);
        
        // Localize script
        wp_localize_script('box-admin', 'box_api', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('box_api_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this file?', 'box-api-integration'),
                'uploading' => __('Uploading...', 'box-api-integration'),
                'upload_success' => __('File uploaded successfully!', 'box-api-integration'),
                'upload_error' => __('Upload failed. Please try again.', 'box-api-integration')
            )
        ));
        
        // Media uploader for file selection
        if ($hook === 'box-integration_page_box-upload') {
            wp_enqueue_media();
        }
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['box_oauth_callback']) || !isset($_GET['code'])) {
            return;
        }
        
        $auth = new Box_Auth($this->get_credentials());
        $result = $auth->exchange_code_for_token($_GET['code']);
        
        if ($result) {
            // Store tokens
            update_option('box_access_token', $result['access_token']);
            update_option('box_refresh_token', $result['refresh_token']);
            update_option('box_token_expires', time() + $result['expires_in']);
            
            // Get user info
            $this->update_user_info();
            
            // Schedule token refresh
            $this->schedule_token_refresh();
            
            // Redirect with success message
            wp_redirect(admin_url('admin.php?page=box-api-integration&auth_success=1'));
            exit;
        } else {
            // Redirect with error message
            wp_redirect(admin_url('admin.php?page=box-api-integration&auth_error=1'));
            exit;
        }
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('box_api_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $client = new Box_API_Client($this->get_credentials());
        $result = $client->test_connection();
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: List files
     */
    public function ajax_list_files() {
        check_ajax_referer('box_api_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '0';
        
        $client = new Box_API_Client($this->get_credentials());
        $files = $client->list_folder_items($folder_id);
        
        wp_send_json($files);
    }
    
    /**
     * AJAX: Upload file
     */
    public function ajax_upload_file() {
        check_ajax_referer('box_api_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error('No file provided');
        }
        
        $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '0';
        
        $file_manager = new Box_File_Manager($this->get_credentials());
        $result = $file_manager->upload_file($_FILES['file'], $folder_id);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Create folder
     */
    public function ajax_create_folder() {
        check_ajax_referer('box_api_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $name = sanitize_text_field($_POST['name']);
        $parent_id = isset($_POST['parent_id']) ? sanitize_text_field($_POST['parent_id']) : '0';
        
        $folder_manager = new Box_Folder_Manager($this->get_credentials());
        $result = $folder_manager->create_folder($name, $parent_id);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Refresh token
     */
    public function ajax_refresh_token() {
        check_ajax_referer('box_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $this->auto_refresh_token();

        wp_send_json_success('Token refreshed');
    }

    /**
     * AJAX: Logout (clear authentication)
     */
    public function ajax_logout() {
        check_ajax_referer('box_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Clear all authentication data
        Box_Auth::clear_auth_data();

        wp_send_json_success('Logged out successfully');
    }

    /**
     * AJAX: Save search box
     */
    public function ajax_save_search_box() {
        check_ajax_referer('box_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $data = array(
            'id' => isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '',
            'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'shortcode' => isset($_POST['shortcode']) ? sanitize_text_field($_POST['shortcode']) : '',
            'folder_id' => isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '0',
            'placeholder' => isset($_POST['placeholder']) ? sanitize_text_field($_POST['placeholder']) : 'Search files...',
            'results_per_page' => isset($_POST['results_per_page']) ? intval($_POST['results_per_page']) : 10,
            'show_preview' => isset($_POST['show_preview']) ? (bool)$_POST['show_preview'] : true
        );

        // Validate required fields
        if (empty($data['name']) || empty($data['shortcode'])) {
            wp_send_json_error('Name and shortcode are required');
        }

        $id = Box_Search::save_search_box($data);

        wp_send_json_success(array(
            'id' => $id,
            'message' => 'Search box saved successfully'
        ));
    }

    /**
     * AJAX: Delete search box
     */
    public function ajax_delete_search_box() {
        check_ajax_referer('box_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if (empty($id)) {
            wp_send_json_error('ID is required');
        }

        $success = Box_Search::delete_search_box($id);

        if ($success) {
            wp_send_json_success('Search box deleted successfully');
        } else {
            wp_send_json_error('Failed to delete search box');
        }
    }

    /**
     * Auto refresh token
     */
    public function auto_refresh_token() {
        $refresh_token = get_option('box_refresh_token');
        
        if (empty($refresh_token)) {
            return false;
        }
        
        $auth = new Box_Auth($this->get_credentials());
        $result = $auth->refresh_access_token($refresh_token);
        
        if ($result) {
            update_option('box_access_token', $result['access_token']);
            update_option('box_refresh_token', $result['refresh_token']);
            update_option('box_token_expires', time() + $result['expires_in']);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Schedule token refresh
     */
    private function schedule_token_refresh() {
        $timestamp = wp_next_scheduled('box_refresh_token_cron');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'box_refresh_token_cron');
        }

        // Check if keep-alive mode is enabled
        $keep_alive = get_option('box_keep_alive', false);

        if ($keep_alive) {
            // Keep-alive mode: refresh every 45 minutes for continuous authentication
            $seconds = 45 * 60; // 45 minutes (safe buffer before 60-minute expiry)
        } else {
            // Manual mode: use custom refresh interval (in minutes, default 50)
            $refresh_interval = get_option('box_token_refresh_interval', 50);
            $seconds = $refresh_interval * 60; // Convert minutes to seconds
        }

        // Schedule to run at calculated interval
        wp_schedule_event(time() + $seconds, 'hourly', 'box_refresh_token_cron');
    }
    
    /**
     * Update user info
     */
    private function update_user_info() {
        $client = new Box_API_Client($this->get_credentials());
        $user_info = $client->get_current_user();
        
        if ($user_info && !is_wp_error($user_info)) {
            update_option('box_user_id', $user_info['id']);
            update_option('box_user_email', $user_info['login']);
        }
    }
    
    /**
     * Get credentials
     */
    public function get_credentials() {
        return array(
            'client_id' => get_option('box_client_id', ''),
            'client_secret' => get_option('box_client_secret', ''),
            'enterprise_id' => get_option('box_enterprise_id', ''),
            'access_token' => get_option('box_access_token', ''),
            'refresh_token' => get_option('box_refresh_token', ''),
            'redirect_uri' => get_option('box_redirect_uri', admin_url('admin.php?page=box-api-integration&box_oauth_callback=1'))
        );
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default redirect URI
        if (!get_option('box_redirect_uri')) {
            update_option('box_redirect_uri', admin_url('admin.php?page=box-api-integration&box_oauth_callback=1'));
        }

        // Initialize rewrite rules
        Box_Document_Viewer::add_rewrite_rules();
        flush_rewrite_rules();

        // Clear cache
        wp_cache_flush();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        $timestamp = wp_next_scheduled('box_refresh_token_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'box_refresh_token_cron');
        }

        // Clear rewrite rules
        flush_rewrite_rules();

        // Clear cache
        wp_cache_flush();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'box_api_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action varchar(100) NOT NULL,
            file_id varchar(50),
            file_name text,
            folder_id varchar(50),
            user_id bigint(20),
            status varchar(20),
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the plugin
Box_API_Integration::get_instance();
