<?php
/**
 * Box Folder Manager Class
 * Handles folder operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Folder_Manager {
    
    private $client;
    
    /**
     * Constructor
     */
    public function __construct($credentials) {
        $this->client = new Box_API_Client($credentials);
    }
    
    /**
     * Create folder
     */
    public function create_folder($name, $parent_id = '0') {
        // Validate folder name
        if (empty($name)) {
            return array(
                'success' => false,
                'message' => __('Folder name is required', 'box-api-integration')
            );
        }
        
        // Check if folder already exists
        $existing = $this->folder_exists($name, $parent_id);
        if ($existing) {
            return array(
                'success' => false,
                'message' => __('A folder with this name already exists', 'box-api-integration'),
                'folder' => $existing
            );
        }
        
        // Create folder
        $result = $this->client->create_folder($name, $parent_id);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        $this->log_folder_action('create', $result);
        
        return array(
            'success' => true,
            'folder' => $result,
            'message' => sprintf(__('Folder "%s" created successfully', 'box-api-integration'), $name)
        );
    }
    
    /**
     * Check if folder exists
     */
    public function folder_exists($name, $parent_id = '0') {
        $items = $this->client->list_folder_items($parent_id);
        
        if (!is_wp_error($items) && isset($items['entries'])) {
            foreach ($items['entries'] as $item) {
                if ($item['type'] === 'folder' && 
                    strcasecmp($item['name'], $name) === 0) {
                    return $item;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get folder tree
     */
    public function get_folder_tree($parent_id = '0', $depth = 0, $max_depth = 3) {
        if ($depth >= $max_depth) {
            return array();
        }
        
        $folders = array();
        $items = $this->client->list_folder_items($parent_id, 100, 0);
        
        if (!is_wp_error($items) && isset($items['entries'])) {
            foreach ($items['entries'] as $item) {
                if ($item['type'] === 'folder') {
                    $folder = array(
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'parent_id' => $parent_id,
                        'depth' => $depth,
                        'children' => array()
                    );
                    
                    // Recursively get subfolders
                    if ($depth < $max_depth - 1) {
                        $folder['children'] = $this->get_folder_tree(
                            $item['id'], 
                            $depth + 1, 
                            $max_depth
                        );
                    }
                    
                    $folders[] = $folder;
                }
            }
        }
        
        return $folders;
    }
    
    /**
     * Get folder breadcrumb
     */
    public function get_breadcrumb($folder_id) {
        if ($folder_id === '0') {
            return array(
                array('id' => '0', 'name' => __('Root', 'box-api-integration'))
            );
        }
        
        $folder = $this->client->get_folder_info($folder_id);
        
        if (is_wp_error($folder)) {
            return array();
        }
        
        $breadcrumb = array();
        
        // Build breadcrumb from path collection
        if (isset($folder['path_collection']['entries'])) {
            foreach ($folder['path_collection']['entries'] as $ancestor) {
                $breadcrumb[] = array(
                    'id' => $ancestor['id'],
                    'name' => $ancestor['name']
                );
            }
        }
        
        // Add current folder
        $breadcrumb[] = array(
            'id' => $folder['id'],
            'name' => $folder['name']
        );
        
        return $breadcrumb;
    }
    
    /**
     * Copy folder
     */
    public function copy_folder($folder_id, $parent_id, $name = null) {
        $data = array(
            'parent' => array('id' => $parent_id)
        );
        
        if ($name) {
            $data['name'] = $name;
        }
        
        $result = $this->client->request("folders/{$folder_id}/copy", 'POST', $data);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        $this->log_folder_action('copy', $result);
        
        return array(
            'success' => true,
            'folder' => $result,
            'message' => __('Folder copied successfully', 'box-api-integration')
        );
    }
    
    /**
     * Move folder
     */
    public function move_folder($folder_id, $parent_id) {
        $data = array(
            'parent' => array('id' => $parent_id)
        );
        
        $result = $this->client->request("folders/{$folder_id}", 'PUT', $data);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        $this->log_folder_action('move', $result);
        
        return array(
            'success' => true,
            'folder' => $result,
            'message' => __('Folder moved successfully', 'box-api-integration')
        );
    }
    
    /**
     * Rename folder
     */
    public function rename_folder($folder_id, $new_name) {
        $data = array(
            'name' => $new_name
        );
        
        $result = $this->client->request("folders/{$folder_id}", 'PUT', $data);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        $this->log_folder_action('rename', $result);
        
        return array(
            'success' => true,
            'folder' => $result,
            'message' => __('Folder renamed successfully', 'box-api-integration')
        );
    }
    
    /**
     * Delete folder
     */
    public function delete_folder($folder_id, $recursive = true) {
        $result = $this->client->delete_folder($folder_id, $recursive);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        $this->log_folder_action('delete', array('id' => $folder_id));
        
        return array(
            'success' => true,
            'message' => __('Folder deleted successfully', 'box-api-integration')
        );
    }
    
    /**
     * Get folder size
     */
    public function get_folder_size($folder_id) {
        $total_size = 0;
        $file_count = 0;
        $folder_count = 0;
        
        $offset = 0;
        $limit = 100;
        
        do {
            $items = $this->client->list_folder_items($folder_id, $limit, $offset);
            
            if (is_wp_error($items) || !isset($items['entries'])) {
                break;
            }
            
            foreach ($items['entries'] as $item) {
                if ($item['type'] === 'file') {
                    $total_size += $item['size'];
                    $file_count++;
                } elseif ($item['type'] === 'folder') {
                    $folder_count++;
                    // Recursively get subfolder size
                    $subfolder_info = $this->get_folder_size($item['id']);
                    $total_size += $subfolder_info['size'];
                    $file_count += $subfolder_info['files'];
                    $folder_count += $subfolder_info['folders'];
                }
            }
            
            $offset += $limit;
        } while (count($items['entries']) === $limit);
        
        return array(
            'size' => $total_size,
            'files' => $file_count,
            'folders' => $folder_count,
            'formatted_size' => size_format($total_size)
        );
    }
    
    /**
     * Create folder structure
     */
    public function create_folder_structure($path, $parent_id = '0') {
        $folders = explode('/', trim($path, '/'));
        $current_parent = $parent_id;
        $created_folders = array();
        
        foreach ($folders as $folder_name) {
            if (empty($folder_name)) {
                continue;
            }
            
            // Check if folder exists
            $existing = $this->folder_exists($folder_name, $current_parent);
            
            if ($existing) {
                $current_parent = $existing['id'];
                $created_folders[] = $existing;
            } else {
                // Create folder
                $result = $this->create_folder($folder_name, $current_parent);
                
                if (!$result['success']) {
                    return array(
                        'success' => false,
                        'message' => sprintf(
                            __('Failed to create folder "%s": %s', 'box-api-integration'),
                            $folder_name,
                            $result['message']
                        )
                    );
                }
                
                $current_parent = $result['folder']['id'];
                $created_folders[] = $result['folder'];
            }
        }
        
        return array(
            'success' => true,
            'folders' => $created_folders,
            'final_folder_id' => $current_parent,
            'message' => __('Folder structure created successfully', 'box-api-integration')
        );
    }
    
    /**
     * Share folder
     */
    public function share_folder($folder_id, $access = 'open', $password = null) {
        $data = array(
            'shared_link' => array(
                'access' => $access // open, company, collaborators
            )
        );
        
        if ($password) {
            $data['shared_link']['password'] = $password;
        }
        
        $result = $this->client->request("folders/{$folder_id}", 'PUT', $data);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        return array(
            'success' => true,
            'shared_link' => $result['shared_link']['url'],
            'message' => __('Folder shared successfully', 'box-api-integration')
        );
    }
    
    /**
     * Get folder collaborations
     */
    public function get_collaborations($folder_id) {
        return $this->client->get_collaborations($folder_id);
    }
    
    /**
     * Add collaboration
     */
    public function add_collaboration($folder_id, $email, $role = 'viewer') {
        $result = $this->client->add_collaboration($folder_id, $email, $role);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        return array(
            'success' => true,
            'collaboration' => $result,
            'message' => sprintf(
                __('User %s added as %s', 'box-api-integration'),
                $email,
                $role
            )
        );
    }
    
    /**
     * Log folder action
     */
    private function log_folder_action($action, $folder_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'box_api_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'action' => 'folder_' . $action,
                'folder_id' => $folder_data['id'] ?? '',
                'file_name' => $folder_data['name'] ?? '',
                'user_id' => get_current_user_id(),
                'status' => 'success',
                'message' => sprintf('Folder %s: %s', $action, $folder_data['name'] ?? ''),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
}
