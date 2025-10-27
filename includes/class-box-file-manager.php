<?php
/**
 * Box File Manager Class
 * Handles file operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_File_Manager {
    
    private $client;
    
    /**
     * Constructor
     */
    public function __construct($credentials) {
        $this->client = new Box_API_Client($credentials);
    }
    
    /**
     * Upload file
     */
    public function upload_file($file, $folder_id = '0') {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return array(
                'success' => false,
                'message' => __('Invalid file upload', 'box-api-integration')
            );
        }
        
        // Check file size (Box limit is 50MB for direct upload)
        if ($file['size'] > 50 * 1024 * 1024) {
            return $this->chunked_upload($file, $folder_id);
        }
        
        // Prepare multipart request
        $boundary = wp_generate_password(24, false);
        
        // Build attributes JSON
        $attributes = json_encode(array(
            'name' => $file['name'],
            'parent' => array('id' => $folder_id)
        ));
        
        // Read file content
        $file_content = file_get_contents($file['tmp_name']);
        
        // Build multipart body
        $body = '';
        
        // Add attributes part
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"attributes\"\r\n\r\n";
        $body .= $attributes . "\r\n";
        
        // Add file part
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$file['name']}\"\r\n";
        $body .= "Content-Type: {$file['type']}\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--{$boundary}--\r\n";
        
        // Make upload request
        $response = wp_remote_post(BOX_UPLOAD_URL . 'files/content', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . get_option('box_access_token'),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary
            ),
            'body' => $body,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 201 && isset($body['entries'][0])) {
            $this->log_upload($body['entries'][0]);
            
            return array(
                'success' => true,
                'file' => $body['entries'][0],
                'message' => __('File uploaded successfully', 'box-api-integration')
            );
        }
        
        // Handle specific error cases
        if ($response_code === 409) {
            return array(
                'success' => false,
                'message' => __('A file with this name already exists', 'box-api-integration'),
                'conflict' => true
            );
        }
        
        $error_message = isset($body['message']) ? $body['message'] : __('Upload failed', 'box-api-integration');
        
        return array(
            'success' => false,
            'message' => $error_message
        );
    }
    
    /**
     * Chunked upload for large files
     */
    public function chunked_upload($file, $folder_id = '0') {
        // Create upload session
        $session_data = array(
            'folder_id' => $folder_id,
            'file_size' => $file['size'],
            'file_name' => $file['name']
        );
        
        $session = $this->client->request('files/upload_sessions', 'POST', $session_data);
        
        if (is_wp_error($session)) {
            return array(
                'success' => false,
                'message' => __('Failed to create upload session', 'box-api-integration')
            );
        }
        
        // Upload chunks
        $chunk_size = 5 * 1024 * 1024; // 5MB chunks
        $handle = fopen($file['tmp_name'], 'rb');
        $offset = 0;
        
        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);
            $chunk_length = strlen($chunk);
            
            $headers = array(
                'Authorization' => 'Bearer ' . get_option('box_access_token'),
                'Content-Type' => 'application/octet-stream',
                'Digest' => 'sha=' . base64_encode(sha1($chunk, true)),
                'Content-Range' => "bytes {$offset}-" . ($offset + $chunk_length - 1) . "/{$file['size']}"
            );
            
            $response = wp_remote_request($session['session_endpoints']['upload_part'], array(
                'method' => 'PUT',
                'headers' => $headers,
                'body' => $chunk,
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                fclose($handle);
                $this->client->request("files/upload_sessions/{$session['id']}", 'DELETE');
                return array(
                    'success' => false,
                    'message' => __('Chunk upload failed', 'box-api-integration')
                );
            }
            
            $offset += $chunk_length;
        }
        
        fclose($handle);
        
        // Commit upload
        $commit_data = array(
            'parts' => $session['parts'],
            'attributes' => array(
                'name' => $file['name']
            )
        );
        
        $result = $this->client->request(
            "files/upload_sessions/{$session['id']}/commit",
            'POST',
            $commit_data
        );
        
        if (!is_wp_error($result) && isset($result['entries'][0])) {
            $this->log_upload($result['entries'][0]);
            
            return array(
                'success' => true,
                'file' => $result['entries'][0],
                'message' => __('Large file uploaded successfully', 'box-api-integration')
            );
        }
        
        return array(
            'success' => false,
            'message' => __('Failed to complete upload', 'box-api-integration')
        );
    }
    
    /**
     * Upload from URL
     */
    public function upload_from_url($url, $folder_id = '0', $filename = null) {
        // Download file to temp
        $temp_file = download_url($url);
        
        if (is_wp_error($temp_file)) {
            return array(
                'success' => false,
                'message' => __('Failed to download file from URL', 'box-api-integration')
            );
        }
        
        // Get filename from URL if not provided
        if (!$filename) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            if (!$filename) {
                $filename = 'download-' . time();
            }
        }
        
        // Create file array
        $file = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
            'type' => mime_content_type($temp_file),
            'size' => filesize($temp_file)
        );
        
        // Upload file
        $result = $this->upload_file($file, $folder_id);
        
        // Clean up temp file
        @unlink($temp_file);
        
        return $result;
    }
    
    /**
     * Replace file content
     */
    public function update_file_content($file_id, $file) {
        // Read file content
        $file_content = file_get_contents($file['tmp_name']);
        
        $response = wp_remote_post(BOX_UPLOAD_URL . "files/{$file_id}/content", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . get_option('box_access_token'),
                'Content-Type' => $file['type']
            ),
            'body' => $file_content,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200 && isset($body['entries'][0])) {
            return array(
                'success' => true,
                'file' => $body['entries'][0],
                'message' => __('File updated successfully', 'box-api-integration')
            );
        }
        
        return array(
            'success' => false,
            'message' => __('Failed to update file', 'box-api-integration')
        );
    }
    
    /**
     * Download file to server
     */
    public function download_to_server($file_id, $destination_path = null) {
        // Get file info
        $file_info = $this->client->get_file_info($file_id);
        
        if (is_wp_error($file_info)) {
            return array(
                'success' => false,
                'message' => __('Failed to get file info', 'box-api-integration')
            );
        }
        
        // Set destination
        if (!$destination_path) {
            $upload_dir = wp_upload_dir();
            $destination_path = $upload_dir['path'] . '/' . $file_info['name'];
        }
        
        // Get download URL
        $download_url = $this->client->get_download_url($file_id);
        
        if (!$download_url) {
            return array(
                'success' => false,
                'message' => __('Failed to get download URL', 'box-api-integration')
            );
        }
        
        // Download file
        $response = wp_remote_get($download_url, array(
            'timeout' => 120,
            'stream' => true,
            'filename' => $destination_path
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        return array(
            'success' => true,
            'path' => $destination_path,
            'message' => __('File downloaded successfully', 'box-api-integration')
        );
    }
    
    /**
     * Get file thumbnail
     */
    public function get_thumbnail($file_id, $size = '256x256') {
        $endpoint = "files/{$file_id}/thumbnail.{$size}";
        
        $response = wp_remote_get(BOX_API_BASE_URL . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . get_option('box_access_token')
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return '';
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $body = wp_remote_retrieve_body($response);
            return 'data:image/png;base64,' . base64_encode($body);
        }
        
        return '';
    }
    
    /**
     * Log file upload
     */
    private function log_upload($file_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'box_api_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'action' => 'file_upload',
                'file_id' => $file_data['id'],
                'file_name' => $file_data['name'],
                'folder_id' => $file_data['parent']['id'],
                'user_id' => get_current_user_id(),
                'status' => 'success',
                'message' => 'File uploaded: ' . $file_data['name'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get supported file types
     */
    public static function get_supported_types() {
        return array(
            'documents' => array('doc', 'docx', 'pdf', 'txt', 'rtf', 'odt'),
            'spreadsheets' => array('xls', 'xlsx', 'csv', 'ods'),
            'presentations' => array('ppt', 'pptx', 'odp'),
            'images' => array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'),
            'videos' => array('mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv'),
            'audio' => array('mp3', 'wav', 'flac', 'aac', 'ogg'),
            'archives' => array('zip', 'rar', '7z', 'tar', 'gz')
        );
    }
    
    /**
     * Get file icon
     */
    public static function get_file_icon($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $icons = array(
            'doc' => 'dashicons-media-document',
            'docx' => 'dashicons-media-document',
            'pdf' => 'dashicons-pdf',
            'xls' => 'dashicons-media-spreadsheet',
            'xlsx' => 'dashicons-media-spreadsheet',
            'ppt' => 'dashicons-media-interactive',
            'pptx' => 'dashicons-media-interactive',
            'jpg' => 'dashicons-format-image',
            'jpeg' => 'dashicons-format-image',
            'png' => 'dashicons-format-image',
            'gif' => 'dashicons-format-image',
            'mp4' => 'dashicons-format-video',
            'mp3' => 'dashicons-format-audio',
            'zip' => 'dashicons-media-archive',
            'folder' => 'dashicons-category'
        );
        
        return isset($icons[$extension]) ? $icons[$extension] : 'dashicons-media-default';
    }
}
