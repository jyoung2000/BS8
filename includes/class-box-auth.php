<?php
/**
 * Box Authentication Class
 * Handles OAuth 2.0 authentication flow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Auth {
    
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    
    /**
     * Constructor
     */
    public function __construct($credentials) {
        $this->client_id = $credentials['client_id'] ?? '';
        $this->client_secret = $credentials['client_secret'] ?? '';
        $this->redirect_uri = $credentials['redirect_uri'] ?? admin_url('admin.php?page=box-api-integration&box_oauth_callback=1');
    }
    
    /**
     * Get authorization URL
     */
    public function get_authorization_url($state = null) {
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri
        );
        
        if ($state) {
            $params['state'] = $state;
        }
        
        return BOX_AUTH_URL . '?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchange_code_for_token($code) {
        $response = wp_remote_post(BOX_TOKEN_URL, array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Box OAuth Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            return array(
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_in' => $data['expires_in']
            );
        }
        
        error_log('Box OAuth Error: ' . print_r($data, true));
        return false;
    }
    
    /**
     * Refresh access token
     */
    public function refresh_access_token($refresh_token) {
        $response = wp_remote_post(BOX_TOKEN_URL, array(
            'body' => array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Box Token Refresh Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            return array(
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_in' => $data['expires_in']
            );
        }
        
        error_log('Box Token Refresh Error: ' . print_r($data, true));
        return false;
    }
    
    /**
     * Revoke token
     */
    public function revoke_token($token) {
        $response = wp_remote_post('https://api.box.com/oauth2/revoke', array(
            'body' => array(
                'token' => $token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    /**
     * Validate credentials format
     */
    public static function validate_credentials($client_id, $client_secret, $enterprise_id = null) {
        $errors = array();
        
        // Check if credentials are provided
        if (empty($client_id)) {
            $errors[] = __('Client ID is required', 'box-api-integration');
        }
        
        if (empty($client_secret)) {
            $errors[] = __('Client Secret is required', 'box-api-integration');
        }
        
        // Validate format (Box client IDs are alphanumeric)
        if (!empty($client_id) && !preg_match('/^[a-zA-Z0-9]+$/', $client_id)) {
            $errors[] = __('Client ID contains invalid characters', 'box-api-integration');
        }
        
        if (!empty($client_secret) && !preg_match('/^[a-zA-Z0-9]+$/', $client_secret)) {
            $errors[] = __('Client Secret contains invalid characters', 'box-api-integration');
        }
        
        // Enterprise ID is optional but should be numeric if provided
        if (!empty($enterprise_id) && !preg_match('/^[0-9]+$/', $enterprise_id)) {
            $errors[] = __('Enterprise ID must be numeric', 'box-api-integration');
        }
        
        return $errors;
    }
    
    /**
     * Get authentication status
     */
    public static function get_auth_status() {
        $access_token = get_option('box_access_token', '');
        $refresh_token = get_option('box_refresh_token', '');
        $expires = get_option('box_token_expires', 0);
        
        if (empty($access_token)) {
            return array(
                'authenticated' => false,
                'message' => __('Not authenticated', 'box-api-integration')
            );
        }
        
        $time_left = $expires - time();
        
        if ($time_left <= 0) {
            if (!empty($refresh_token)) {
                return array(
                    'authenticated' => true,
                    'expired' => true,
                    'message' => __('Token expired, refresh needed', 'box-api-integration')
                );
            }
            
            return array(
                'authenticated' => false,
                'message' => __('Token expired', 'box-api-integration')
            );
        }
        
        $minutes_left = round($time_left / 60);
        
        return array(
            'authenticated' => true,
            'expired' => false,
            'expires_in' => $minutes_left,
            'message' => sprintf(__('Authenticated (expires in %d minutes)', 'box-api-integration'), $minutes_left)
        );
    }
    
    /**
     * Clear all authentication data
     */
    public static function clear_auth_data() {
        delete_option('box_access_token');
        delete_option('box_refresh_token');
        delete_option('box_token_expires');
        delete_option('box_user_id');
        delete_option('box_user_email');
        
        // Clear any scheduled token refresh
        $timestamp = wp_next_scheduled('box_refresh_token_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'box_refresh_token_cron');
        }
    }
}
