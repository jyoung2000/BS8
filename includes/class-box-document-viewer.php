<?php
/**
 * Box Document Viewer
 * Handles document viewing with shareable URLs
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Document_Viewer {

    /**
     * Initialize
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'handle_document_view'));

        // Prevent 404 errors on box-document pages
        add_filter('pre_handle_404', array(__CLASS__, 'prevent_404'), 10, 2);
        add_action('parse_request', array(__CLASS__, 'parse_box_document_request'));
        add_filter('body_class', array(__CLASS__, 'remove_404_body_class'));
        add_filter('wp_title', array(__CLASS__, 'set_document_title'), 10, 2);
        add_filter('document_title_parts', array(__CLASS__, 'set_document_title_parts'));

        // AJAX handler for getting document info
        add_action('wp_ajax_box_get_document_url', array(__CLASS__, 'ajax_get_document_url'));
        add_action('wp_ajax_nopriv_box_get_document_url', array(__CLASS__, 'ajax_get_document_url'));

        // AJAX handler for Box AI chat
        add_action('wp_ajax_box_ai_chat', array(__CLASS__, 'ajax_box_ai_chat'));
        add_action('wp_ajax_nopriv_box_ai_chat', array(__CLASS__, 'ajax_box_ai_chat'));

        // AJAX handler for file downloads
        add_action('wp_ajax_box_download_file', array(__CLASS__, 'ajax_download_file'));
        add_action('wp_ajax_nopriv_box_download_file', array(__CLASS__, 'ajax_download_file'));

        // Enqueue chat scripts on document viewer pages
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_chat_scripts'));
    }

    /**
     * Add rewrite rules for document viewing
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^box-document/([^/]+)/?$',
            'index.php?box_document_id=$matches[1]',
            'top'
        );
    }

    /**
     * Add custom query vars
     */
    public static function add_query_vars($vars) {
        $vars[] = 'box_document_id';
        return $vars;
    }

    /**
     * Parse box document requests and mark them as valid
     */
    public static function parse_box_document_request($wp) {
        // Check if this is a box-document request
        if (isset($wp->query_vars['box_document_id']) && !empty($wp->query_vars['box_document_id'])) {
            // Mark this as a valid request (not a 404)
            $wp->query_vars['error'] = '';
            status_header(200);
        }
    }

    /**
     * Prevent 404 status for box-document pages
     */
    public static function prevent_404($preempt, $wp_query) {
        // If this is a box-document page, don't trigger 404
        if (get_query_var('box_document_id')) {
            return true; // Prevent 404
        }
        return $preempt;
    }

    /**
     * Remove 404 class from body on box-document pages
     */
    public static function remove_404_body_class($classes) {
        if (get_query_var('box_document_id')) {
            // Remove error404 class if present
            $classes = array_diff($classes, array('error404'));
            // Add custom class for box-document pages
            $classes[] = 'box-document-page';
        }
        return $classes;
    }

    /**
     * Set document title for box-document pages
     */
    public static function set_document_title($title, $sep = '|') {
        $file_id = get_query_var('box_document_id');
        if ($file_id) {
            return 'Document ' . $sep . ' ' . get_bloginfo('name');
        }
        return $title;
    }

    /**
     * Set document title parts for box-document pages
     */
    public static function set_document_title_parts($title_parts) {
        $file_id = get_query_var('box_document_id');
        if ($file_id) {
            $title_parts['title'] = 'Document';
        }
        return $title_parts;
    }

    /**
     * Handle document view requests
     */
    public static function handle_document_view() {
        $file_id = get_query_var('box_document_id');

        if (!$file_id) {
            return;
        }

        // Ensure this is not treated as a 404
        global $wp_query;
        $wp_query->is_404 = false;
        status_header(200);

        // Initialize default values
        $file_info = array(
            'id' => $file_id,
            'name' => 'Document',
            'size' => 0,
            'modified_at' => current_time('mysql')
        );
        $embed_url = null;
        $error_message = null;

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            $error_message = __('Not authenticated with Box. Please contact the site administrator to reconnect.', 'box-api-integration');
            // Continue to render page with error message
        } else {
            // Get file info from Box
            try {
                $credentials = Box_API_Integration::get_instance()->get_credentials();
                $client = new Box_API_Client($credentials);

                $api_file_info = $client->get_file_info($file_id);

                if (is_wp_error($api_file_info)) {
                    $error_message = __('Unable to load file information. ', 'box-api-integration') . $api_file_info->get_error_message();
                    error_log('Box Document Viewer - File info error: ' . $api_file_info->get_error_message());
                } else {
                    // Successfully got file info
                    $file_info = $api_file_info;

                    // Try to get embed/preview URL
                    $embed_url = self::get_embed_url($file_id);

                    if (!$embed_url) {
                        error_log('Box Document Viewer - Unable to get embed URL for file: ' . $file_id);
                    }
                }
            } catch (Exception $e) {
                $error_message = __('An error occurred while loading the document. ', 'box-api-integration') . $e->getMessage();
                error_log('Box Document Viewer - Exception: ' . $e->getMessage());
            }
        }

        // Always display the document page (even with errors)
        self::render_document_page($file_info, $embed_url, $error_message);
        exit;
    }

    /**
     * Get embed URL for a document
     */
    public static function get_embed_url($file_id) {
        try {
            $credentials = Box_API_Integration::get_instance()->get_credentials();
            $client = new Box_API_Client($credentials);

            // Get embed link from Box
            $response = $client->request("files/{$file_id}?fields=expiring_embed_link", 'GET');

            if (is_wp_error($response)) {
                error_log('Box Document Viewer - Embed link request error: ' . $response->get_error_message());
                // Try fallback
            } elseif (isset($response['expiring_embed_link']['url'])) {
                return $response['expiring_embed_link']['url'];
            }

            // Fallback to shared link
            $shared_link = $client->create_shared_link($file_id);
            if (!is_wp_error($shared_link) && isset($shared_link['shared_link']['url'])) {
                return $shared_link['shared_link']['url'];
            } elseif (is_wp_error($shared_link)) {
                error_log('Box Document Viewer - Shared link error: ' . $shared_link->get_error_message());
            }
        } catch (Exception $e) {
            error_log('Box Document Viewer - Embed URL exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Render document viewing page
     */
    private static function render_document_page($file_info, $embed_url, $error_message = null) {
        $file_name = isset($file_info['name']) ? $file_info['name'] : 'Document';
        $file_size = isset($file_info['size']) ? size_format($file_info['size'], 2) : '';
        $modified_at = isset($file_info['modified_at']) ? date('F j, Y g:i a', strtotime($file_info['modified_at'])) : '';

        // Get download URL - always generate a working download URL
        $download_url = '';
        if (isset($file_info['id'])) {
            try {
                $credentials = Box_API_Integration::get_instance()->get_credentials();
                $client = new Box_API_Client($credentials);

                // Try to get shared link first (best for direct downloads)
                $shared_link = $client->create_shared_link($file_info['id']);

                if (!is_wp_error($shared_link) && isset($shared_link['shared_link']['download_url'])) {
                    // Use shared link download URL
                    $download_url = $shared_link['shared_link']['download_url'];
                } elseif (!is_wp_error($shared_link) && isset($shared_link['shared_link']['url'])) {
                    // Use shared link URL with download parameter
                    $download_url = $shared_link['shared_link']['url'] . '?dl=1';
                } else {
                    // Fallback: use WordPress proxy endpoint for authenticated download
                    $download_url = admin_url('admin-ajax.php') . '?action=box_download_file&file_id=' . urlencode($file_info['id']) . '&nonce=' . wp_create_nonce('box_download_' . $file_info['id']);
                }

                // Debug logging
                error_log('Download URL for file ' . $file_info['id'] . ': ' . ($download_url ? 'Generated: ' . $download_url : 'Failed'));
            } catch (Exception $e) {
                error_log('Download URL generation error: ' . $e->getMessage());
                // Fallback to proxy endpoint
                $download_url = admin_url('admin-ajax.php') . '?action=box_download_file&file_id=' . urlencode($file_info['id']) . '&nonce=' . wp_create_nonce('box_download_' . $file_info['id']);
            }
        }

        // Always ensure download URL is set
        if (empty($download_url) && isset($file_info['id'])) {
            // Ultimate fallback: WordPress proxy endpoint
            $download_url = admin_url('admin-ajax.php') . '?action=box_download_file&file_id=' . urlencode($file_info['id']) . '&nonce=' . wp_create_nonce('box_download_' . $file_info['id']);
        }

        // Get custom colors and settings (Box blue: #0061D5)
        $chat_ai_enabled = get_option('box_chat_ai_enabled', true);
        $ai_button_color = get_option('box_chat_ai_button_color', '#0061D5');
        $header_color = get_option('box_chat_header_color', '#0061D5');
        $submit_color = get_option('box_chat_submit_color', '#0061D5');

        // Manually enqueue scripts here to ensure they load
        wp_enqueue_style('dashicons');
        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'box-ai-chat',
            BOX_API_PLUGIN_URL . 'assets/js/box-ai-chat.js',
            array('jquery'),
            BOX_API_VERSION . '-' . time(), // Add timestamp to prevent caching
            true
        );

        wp_localize_script('box-ai-chat', 'boxAiChat', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('box_ai_chat_nonce')
        ));

        // Add custom styles to wp_head
        add_action('wp_head', function() use ($file_name, $ai_button_color, $header_color, $submit_color) {
            // Helper function to generate lighter/darker shades
            $darken_color = function($hex, $percent) {
                $hex = str_replace('#', '', $hex);
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $r = max(0, min(255, $r - ($r * $percent / 100)));
                $g = max(0, min(255, $g - ($g * $percent / 100)));
                $b = max(0, min(255, $b - ($b * $percent / 100)));
                return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
            };

            $ai_button_hover = $darken_color($ai_button_color, -10);
            $header_dark = $darken_color($header_color, 10);
            $submit_hover = $darken_color($submit_color, -10);
            ?>
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
            <title><?php echo esc_html($file_name); ?> - <?php bloginfo('name'); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                html {
                    /* Prevent iOS text size adjustment */
                    -webkit-text-size-adjust: 100%;
                    -moz-text-size-adjust: 100%;
                    -ms-text-size-adjust: 100%;
                    text-size-adjust: 100%;
                    /* Prevent double-tap zoom */
                    touch-action: manipulation;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
                    /* Prevent text size adjustment */
                    -webkit-text-size-adjust: 100%;
                    /* Prevent double-tap zoom */
                    touch-action: manipulation;
                }

                .box-document-viewer-wrapper {
                    min-height: 100vh;
                    display: flex;
                    flex-direction: column;
                    background: #f5f5f7;
                }

                .document-viewer-header {
                    position: sticky;
                    top: 0;
                    background: #fff;
                    border-bottom: 1px solid rgba(0, 0, 0, 0.12);
                    padding: 18px 28px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
                    z-index: 999;
                }

                .document-info {
                    flex: 1;
                    min-width: 0;
                    margin-right: 24px;
                }

                .document-name {
                    font-size: 19px;
                    font-weight: 600;
                    color: #1d1d1f;
                    margin-bottom: 6px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    letter-spacing: -0.02em;
                }

                .document-meta {
                    font-size: 14px;
                    color: #86868b;
                    font-weight: 400;
                }

                .document-actions {
                    display: flex;
                    gap: 12px;
                    align-items: center;
                    flex-shrink: 0;
                    justify-content: space-between;
                }

                .document-actions-right {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                }

                .btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 12px 24px;
                    font-size: 16px;
                    font-weight: 600;
                    text-decoration: none;
                    border-radius: 10px;
                    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                    border: none;
                    cursor: pointer;
                    white-space: nowrap;
                    letter-spacing: -0.01em;
                }

                .btn-primary {
                    color: #fff !important;
                    background: linear-gradient(180deg, #0077ed 0%, #0051d5 100%);
                    box-shadow: 0 3px 10px rgba(0, 113, 227, 0.35);
                    border: 1px solid rgba(0, 113, 227, 0.1);
                }

                .btn-primary:hover {
                    background: linear-gradient(180deg, #0071e3 0%, #004fc4 100%);
                    box-shadow: 0 5px 16px rgba(0, 113, 227, 0.45);
                    transform: translateY(-2px);
                }

                .btn-primary:active {
                    transform: translateY(0);
                    box-shadow: 0 2px 6px rgba(0, 113, 227, 0.3);
                }

                .btn-primary:focus {
                    outline: none;
                    box-shadow: 0 0 0 4px rgba(0, 113, 227, 0.2), 0 3px 10px rgba(0, 113, 227, 0.35);
                }

                .btn-secondary {
                    color: #1d1d1f !important;
                    background: #f5f5f7;
                    border: 1.5px solid #d2d2d7;
                }

                .btn-secondary:hover {
                    background: #e8e8ed;
                    border-color: #b0b0b5;
                    transform: translateY(-1px);
                }

                .btn-secondary:active {
                    transform: translateY(0);
                    background: #dcdce0;
                }

                .btn-secondary:focus {
                    outline: none;
                    box-shadow: 0 0 0 4px rgba(0, 0, 0, 0.08);
                }

                .btn-ai {
                    color: #fff !important;
                    background: linear-gradient(135deg, <?php echo esc_attr($ai_button_color); ?> 0%, <?php echo esc_attr($darken_color($ai_button_color, 5)); ?> 100%);
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                    border: none;
                }

                .btn-ai:hover {
                    background: linear-gradient(135deg, <?php echo esc_attr($ai_button_hover); ?> 0%, <?php echo esc_attr($ai_button_color); ?> 100%);
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                    transform: translateY(-2px);
                }

                .btn-ai:active {
                    transform: translateY(0);
                    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
                }

                .btn .dashicons {
                    font-size: 18px;
                    width: 18px;
                    height: 18px;
                }

                .btn-ai .dashicons {
                    font-size: 18px;
                    width: 18px;
                    height: 18px;
                }

                /* Icon-only button - compact and circular */
                .btn-icon {
                    padding: 12px;
                    min-width: unset;
                    width: 48px;
                    height: 48px;
                    border-radius: 10px;
                    color: #1d1d1f !important;
                    background: #f5f5f7;
                    border: 1.5px solid #d2d2d7;
                }

                .btn-icon:hover {
                    background: #e8e8ed;
                    border-color: #b0b0b5;
                    transform: translateY(-1px);
                }

                .btn-icon:active {
                    transform: translateY(0);
                    background: #dcdce0;
                }

                .btn-icon:focus {
                    outline: none;
                    box-shadow: 0 0 0 4px rgba(0, 0, 0, 0.08);
                }

                .btn-icon .dashicons {
                    font-size: 20px;
                    width: 20px;
                    height: 20px;
                }

                /* Chat Modal - Modern Glass Morphism Design */
                .box-ai-chat-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    /* Prevent zoom gestures */
                    touch-action: none;
                    /* Smooth fade in animation */
                    animation: modalFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                }

                @keyframes modalFadeIn {
                    from {
                        opacity: 0;
                    }
                    to {
                        opacity: 1;
                    }
                }

                .box-ai-chat-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.65);
                    backdrop-filter: blur(20px) saturate(180%);
                    -webkit-backdrop-filter: blur(20px) saturate(180%);
                    /* Modern glass effect */
                    animation: overlayFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                }

                @keyframes overlayFadeIn {
                    from {
                        opacity: 0;
                        backdrop-filter: blur(0px);
                        -webkit-backdrop-filter: blur(0px);
                    }
                    to {
                        opacity: 1;
                        backdrop-filter: blur(20px) saturate(180%);
                        -webkit-backdrop-filter: blur(20px) saturate(180%);
                    }
                }

                .box-ai-chat-container {
                    position: relative;
                    width: 90%;
                    max-width: 950px;
                    height: 88vh;
                    max-height: 920px;
                    background: #ffffff;
                    border-radius: 24px;
                    box-shadow:
                        0 32px 64px rgba(0, 0, 0, 0.24),
                        0 0 1px rgba(0, 0, 0, 0.12);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                    /* Smooth scrolling for iOS */
                    -webkit-overflow-scrolling: touch;
                    /* Prevent zoom on double tap */
                    touch-action: pan-y;
                    /* Slide up animation */
                    animation: containerSlideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                    /* Modern border for depth */
                    border: 1px solid rgba(255, 255, 255, 0.18);
                }

                @keyframes containerSlideUp {
                    from {
                        opacity: 0;
                        transform: translateY(40px) scale(0.96);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }

                .box-ai-chat-header {
                    padding: 20px 24px;
                    background: linear-gradient(135deg, <?php echo esc_attr($header_color); ?> 0%, <?php echo esc_attr($header_dark); ?> 100%);
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                }

                .box-ai-chat-title {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    font-size: 19px;
                    font-weight: 600;
                    letter-spacing: -0.02em;
                }

                .box-ai-chat-title .dashicons {
                    font-size: 26px;
                    width: 26px;
                    height: 26px;
                    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
                }

                .box-ai-chat-close-btn {
                    background: rgba(255, 255, 255, 0.15);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    border-radius: 10px;
                    padding: 10px;
                    min-width: 40px;
                    min-height: 40px;
                    cursor: pointer;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                    /* Prevent zoom on tap */
                    touch-action: manipulation;
                    -webkit-tap-highlight-color: transparent;
                    backdrop-filter: blur(10px);
                    -webkit-backdrop-filter: blur(10px);
                }

                .box-ai-chat-close-btn:hover {
                    background: rgba(255, 255, 255, 0.25);
                    border-color: rgba(255, 255, 255, 0.3);
                    transform: scale(1.05);
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                }

                .box-ai-chat-close-btn:active {
                    transform: scale(0.98);
                    background: rgba(255, 255, 255, 0.2);
                }

                .box-ai-chat-close-btn .dashicons {
                    font-size: 20px;
                    width: 20px;
                    height: 20px;
                }

                .box-ai-chat-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: 28px 32px;
                    background: linear-gradient(180deg, #fafafa 0%, #f5f5f7 100%);
                    /* Smooth scrolling for iOS */
                    -webkit-overflow-scrolling: touch;
                    /* Better scroll performance */
                    will-change: scroll-position;
                }

                /* Custom scrollbar for webkit browsers */
                .box-ai-chat-messages::-webkit-scrollbar {
                    width: 8px;
                }

                .box-ai-chat-messages::-webkit-scrollbar-track {
                    background: transparent;
                    margin: 8px 0;
                }

                .box-ai-chat-messages::-webkit-scrollbar-thumb {
                    background: rgba(0, 0, 0, 0.15);
                    border-radius: 10px;
                    border: 2px solid transparent;
                    background-clip: padding-box;
                }

                .box-ai-chat-messages::-webkit-scrollbar-thumb:hover {
                    background: rgba(0, 0, 0, 0.25);
                    background-clip: padding-box;
                }

                .box-ai-welcome-message {
                    text-align: center;
                    padding: 48px 24px;
                    color: #6e6e73;
                }

                .box-ai-welcome-message p {
                    font-size: 17px;
                    line-height: 1.5;
                    font-weight: 400;
                    letter-spacing: -0.01em;
                }

                .box-ai-message {
                    margin-bottom: 18px;
                    animation: messageSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
                }

                @keyframes messageSlideIn {
                    from {
                        opacity: 0;
                        transform: translateY(12px) scale(0.95);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }

                .box-ai-message-user {
                    display: flex;
                    justify-content: flex-end;
                }

                .box-ai-message-user .box-ai-message-content {
                    background: <?php echo esc_attr($submit_color); ?>;
                    color: #fff;
                    padding: 12px 18px;
                    border-radius: 20px 20px 4px 20px;
                    max-width: 75%;
                    word-wrap: break-word;
                    font-size: 16px;
                    line-height: 1.47;
                    font-weight: 400;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
                    letter-spacing: -0.003em;
                }

                .box-ai-message-ai {
                    display: flex;
                    justify-content: flex-start;
                }

                .box-ai-message-ai .box-ai-message-content {
                    background: #ffffff;
                    color: #1d1d1f;
                    padding: 14px 18px;
                    border-radius: 20px 20px 20px 4px;
                    max-width: 80%;
                    word-wrap: break-word;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                    line-height: 1.53;
                    font-size: 16px;
                    font-weight: 400;
                    border: 1px solid #e5e5e7;
                    letter-spacing: -0.003em;
                }

                .box-ai-message-content br {
                    display: block;
                    margin: 8px 0;
                    content: "";
                }

                /* Formatted text support */
                .box-ai-message-content strong,
                .box-ai-message-content b {
                    font-weight: 700;
                    color: #1d1d1f;
                }

                .box-ai-message-content em,
                .box-ai-message-content i {
                    font-style: italic;
                }

                .box-ai-message-content ul,
                .box-ai-message-content ol {
                    margin: 12px 0;
                    padding-left: 24px;
                }

                .box-ai-message-content li {
                    margin: 6px 0;
                    line-height: 1.5;
                }

                .box-ai-message-content p {
                    margin: 10px 0;
                    line-height: 1.6;
                }

                .box-ai-message-content p:first-child {
                    margin-top: 0;
                }

                .box-ai-message-content p:last-child {
                    margin-bottom: 0;
                }

                .box-ai-message-content code {
                    background: #f5f5f7;
                    padding: 3px 7px;
                    border-radius: 6px;
                    font-family: 'SF Mono', 'Monaco', 'Menlo', 'Courier New', monospace;
                    font-size: 14px;
                    color: #c7254e;
                }

                .box-ai-message-content pre {
                    background: #f5f5f7;
                    padding: 14px;
                    border-radius: 10px;
                    overflow-x: auto;
                    margin: 12px 0;
                    border: 1px solid #e5e5e7;
                }

                .box-ai-message-content pre code {
                    background: none;
                    padding: 0;
                    color: #1d1d1f;
                }

                .box-ai-message-content h1,
                .box-ai-message-content h2,
                .box-ai-message-content h3,
                .box-ai-message-content h4 {
                    margin: 18px 0 10px 0;
                    font-weight: 600;
                    letter-spacing: -0.015em;
                }

                .box-ai-message-content h1 {
                    font-size: 22px;
                    line-height: 1.3;
                }

                .box-ai-message-content h2 {
                    font-size: 20px;
                    line-height: 1.3;
                }

                .box-ai-message-content h3 {
                    font-size: 18px;
                    line-height: 1.4;
                }

                .box-ai-message-content h4 {
                    font-size: 16px;
                    line-height: 1.4;
                }

                .box-ai-message-content a {
                    color: #0071e3;
                    text-decoration: none;
                }

                .box-ai-message-content a:hover {
                    text-decoration: underline;
                }

                .box-ai-message-loading {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    color: #86868b;
                    font-size: 14px;
                }

                .box-ai-message-loading .spinner {
                    display: inline-block;
                    width: 16px;
                    height: 16px;
                    border: 2px solid rgba(107, 70, 193, 0.2);
                    border-top-color: #6B46C1;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }

                .box-ai-chat-input-container {
                    padding: 20px 24px;
                    /* Safe area for iPhone bottom */
                    padding-bottom: calc(20px + env(safe-area-inset-bottom));
                    background: #fff;
                    border-top: 1px solid rgba(0, 0, 0, 0.08);
                    /* Prevent input from being covered by keyboard on iOS */
                    position: relative;
                    box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.04);
                }

                /* Claude-style input wrapper - full width with inline button */
                .box-ai-chat-input-wrapper {
                    position: relative;
                    background: #f5f5f7;
                    border: 2px solid #e5e5e7;
                    border-radius: 24px;
                    transition: all 0.2s ease;
                    display: flex;
                    align-items: flex-end;
                }

                .box-ai-chat-input-wrapper:focus-within {
                    border-color: <?php echo esc_attr($submit_color); ?>;
                    background: #fff;
                    box-shadow: 0 0 0 3px rgba(<?php
                        echo hexdec(substr($submit_color, 1, 2)) . ', ' .
                             hexdec(substr($submit_color, 3, 2)) . ', ' .
                             hexdec(substr($submit_color, 5, 2));
                    ?>, 0.1);
                }

                #box-ai-chat-input {
                    flex: 1;
                    padding: 14px 18px;
                    padding-right: 52px; /* Space for button */
                    border: none;
                    background: transparent;
                    border-radius: 24px;
                    font-size: 16px;
                    /* CRITICAL: 16px minimum prevents iOS zoom */
                    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
                    resize: none;
                    max-height: 160px;
                    min-height: 24px;
                    line-height: 1.5;
                    /* Better text rendering */
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                    /* Prevent iOS auto-zoom */
                    -webkit-text-size-adjust: 100%;
                    /* Allow only vertical scrolling */
                    touch-action: manipulation;
                    letter-spacing: -0.003em;
                    color: #1d1d1f;
                }

                #box-ai-chat-input::placeholder {
                    color: #86868b;
                    font-weight: 400;
                }

                #box-ai-chat-input:focus {
                    outline: none;
                }

                /* Claude-style send button - inline, subtle when empty, prominent when has text */
                .box-ai-chat-send-btn {
                    position: absolute;
                    right: 8px;
                    top: 50%;
                    transform: translateY(-50%);
                    background: #d2d2d7;
                    border: none;
                    border-radius: 50%;
                    padding: 0;
                    width: 36px;
                    height: 36px;
                    color: #fff;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0.5;
                    /* Prevent zoom on tap */
                    touch-action: manipulation;
                    -webkit-tap-highlight-color: transparent;
                }

                /* Active state when there's text */
                .box-ai-chat-send-btn.has-text {
                    background: <?php echo esc_attr($submit_color); ?>;
                    opacity: 1;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                }

                .box-ai-chat-send-btn.has-text:hover {
                    background: <?php echo esc_attr($submit_hover); ?>;
                    transform: translateY(-50%) scale(1.05);
                    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.2);
                }

                .box-ai-chat-send-btn.has-text:active {
                    transform: translateY(-50%) scale(0.95);
                }

                .box-ai-chat-send-btn:disabled {
                    opacity: 0.3;
                    cursor: not-allowed;
                }

                .box-ai-chat-send-btn .dashicons {
                    font-size: 20px;
                    width: 20px;
                    height: 20px;
                }

                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .document-viewer-container {
                    flex: 1;
                    display: flex;
                    background: #fff;
                    min-height: 100vh;
                }

                .document-viewer-container iframe {
                    width: 100%;
                    height: 100vh;
                    min-height: 800px;
                    border: none;
                    display: block;
                }

                .document-viewer-error {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    flex-direction: column;
                    gap: 20px;
                    color: #1d1d1f;
                    padding: 24px;
                }

                .document-viewer-error p {
                    font-size: 17px;
                    font-weight: 500;
                    color: #86868b;
                }

                /* Tablet screens (iPad, larger tablets) - Modern & Spacious */
                @media (max-width: 768px) {
                    .document-viewer-header {
                        flex-direction: column;
                        gap: 16px;
                        align-items: stretch;
                        padding: 16px 20px;
                    }

                    .document-info {
                        margin-right: 0;
                    }

                    .document-name {
                        font-size: 17px;
                    }

                    .document-meta {
                        font-size: 13px;
                    }

                    .document-actions {
                        width: 100%;
                        gap: 10px;
                        flex-wrap: wrap;
                    }

                    .document-actions-right {
                        flex: 1;
                        gap: 8px;
                        display: flex;
                        flex-wrap: nowrap;
                    }

                    .btn {
                        flex: 1;
                        min-width: 120px;
                        font-size: 15px;
                        padding: 11px 20px;
                    }

                    /* Chat with AI button takes full width on tablets */
                    #box-ai-chat-button {
                        flex: 0 0 100%;
                        order: -1;
                    }

                    .btn-icon {
                        flex: 0 0 auto;
                        min-width: 44px;
                        width: 44px;
                        height: 44px;
                        padding: 10px;
                    }

                    /* Make download button icon-only on tablets for better fit */
                    .btn-download .btn-text {
                        display: none;
                    }

                    .btn-download {
                        flex: 0 0 auto;
                        padding: 12px;
                        min-width: 44px;
                        width: 44px;
                        height: 44px;
                    }

                    .document-viewer-container iframe {
                        height: 90vh;
                        min-height: 600px;
                    }

                    /* Modern chat modal for tablets */
                    .box-ai-chat-container {
                        width: 94%;
                        height: 92vh;
                        max-height: 1100px;
                        border-radius: 28px;
                    }

                    .box-ai-chat-header {
                        padding: 22px 26px;
                    }

                    .box-ai-chat-title {
                        font-size: 19px;
                        font-weight: 600;
                        gap: 14px;
                    }

                    .box-ai-chat-title .dashicons {
                        font-size: 28px;
                        width: 28px;
                        height: 28px;
                    }

                    /* Prominent touch targets */
                    .box-ai-chat-close-btn {
                        padding: 12px;
                        min-width: 50px;
                        min-height: 50px;
                        border-radius: 12px;
                    }

                    .box-ai-chat-close-btn .dashicons {
                        font-size: 24px;
                        width: 24px;
                        height: 24px;
                    }

                    /* Spacious message area */
                    .box-ai-chat-messages {
                        padding: 30px 26px;
                    }

                    .box-ai-message {
                        margin-bottom: 22px;
                    }

                    /* Comfortable message bubbles */
                    .box-ai-message-user .box-ai-message-content {
                        max-width: 82%;
                        font-size: 17px;
                        padding: 17px 22px;
                        line-height: 1.5;
                        border-radius: 24px 24px 6px 24px;
                    }

                    .box-ai-message-ai .box-ai-message-content {
                        max-width: 85%;
                        font-size: 17px;
                        padding: 19px 24px;
                        line-height: 1.6;
                        border-radius: 24px 24px 24px 6px;
                    }

                    .box-ai-welcome-message {
                        padding: 52px 28px;
                    }

                    .box-ai-welcome-message p {
                        font-size: 18px;
                    }

                    /* Enhanced input area for tablets */
                    .box-ai-chat-input-container {
                        padding: 20px 24px;
                        padding-bottom: calc(20px + env(safe-area-inset-bottom));
                        gap: 14px;
                    }

                    #box-ai-chat-input {
                        font-size: 17px;
                        padding: 16px 20px;
                        border-radius: 22px;
                        line-height: 1.47;
                        min-height: 54px;
                        -webkit-text-size-adjust: 100%;
                        touch-action: manipulation;
                    }

                    .box-ai-chat-send-btn {
                        padding: 15px;
                        min-width: 54px;
                        min-height: 54px;
                        border-radius: 22px;
                        touch-action: manipulation;
                    }

                    .box-ai-chat-send-btn .dashicons {
                        font-size: 26px;
                        width: 26px;
                        height: 26px;
                    }
                }

                /* Mobile screens - Clean & Modern for iPhone and smaller devices */
                @media (max-width: 480px) {
                    .document-viewer-header {
                        padding: 14px 16px;
                    }

                    .btn {
                        font-size: 14px;
                        padding: 10px 16px;
                    }

                    /* Make download button icon-only on mobile */
                    .btn-download .btn-text {
                        display: none;
                    }

                    .btn-download {
                        padding: 12px;
                        min-width: unset;
                        width: 48px;
                        height: 48px;
                    }

                    /* Ensure download and back buttons stay on same line */
                    .document-actions-right {
                        display: flex;
                        flex-wrap: nowrap;
                        gap: 8px;
                    }

                    /* Fullscreen immersive chat with modern design */
                    .box-ai-chat-container {
                        width: 100%;
                        height: 100%;
                        max-height: 100%;
                        border-radius: 0;
                        /* Support iPhone notch and home indicator */
                        padding-top: env(safe-area-inset-top);
                        box-shadow: none;
                        border: none;
                    }

                    /* Modern header with safe area support */
                    .box-ai-chat-header {
                        padding: 20px 22px;
                        padding-top: calc(20px + env(safe-area-inset-top));
                    }

                    .box-ai-chat-title {
                        font-size: 18px;
                        font-weight: 600;
                        gap: 12px;
                    }

                    .box-ai-chat-title .dashicons {
                        font-size: 24px;
                        width: 24px;
                        height: 24px;
                    }

                    /* Prominent close button with modern styling */
                    .box-ai-chat-close-btn {
                        padding: 12px;
                        min-width: 48px;
                        min-height: 48px;
                        border-radius: 12px;
                        background: rgba(255, 255, 255, 0.2);
                        border: 1.5px solid rgba(255, 255, 255, 0.25);
                    }

                    .box-ai-chat-close-btn:active {
                        background: rgba(255, 255, 255, 0.3);
                        transform: scale(0.95);
                    }

                    .box-ai-chat-close-btn .dashicons {
                        font-size: 26px;
                        width: 26px;
                        height: 26px;
                    }

                    /* Clean message area with modern background */
                    .box-ai-chat-messages {
                        padding: 24px 20px;
                        padding-bottom: 28px;
                        background: linear-gradient(180deg, #f9f9f9 0%, #f5f5f7 100%);
                    }

                    .box-ai-message {
                        margin-bottom: 20px;
                    }

                    .box-ai-welcome-message {
                        padding: 48px 24px;
                    }

                    .box-ai-welcome-message p {
                        font-size: 17px;
                        line-height: 1.5;
                    }

                    /* Modern, comfortable message bubbles - bigger with better spacing */
                    .box-ai-message-user .box-ai-message-content {
                        max-width: 85%;
                        padding: 14px 18px;
                        font-size: 15px;
                        border-radius: 22px 22px 6px 22px;
                        line-height: 1.5;
                        box-shadow:
                            0 3px 10px rgba(0, 113, 227, 0.22),
                            0 0 1px rgba(0, 113, 227, 0.12);
                    }

                    .box-ai-message-ai .box-ai-message-content {
                        max-width: 90%;
                        padding: 16px 20px;
                        font-size: 15px;
                        border-radius: 22px 22px 22px 6px;
                        line-height: 1.6;
                        box-shadow:
                            0 3px 12px rgba(0, 0, 0, 0.08),
                            0 0 1px rgba(0, 0, 0, 0.08),
                            inset 0 1px 0 rgba(255, 255, 255, 0.8);
                        border: 1px solid rgba(0, 0, 0, 0.06);
                    }

                    /* Optimized text sizing for readability */
                    .box-ai-message-content h1 {
                        font-size: 19px;
                        font-weight: 700;
                        margin: 14px 0 10px 0;
                    }

                    .box-ai-message-content h2 {
                        font-size: 17px;
                        font-weight: 700;
                        margin: 12px 0 8px 0;
                    }

                    .box-ai-message-content h3 {
                        font-size: 16px;
                        font-weight: 600;
                        margin: 10px 0 6px 0;
                    }

                    .box-ai-message-content h4 {
                        font-size: 15px;
                        font-weight: 600;
                        margin: 10px 0 6px 0;
                    }

                    .box-ai-message-content p {
                        margin: 8px 0;
                        line-height: 1.6;
                    }

                    .box-ai-message-content ul,
                    .box-ai-message-content ol {
                        margin: 10px 0;
                        padding-left: 22px;
                    }

                    .box-ai-message-content li {
                        margin: 6px 0;
                        line-height: 1.5;
                    }

                    .box-ai-message-content code {
                        font-size: 13px;
                        padding: 2px 5px;
                        border-radius: 4px;
                    }

                    .box-ai-message-content pre {
                        padding: 12px;
                        margin: 10px 0;
                        font-size: 13px;
                        border-radius: 8px;
                        overflow-x: auto;
                        -webkit-overflow-scrolling: touch;
                    }

                    /* Mobile input area - Claude-style, optimized for typing */
                    .box-ai-chat-input-container {
                        padding: 16px 18px;
                        /* Critical: respect iPhone home indicator */
                        padding-bottom: calc(16px + env(safe-area-inset-bottom));
                    }

                    .box-ai-chat-input-wrapper {
                        border-width: 2px;
                        border-radius: 26px;
                    }

                    #box-ai-chat-input {
                        padding: 16px 18px;
                        padding-right: 56px; /* More space for larger mobile button */
                        font-size: 17px;
                        /* CRITICAL: 17px for better mobile readability without iOS zoom */
                        min-height: 52px;
                        line-height: 1.5;
                    }

                    #box-ai-chat-input::placeholder {
                        font-size: 17px;
                    }

                    /* Mobile send button - centered and aligned with border */
                    .box-ai-chat-send-btn {
                        width: 40px;
                        height: 40px;
                        right: 8px;
                        top: 50%;
                        bottom: auto;
                        transform: translateY(-50%);
                    }

                    .box-ai-chat-send-btn.has-text:hover {
                        transform: translateY(-50%) scale(1.05);
                    }

                    .box-ai-chat-send-btn.has-text:active {
                        transform: translateY(-50%) scale(0.95);
                    }

                    .box-ai-chat-send-btn .dashicons {
                        font-size: 22px;
                        width: 22px;
                        height: 22px;
                    }

                    /* Loading state */
                    .box-ai-message-loading {
                        font-size: 15px;
                        gap: 10px;
                        padding: 12px 16px;
                    }

                    .box-ai-message-loading .spinner {
                        width: 20px;
                        height: 20px;
                    }
                }

                /* Extra small mobile screens - compact but still comfortable */
                @media (max-width: 360px) {
                    .box-ai-chat-header {
                        padding: 16px 18px;
                        padding-top: max(16px, env(safe-area-inset-top));
                    }

                    .box-ai-chat-title {
                        font-size: 16px;
                    }

                    .box-ai-chat-messages {
                        padding: 18px 16px;
                    }

                    .box-ai-message {
                        margin-bottom: 16px;
                    }

                    /* Bigger bubbles with comfortable text on small screens */
                    .box-ai-message-user .box-ai-message-content {
                        font-size: 15px;
                        padding: 12px 16px;
                        max-width: 85%;
                    }

                    .box-ai-message-ai .box-ai-message-content {
                        font-size: 15px;
                        padding: 14px 18px;
                        max-width: 90%;
                    }

                    .box-ai-welcome-message p {
                        font-size: 15px;
                    }

                    /* Comfortable input on extra small screens */
                    .box-ai-chat-input-container {
                        padding: 14px 16px;
                        padding-bottom: calc(14px + env(safe-area-inset-bottom));
                    }

                    .box-ai-chat-input-wrapper {
                        border-radius: 22px;
                    }

                    #box-ai-chat-input {
                        padding: 14px 16px;
                        padding-right: 52px;
                        font-size: 16px;
                        /* CRITICAL: 16px prevents iOS zoom */
                        min-height: 48px;
                    }

                    #box-ai-chat-input::placeholder {
                        font-size: 16px;
                    }

                    /* Compact but still tappable button - vertically centered */
                    .box-ai-chat-send-btn {
                        width: 36px;
                        height: 36px;
                        right: 8px;
                        top: 50%;
                        bottom: auto;
                        transform: translateY(-50%);
                    }

                    .box-ai-chat-send-btn.has-text:hover {
                        transform: translateY(-50%) scale(1.05);
                    }

                    .box-ai-chat-send-btn.has-text:active {
                        transform: translateY(-50%) scale(0.95);
                    }

                    .box-ai-chat-send-btn .dashicons {
                        font-size: 20px;
                        width: 20px;
                        height: 20px;
                    }

                    .box-ai-chat-close-btn {
                        min-width: 44px;
                        min-height: 44px;
                        touch-action: manipulation;
                    }
                }
            </style>
            <?php
        }, 999);

        // Start WordPress page
        get_header();
        ?>

        <div class="box-document-viewer-wrapper">
            <div class="document-viewer-header">
                <div class="document-info">
                    <div class="document-name"><?php echo esc_html($file_name); ?></div>
                    <div class="document-meta">
                        <?php if ($file_size) : ?>
                            <?php echo esc_html($file_size); ?>
                        <?php endif; ?>
                        <?php if ($modified_at) : ?>
                            <?php echo $file_size ? ' · ' : ''; ?>Modified <?php echo esc_html($modified_at); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="document-actions">
                    <?php if ($chat_ai_enabled) : ?>
                        <button id="box-ai-chat-button" class="btn btn-primary" data-file-id="<?php echo esc_attr($file_info['id']); ?>">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span>Chat with AI</span>
                        </button>
                    <?php endif; ?>
                    <div class="document-actions-right">
                        <a href="<?php echo esc_url($download_url); ?>" class="btn btn-primary btn-download" download title="Download file">
                            <span class="dashicons dashicons-download"></span>
                            <span class="btn-text">Download</span>
                        </a>
                        <button onclick="navigateBack();" class="btn btn-secondary">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                            <span>Back</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="document-viewer-container">
                <?php if ($error_message) : ?>
                    <div class="document-viewer-error">
                        <span class="dashicons dashicons-warning" style="font-size: 48px; width: 48px; height: 48px; color: #d63638;"></span>
                        <p style="margin-top: 20px; font-size: 18px; font-weight: 600;"><?php echo esc_html($error_message); ?></p>
                        <p style="color: #86868b;">You can still download the file using the button above.</p>
                        <a href="<?php echo esc_url($download_url); ?>" class="btn btn-primary" download style="margin-top: 20px;">
                            <span class="dashicons dashicons-download"></span>
                            Download File
                        </a>
                    </div>
                <?php elseif ($embed_url) : ?>
                    <iframe src="<?php echo esc_url($embed_url); ?>" allowfullscreen></iframe>
                <?php else : ?>
                    <div class="document-viewer-error">
                        <span class="dashicons dashicons-info" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>
                        <p style="margin-top: 20px;">Unable to preview this document.</p>
                        <p style="color: #86868b;">The preview may not be available for this file type.</p>
                        <a href="<?php echo esc_url($download_url); ?>" class="btn btn-primary" download style="margin-top: 20px;">
                            <span class="dashicons dashicons-download"></span>
                            Download File
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Box AI Chat Modal -->
            <?php if ($chat_ai_enabled) : ?>
            <div id="box-ai-chat-modal" class="box-ai-chat-modal" style="display: none;">
                <div class="box-ai-chat-overlay"></div>
                <div class="box-ai-chat-container">
                    <div class="box-ai-chat-header">
                        <div class="box-ai-chat-title">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span>Chat with Box AI</span>
                        </div>
                        <button id="box-ai-chat-close" class="box-ai-chat-close-btn">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="box-ai-chat-messages" id="box-ai-chat-messages">
                        <div class="box-ai-welcome-message">
                            <p>Hi! I'm Box AI. I can help you understand this document. Ask me anything!</p>
                        </div>
                    </div>
                    <div class="box-ai-chat-input-container">
                        <div class="box-ai-chat-input-wrapper">
                            <textarea id="box-ai-chat-input" placeholder="Ask a question about this document..." rows="1"></textarea>
                            <button id="box-ai-chat-send" class="box-ai-chat-send-btn">
                                <span class="dashicons dashicons-arrow-up-alt2"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        // Navigate back function for Back button
        function navigateBack() {
            // Check if there's a referrer and it's from the same domain
            if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) {
                // Go back to previous page
                window.history.back();
            } else {
                // Go to homepage if no referrer or external referrer
                window.location.href = '/';
            }
        }

        console.log('=== Box AI Chat Debug ===');
        console.log('Document viewer page loaded');
        console.log('jQuery available:', typeof jQuery !== 'undefined');
        console.log('boxAiChat object:', typeof boxAiChat !== 'undefined' ? boxAiChat : 'NOT FOUND');
        console.log('Chat button exists:', document.getElementById('box-ai-chat-button') !== null);
        console.log('File ID on button:', document.getElementById('box-ai-chat-button') ? document.getElementById('box-ai-chat-button').getAttribute('data-file-id') : 'NO BUTTON');
        console.log('Download URL generated:', <?php echo json_encode(!empty($download_url)); ?>);
        console.log('Download button exists:', document.querySelector('.btn-icon') !== null);
        </script>

        <?php
        get_footer();
    }

    /**
     * Get public URL for a document
     */
    public static function get_document_url($file_id) {
        return home_url('/box-document/' . $file_id . '/');
    }

    /**
     * AJAX handler to get document URL
     */
    public static function ajax_get_document_url() {
        check_ajax_referer('box_search_nonce', 'nonce');

        $file_id = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : '';

        if (empty($file_id)) {
            wp_send_json_error('File ID is required');
        }

        $url = self::get_document_url($file_id);
        wp_send_json_success(array('url' => $url));
    }

    /**
     * Enqueue chat scripts
     */
    public static function enqueue_chat_scripts() {
        // Only enqueue on document viewer pages
        $file_id = get_query_var('box_document_id');

        if ($file_id) {
            // Enqueue dashicons for chat icon
            wp_enqueue_style('dashicons');

            wp_enqueue_script(
                'box-ai-chat',
                BOX_API_PLUGIN_URL . 'assets/js/box-ai-chat.js',
                array('jquery'),
                BOX_API_VERSION,
                true
            );

            wp_localize_script('box-ai-chat', 'boxAiChat', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('box_ai_chat_nonce')
            ));
        }
    }

    /**
     * AJAX handler for Box AI chat
     */
    public static function ajax_box_ai_chat() {
        check_ajax_referer('box_ai_chat_nonce', 'nonce');

        $file_id = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (empty($file_id)) {
            wp_send_json_error('File ID is required');
        }

        if (empty($message)) {
            wp_send_json_error('Message is required');
        }

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            wp_send_json_error('Not authenticated with Box');
        }

        // Call Box AI
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        $response = $client->ai_ask($file_id, $message);

        if (is_wp_error($response)) {
            // Get detailed error information
            $error_data = $response->get_error_data();
            $error_message = $response->get_error_message();

            // Add more context if available
            if (is_array($error_data) && isset($error_data['message'])) {
                $error_message = $error_data['message'];
            }

            $error_code = '';
            if (is_array($error_data) && isset($error_data['code'])) {
                $error_code = $error_data['code'];
            }

            // Handle specific error: insufficient_scope
            if ($error_code === 'insufficient_scope') {
                $error_message = "Box AI requires additional permissions. Please add 'AI' scopes to your Box App:\n\n" .
                                "1. Go to Box Developer Console (https://app.box.com/developers/console)\n" .
                                "2. Select your app\n" .
                                "3. Go to 'Configuration' tab\n" .
                                "4. Under 'Application Scopes', enable:\n" .
                                "   - 'Read and write all files and folders'\n" .
                                "   - 'Manage AI' (if available)\n" .
                                "5. Click 'Save Changes'\n" .
                                "6. Go back to WordPress and re-authenticate (logout and login again)\n\n" .
                                "Note: Your Box account must have Box AI enabled (requires Enterprise Plus plan)";
            } elseif ($error_code) {
                $error_message .= ' (Code: ' . $error_code . ')';
            }

            error_log('Box AI Error: ' . print_r($error_data, true));
            wp_send_json_error($error_message);
        }

        // Log successful response for debugging
        error_log('Box AI Response: ' . print_r($response, true));

        // Extract answer from response
        $answer = '';
        if (isset($response['answer'])) {
            $answer = $response['answer'];
        } elseif (isset($response['completion'])) {
            $answer = $response['completion'];
        } elseif (isset($response['choices'][0]['message']['content'])) {
            // Alternative response format
            $answer = $response['choices'][0]['message']['content'];
        } else {
            error_log('Box AI: Unexpected response format: ' . print_r($response, true));
            wp_send_json_error('Unexpected response format from Box AI. Please check the error logs.');
        }

        wp_send_json_success(array(
            'answer' => $answer,
            'created_at' => isset($response['created_at']) ? $response['created_at'] : current_time('mysql')
        ));
    }

    /**
     * AJAX handler for file downloads
     * Proxies the download request with proper authentication
     */
    public static function ajax_download_file() {
        // Verify nonce
        $file_id = isset($_GET['file_id']) ? sanitize_text_field($_GET['file_id']) : '';
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';

        if (empty($file_id)) {
            wp_die('File ID is required');
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, 'box_download_' . $file_id)) {
            wp_die('Invalid security token');
        }

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            wp_die('Not authenticated with Box');
        }

        // Get file info
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        $file_info = $client->get_file_info($file_id);

        if (is_wp_error($file_info)) {
            wp_die('File not found or access denied');
        }

        // Get the file content
        $access_token = get_option('box_access_token');
        $download_url = "https://api.box.com/2.0/files/{$file_id}/content";

        // Stream the file
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 300, // 5 minutes for large files
            'stream' => true,
            'filename' => wp_tempnam()
        );

        $response = wp_remote_get($download_url, $args);

        if (is_wp_error($response)) {
            wp_die('Failed to download file: ' . $response->get_error_message());
        }

        // Get the temporary file path
        $temp_file = $args['filename'];

        // Set headers for download
        $file_name = isset($file_info['name']) ? $file_info['name'] : 'download';
        $file_size = isset($file_info['size']) ? $file_info['size'] : filesize($temp_file);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: no-cache');
        header('Pragma: no-cache');

        // Stream the file to the user
        readfile($temp_file);

        // Clean up
        @unlink($temp_file);

        exit;
    }
}
