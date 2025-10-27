<?php
/**
 * Box Search Class
 * Handles custom search boxes with shortcodes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Search {

    /**
     * Initialize
     */
    public static function init() {
        // Register search boxes from options
        $search_boxes = self::get_search_boxes();

        foreach ($search_boxes as $box) {
            add_shortcode($box['shortcode'], array(__CLASS__, 'render_search_form'));
        }

        // AJAX handlers
        add_action('wp_ajax_box_search_files', array(__CLASS__, 'ajax_search_files'));
        add_action('wp_ajax_nopriv_box_search_files', array(__CLASS__, 'ajax_search_files'));

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_scripts'));
    }

    /**
     * Get all search boxes
     */
    public static function get_search_boxes() {
        $boxes = get_option('box_search_boxes', array());
        return is_array($boxes) ? $boxes : array();
    }

    /**
     * Get a specific search box by ID
     */
    public static function get_search_box($id) {
        $boxes = self::get_search_boxes();
        return isset($boxes[$id]) ? $boxes[$id] : null;
    }

    /**
     * Add or update a search box
     */
    public static function save_search_box($data) {
        $boxes = self::get_search_boxes();

        $id = isset($data['id']) && !empty($data['id']) ? $data['id'] : uniqid('box_search_');

        $boxes[$id] = array(
            'id' => $id,
            'name' => sanitize_text_field($data['name']),
            'shortcode' => sanitize_text_field($data['shortcode']),
            'folder_id' => sanitize_text_field($data['folder_id']),
            'placeholder' => sanitize_text_field($data['placeholder']),
            'results_per_page' => intval($data['results_per_page']),
            'show_preview' => isset($data['show_preview']) ? (bool)$data['show_preview'] : true,
            'created' => isset($boxes[$id]['created']) ? $boxes[$id]['created'] : current_time('mysql'),
            'modified' => current_time('mysql')
        );

        update_option('box_search_boxes', $boxes);

        return $id;
    }

    /**
     * Delete a search box
     */
    public static function delete_search_box($id) {
        $boxes = self::get_search_boxes();

        if (isset($boxes[$id])) {
            unset($boxes[$id]);
            update_option('box_search_boxes', $boxes);
            return true;
        }

        return false;
    }

    /**
     * Render search form (shortcode handler)
     */
    public static function render_search_form($atts, $content = null, $tag = '') {
        // Find the search box by shortcode tag
        $search_box = null;
        $boxes = self::get_search_boxes();

        foreach ($boxes as $box) {
            if ($box['shortcode'] === $tag) {
                $search_box = $box;
                break;
            }
        }

        if (!$search_box) {
            return '<p>Search box not found.</p>';
        }

        // Generate unique ID for this instance
        $instance_id = 'box-search-' . $search_box['id'] . '-' . wp_rand();

        ob_start();
        ?>
        <div class="box-search-container" id="<?php echo esc_attr($instance_id); ?>" data-box-id="<?php echo esc_attr($search_box['id']); ?>" data-folder-id="<?php echo esc_attr($search_box['folder_id']); ?>">
            <div class="box-search-form">
                <input type="text"
                       class="box-search-input"
                       placeholder="<?php echo esc_attr($search_box['placeholder']); ?>"
                       aria-label="<?php echo esc_attr($search_box['name']); ?>">
                <button type="button" class="box-search-button">
                    <span class="dashicons dashicons-search"></span>
                    <span class="box-search-button-text">Search</span>
                </button>
            </div>

            <div class="box-search-loading" style="display: none;">
                <span class="spinner"></span>
                Searching...
            </div>

            <div class="box-search-results" style="display: none;">
                <div class="box-search-results-header">
                    <span class="box-search-results-count"></span>
                    <button type="button" class="box-search-clear">Clear</button>
                </div>
                <div class="box-search-results-list"></div>
            </div>

            <div class="box-search-error" style="display: none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue frontend scripts
     */
    public static function enqueue_frontend_scripts() {
        // Only enqueue if there are search boxes and we're not in admin
        if (!is_admin() && !empty(self::get_search_boxes())) {
            wp_enqueue_style('dashicons');

            wp_enqueue_script(
                'box-search',
                BOX_API_PLUGIN_URL . 'assets/js/search.js',
                array('jquery'),
                BOX_API_VERSION,
                true
            );

            wp_enqueue_style(
                'box-search',
                BOX_API_PLUGIN_URL . 'assets/css/search.css',
                array(),
                BOX_API_VERSION
            );

            wp_localize_script('box-search', 'boxSearch', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('box_search_nonce'),
                'siteUrl' => home_url()
            ));
        }
    }

    /**
     * AJAX handler for search
     */
    public static function ajax_search_files() {
        check_ajax_referer('box_search_nonce', 'nonce');

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '0';
        $box_id = isset($_POST['box_id']) ? sanitize_text_field($_POST['box_id']) : '';

        if (empty($query)) {
            wp_send_json_error('Search query is required');
        }

        // Get search box configuration
        $search_box = self::get_search_box($box_id);
        if (!$search_box) {
            wp_send_json_error('Search box not found');
        }

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            wp_send_json_error('Not authenticated with Box');
        }

        // Perform search
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        // Search in specific folder if specified
        $search_params = array(
            'query' => $query,
            'type' => 'file',
            'limit' => $search_box['results_per_page']
        );

        // Add ancestor folder filter if not root
        if ($folder_id !== '0') {
            $search_params['ancestor_folder_ids'] = $folder_id;
        }

        $results = $client->search(
            $search_params['query'],
            $search_params['type'],
            $search_params['limit'],
            isset($search_params['ancestor_folder_ids']) ? $search_params['ancestor_folder_ids'] : null
        );

        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        }

        // Format results for frontend
        $formatted_results = array();
        if (isset($results['entries']) && is_array($results['entries'])) {
            foreach ($results['entries'] as $file) {
                $formatted_results[] = array(
                    'id' => $file['id'],
                    'name' => $file['name'],
                    'size' => isset($file['size']) ? $file['size'] : 0,
                    'modified_at' => isset($file['modified_at']) ? $file['modified_at'] : '',
                    'type' => $file['type'],
                    'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                    'parent' => isset($file['parent']) ? $file['parent']['name'] : 'Unknown',
                    'url' => Box_Document_Viewer::get_document_url($file['id'], $file['name']) // Use proper permalink with filename slug
                );
            }
        }

        wp_send_json_success(array(
            'results' => $formatted_results,
            'total_count' => isset($results['total_count']) ? $results['total_count'] : count($formatted_results),
            'query' => $query
        ));
    }
}
