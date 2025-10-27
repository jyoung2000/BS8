<?php
/**
 * Box API Client Class
 * Handles all Box API interactions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_API_Client {
    
    private $client_id;
    private $client_secret;
    private $enterprise_id;
    private $access_token;
    private $refresh_token;
    private $redirect_uri;
    
    /**
     * Constructor
     */
    public function __construct($credentials = array()) {
        $this->client_id = $credentials['client_id'] ?? '';
        $this->client_secret = $credentials['client_secret'] ?? '';
        $this->enterprise_id = $credentials['enterprise_id'] ?? '';
        $this->access_token = $credentials['access_token'] ?? get_option('box_access_token', '');
        $this->refresh_token = $credentials['refresh_token'] ?? get_option('box_refresh_token', '');
        $this->redirect_uri = $credentials['redirect_uri'] ?? '';
    }
    
    /**
     * Make API request
     */
    public function request($endpoint, $method = 'GET', $data = null, $upload = false) {
        // Check if token is valid
        if (!$this->is_token_valid()) {
            $this->refresh_token();
        }
        
        // Determine base URL
        $base_url = $upload ? BOX_UPLOAD_URL : BOX_API_BASE_URL;
        $url = $base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
            ),
            'timeout' => 30
        );
        
        // Add data based on request type
        if ($data) {
            if ($upload) {
                // For file uploads, use multipart/form-data
                $args['headers']['Content-Type'] = 'multipart/form-data';
                $args['body'] = $data;
            } elseif (in_array($method, array('POST', 'PUT', 'PATCH'))) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = json_encode($data);
            } elseif ($method === 'GET') {
                $url .= '?' . http_build_query($data);
            }
        }
        
        // Make request
        $response = wp_remote_request($url, $args);
        
        // Log request
        $this->log_request($endpoint, $method, $response);
        
        // Handle response
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        // Handle errors
        if ($response_code >= 400) {
            $error_message = isset($data['message']) ? $data['message'] : 'API request failed';
            return new WP_Error('box_api_error', $error_message, $data);
        }
        
        return $data;
    }
    
    /**
     * Test connection
     */
    public function test_connection() {
        if (empty($this->client_id) || empty($this->client_secret)) {
            return array(
                'success' => false,
                'message' => __('Please configure your Box API credentials first.', 'box-api-integration')
            );
        }
        
        if (empty($this->access_token)) {
            return array(
                'success' => false,
                'message' => __('Not authenticated. Please authorize the application first.', 'box-api-integration'),
                'needs_auth' => true
            );
        }
        
        // Try to get current user info
        $user = $this->get_current_user();
        
        if (!is_wp_error($user)) {
            return array(
                'success' => true,
                'message' => sprintf(__('Connected as %s', 'box-api-integration'), $user['login']),
                'user' => $user
            );
        }
        
        return array(
            'success' => false,
            'message' => __('Connection failed. Please check your credentials.', 'box-api-integration')
        );
    }
    
    /**
     * Get current user info
     */
    public function get_current_user() {
        return $this->request('users/me');
    }
    
    /**
     * List folder items
     */
    public function list_folder_items($folder_id = '0', $limit = 100, $offset = 0) {
        $params = array(
            'limit' => $limit,
            'offset' => $offset,
            'fields' => 'id,type,name,size,modified_at,created_at,description,parent,path_collection'
        );
        
        return $this->request("folders/{$folder_id}/items", 'GET', $params);
    }
    
    /**
     * Get folder info
     */
    public function get_folder_info($folder_id) {
        return $this->request("folders/{$folder_id}");
    }
    
    /**
     * Create folder
     */
    public function create_folder($name, $parent_id = '0') {
        $data = array(
            'name' => $name,
            'parent' => array('id' => $parent_id)
        );
        
        return $this->request('folders', 'POST', $data);
    }
    
    /**
     * Delete folder
     */
    public function delete_folder($folder_id, $recursive = true) {
        $endpoint = "folders/{$folder_id}";
        if ($recursive) {
            $endpoint .= '?recursive=true';
        }
        
        return $this->request($endpoint, 'DELETE');
    }
    
    /**
     * Get file info
     */
    public function get_file_info($file_id) {
        return $this->request("files/{$file_id}");
    }
    
    /**
     * Download file
     */
    public function download_file($file_id) {
        $endpoint = "files/{$file_id}/content";
        
        $response = wp_remote_get(BOX_API_BASE_URL . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'timeout' => 60,
            'stream' => true,
            'filename' => WP_CONTENT_DIR . '/uploads/box-temp/' . $file_id
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 302) {
            // Follow redirect
            $headers = wp_remote_retrieve_headers($response);
            $download_url = $headers['location'];
            
            return array(
                'success' => true,
                'download_url' => $download_url
            );
        }
        
        return new WP_Error('download_failed', 'Failed to download file');
    }
    
    /**
     * Get download URL
     */
    public function get_download_url($file_id) {
        $endpoint = "files/{$file_id}/content";
        
        $response = wp_remote_head(BOX_API_BASE_URL . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'redirection' => 0
        ));
        
        if (is_wp_error($response)) {
            return '';
        }
        
        $headers = wp_remote_retrieve_headers($response);
        return $headers['location'] ?? '';
    }
    
    /**
     * Delete file
     */
    public function delete_file($file_id) {
        return $this->request("files/{$file_id}", 'DELETE');
    }
    
    /**
     * Copy file
     */
    public function copy_file($file_id, $parent_id, $name = null) {
        $data = array(
            'parent' => array('id' => $parent_id)
        );
        
        if ($name) {
            $data['name'] = $name;
        }
        
        return $this->request("files/{$file_id}/copy", 'POST', $data);
    }
    
    /**
     * Move file
     */
    public function move_file($file_id, $parent_id) {
        $data = array(
            'parent' => array('id' => $parent_id)
        );
        
        return $this->request("files/{$file_id}", 'PUT', $data);
    }
    
    /**
     * Create shared link
     */
    public function create_shared_link($file_id, $access = 'open', $password = null) {
        $data = array(
            'shared_link' => array(
                'access' => $access // open, company, collaborators
            )
        );
        
        if ($password) {
            $data['shared_link']['password'] = $password;
        }
        
        return $this->request("files/{$file_id}", 'PUT', $data);
    }
    
    /**
     * Search files
     */
    public function search($query, $type = null, $limit = 30, $ancestor_folder_ids = null) {
        $params = array(
            'query' => $query,
            'limit' => $limit,
            'fields' => 'id,type,name,size,modified_at,created_at,parent'
        );

        if ($type) {
            $params['type'] = $type; // file or folder
        }

        if ($ancestor_folder_ids) {
            $params['ancestor_folder_ids'] = $ancestor_folder_ids;
        }

        return $this->request('search', 'GET', $params);
    }
    
    /**
     * Get storage info
     */
    public function get_storage_info() {
        $user = $this->get_current_user();
        
        if (!is_wp_error($user)) {
            return array(
                'space_amount' => $user['space_amount'] ?? 0,
                'space_used' => $user['space_used'] ?? 0,
                'max_upload_size' => $user['max_upload_size'] ?? 0
            );
        }
        
        return array();
    }
    
    /**
     * Create collaboration
     */
    public function add_collaboration($folder_id, $user_email, $role = 'viewer') {
        $data = array(
            'item' => array(
                'type' => 'folder',
                'id' => $folder_id
            ),
            'accessible_by' => array(
                'type' => 'user',
                'login' => $user_email
            ),
            'role' => $role // editor, viewer, previewer, uploader, viewer uploader, co-owner
        );
        
        return $this->request('collaborations', 'POST', $data);
    }
    
    /**
     * Get collaborations
     */
    public function get_collaborations($folder_id) {
        return $this->request("folders/{$folder_id}/collaborations");
    }
    
    /**
     * Remove collaboration
     */
    public function remove_collaboration($collaboration_id) {
        return $this->request("collaborations/{$collaboration_id}", 'DELETE');
    }
    
    /**
     * Check if token is valid
     */
    private function is_token_valid() {
        if (empty($this->access_token)) {
            return false;
        }
        
        $expires = get_option('box_token_expires', 0);
        return time() < $expires - 60; // Refresh 1 minute before expiry
    }
    
    /**
     * Refresh access token
     */
    private function refresh_token() {
        if (empty($this->refresh_token)) {
            return false;
        }
        
        $auth = new Box_Auth(array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ));
        
        $result = $auth->refresh_access_token($this->refresh_token);
        
        if ($result) {
            $this->access_token = $result['access_token'];
            $this->refresh_token = $result['refresh_token'];
            
            // Update stored tokens
            update_option('box_access_token', $result['access_token']);
            update_option('box_refresh_token', $result['refresh_token']);
            update_option('box_token_expires', time() + $result['expires_in']);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Log API request
     */
    private function log_request($endpoint, $method, $response) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'box_api_logs';
        
        $status = 'success';
        $message = '';
        
        if (is_wp_error($response)) {
            $status = 'error';
            $message = $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code >= 400) {
                $status = 'error';
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? 'Request failed';
            }
        }
        
        $wpdb->insert(
            $table_name,
            array(
                'action' => $method . ' ' . $endpoint,
                'status' => $status,
                'message' => $message,
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s')
        );
        
        // Clean old logs (keep last 30 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
    }
    
    /**
     * Get recent logs
     */
    public static function get_recent_logs($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'box_api_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Box AI - Ask a question about a document
     *
     * @param string $file_id The ID of the file to ask about
     * @param string $prompt The question to ask
     * @param string $mode AI mode (default: single_item_qa)
     * @return array|WP_Error Response from Box AI
     */
    public function ai_ask($file_id, $prompt, $mode = 'single_item_qa') {
        $data = array(
            'mode' => $mode,
            'prompt' => $prompt,
            'items' => array(
                array(
                    'type' => 'file',
                    'id' => $file_id
                )
            )
        );

        // Log the request for debugging
        error_log('Box AI Request: ' . json_encode($data, JSON_PRETTY_PRINT));

        return $this->request('ai/ask', 'POST', $data);
    }

    /**
     * Box AI - Get text from a document (for context)
     *
     * @param string $file_id The ID of the file
     * @return array|WP_Error Response with document text
     */
    public function ai_extract_text($file_id) {
        $data = array(
            'items' => array(
                array(
                    'id' => $file_id,
                    'type' => 'file'
                )
            )
        );

        return $this->request('ai/extract', 'POST', $data);
    }
}
