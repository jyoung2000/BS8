<?php
/**
 * File Manager Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current folder
$current_folder_id = isset($_GET['folder']) ? sanitize_text_field($_GET['folder']) : '0';

// Get credentials
$credentials = Box_API_Integration::get_instance()->get_credentials();
$folder_manager = new Box_Folder_Manager($credentials);

// Get breadcrumb
$breadcrumb = $folder_manager->get_breadcrumb($current_folder_id);
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-portfolio"></span>
        <?php _e('Box File Manager', 'box-api-integration'); ?>
    </h1>
    
    <div class="box-file-manager-container">
        
        <!-- Toolbar -->
        <div class="box-toolbar">
            <div class="box-breadcrumb">
                <?php foreach ($breadcrumb as $index => $crumb) : ?>
                    <?php if ($index > 0) echo ' / '; ?>
                    <?php if ($index === count($breadcrumb) - 1) : ?>
                        <strong><?php echo esc_html($crumb['name']); ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=box-file-manager&folder=' . $crumb['id'])); ?>">
                            <?php echo esc_html($crumb['name']); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <div class="box-actions">
                <button type="button" class="button" id="refresh-files">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh', 'box-api-integration'); ?>
                </button>
                
                <button type="button" class="button" id="upload-files">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Upload', 'box-api-integration'); ?>
                </button>
                
                <button type="button" class="button" id="new-folder">
                    <span class="dashicons dashicons-category"></span>
                    <?php _e('New Folder', 'box-api-integration'); ?>
                </button>
                
                <div class="box-search">
                    <input type="search" id="box-search" placeholder="<?php esc_attr_e('Search files...', 'box-api-integration'); ?>" />
                </div>
            </div>
        </div>
        
        <!-- File Browser -->
        <div class="box-browser-section">
            <?php Box_Admin::render_file_browser($current_folder_id); ?>
        </div>
        
        <!-- Bulk Actions -->
        <div class="box-bulk-actions" style="display: none;">
            <label><?php _e('Bulk Actions:', 'box-api-integration'); ?></label>
            <button type="button" class="button" id="bulk-download">
                <?php _e('Download', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button" id="bulk-move">
                <?php _e('Move', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button" id="bulk-copy">
                <?php _e('Copy', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="bulk-delete">
                <?php _e('Delete', 'box-api-integration'); ?>
            </button>
        </div>
        
        <!-- File Preview Panel -->
        <div id="file-preview-panel" class="box-preview-panel" style="display: none;">
            <div class="preview-header">
                <h3 id="preview-filename"></h3>
                <button type="button" class="close-preview">&times;</button>
            </div>
            
            <div class="preview-content">
                <div class="preview-info">
                    <p><strong><?php _e('Type:', 'box-api-integration'); ?></strong> <span id="preview-type"></span></p>
                    <p><strong><?php _e('Size:', 'box-api-integration'); ?></strong> <span id="preview-size"></span></p>
                    <p><strong><?php _e('Modified:', 'box-api-integration'); ?></strong> <span id="preview-modified"></span></p>
                    <p><strong><?php _e('Created:', 'box-api-integration'); ?></strong> <span id="preview-created"></span></p>
                </div>
                
                <div class="preview-actions">
                    <button type="button" class="button button-primary" id="preview-download">
                        <?php _e('Download', 'box-api-integration'); ?>
                    </button>
                    <button type="button" class="button" id="preview-share">
                        <?php _e('Share', 'box-api-integration'); ?>
                    </button>
                    <button type="button" class="button" id="preview-move">
                        <?php _e('Move', 'box-api-integration'); ?>
                    </button>
                    <button type="button" class="button" id="preview-rename">
                        <?php _e('Rename', 'box-api-integration'); ?>
                    </button>
                    <button type="button" class="button button-link-delete" id="preview-delete">
                        <?php _e('Delete', 'box-api-integration'); ?>
                    </button>
                </div>
                
                <div class="preview-image" style="display: none;">
                    <img id="preview-img" src="" alt="" />
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- Upload Modal -->
<div id="upload-modal" class="box-modal" style="display: none;">
    <div class="box-modal-content">
        <div class="modal-header">
            <h2><?php _e('Upload Files', 'box-api-integration'); ?></h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        
        <input type="hidden" id="upload-folder-id" value="<?php echo esc_attr($current_folder_id); ?>" />
        
        <?php Box_Admin::render_upload_form($current_folder_id); ?>
    </div>
</div>

<!-- New Folder Modal -->
<div id="new-folder-modal" class="box-modal" style="display: none;">
    <div class="box-modal-content">
        <div class="modal-header">
            <h2><?php _e('Create New Folder', 'box-api-integration'); ?></h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        
        <div class="modal-body">
            <p>
                <label for="folder-name"><?php _e('Folder Name:', 'box-api-integration'); ?></label>
                <input type="text" id="folder-name" class="regular-text" />
            </p>
            
            <input type="hidden" id="parent-folder-id" value="<?php echo esc_attr($current_folder_id); ?>" />
        </div>
        
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="create-folder-btn">
                <?php _e('Create Folder', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button modal-close">
                <?php _e('Cancel', 'box-api-integration'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Move/Copy Modal -->
<div id="move-copy-modal" class="box-modal" style="display: none;">
    <div class="box-modal-content">
        <div class="modal-header">
            <h2 id="move-copy-title"><?php _e('Select Destination', 'box-api-integration'); ?></h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        
        <div class="modal-body">
            <p>
                <label for="destination-folder"><?php _e('Select folder:', 'box-api-integration'); ?></label>
                <?php Box_Admin::render_folder_select('0', 'destination_folder', 'destination-folder'); ?>
            </p>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="confirm-move-copy">
                <?php _e('Confirm', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button modal-close">
                <?php _e('Cancel', 'box-api-integration'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Share Modal -->
<div id="share-modal" class="box-modal" style="display: none;">
    <div class="box-modal-content">
        <div class="modal-header">
            <h2><?php _e('Share File', 'box-api-integration'); ?></h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        
        <div class="modal-body">
            <h3><?php _e('Create Shared Link', 'box-api-integration'); ?></h3>
            <p>
                <label for="share-access"><?php _e('Access Level:', 'box-api-integration'); ?></label>
                <select id="share-access">
                    <option value="open"><?php _e('Public - Anyone with link', 'box-api-integration'); ?></option>
                    <option value="company"><?php _e('Company - Logged in users only', 'box-api-integration'); ?></option>
                    <option value="collaborators"><?php _e('Collaborators - Specific people only', 'box-api-integration'); ?></option>
                </select>
            </p>
            
            <p>
                <label for="share-password">
                    <input type="checkbox" id="share-password-enabled" />
                    <?php _e('Password protect', 'box-api-integration'); ?>
                </label>
                <input type="password" id="share-password" class="regular-text" style="display: none;" />
            </p>
            
            <div id="share-link-result" style="display: none;">
                <p><strong><?php _e('Shared Link:', 'box-api-integration'); ?></strong></p>
                <input type="text" id="shared-link-url" class="large-text" readonly />
                <button type="button" class="button" id="copy-share-link">
                    <?php _e('Copy Link', 'box-api-integration'); ?>
                </button>
            </div>
            
            <hr />
            
            <h3><?php _e('Add Collaborators', 'box-api-integration'); ?></h3>
            <p>
                <label for="collaborator-email"><?php _e('Email:', 'box-api-integration'); ?></label>
                <input type="email" id="collaborator-email" class="regular-text" />
            </p>
            
            <p>
                <label for="collaborator-role"><?php _e('Permission:', 'box-api-integration'); ?></label>
                <select id="collaborator-role">
                    <option value="viewer"><?php _e('Viewer - View only', 'box-api-integration'); ?></option>
                    <option value="editor"><?php _e('Editor - Edit files', 'box-api-integration'); ?></option>
                    <option value="co-owner"><?php _e('Co-owner - Full access', 'box-api-integration'); ?></option>
                </select>
            </p>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="create-share">
                <?php _e('Create Link', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button" id="add-collaborator">
                <?php _e('Add Collaborator', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button modal-close">
                <?php _e('Close', 'box-api-integration'); ?>
            </button>
        </div>
    </div>
</div>
