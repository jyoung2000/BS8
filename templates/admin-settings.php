<?php
/**
 * Admin Settings Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current values
$client_id = get_option('box_client_id', '');
$client_secret = get_option('box_client_secret', '');
$enterprise_id = get_option('box_enterprise_id', '');
$redirect_uri = get_option('box_redirect_uri', admin_url('admin.php?page=box-api-integration&box_oauth_callback=1'));
$refresh_interval = get_option('box_token_refresh_interval', 50);
$keep_alive = get_option('box_keep_alive', false);

// Get chat customization colors (Box blue: #0061D5)
$chat_ai_enabled = get_option('box_chat_ai_enabled', true);
$ai_button_color = get_option('box_chat_ai_button_color', '#0061D5');
$header_color = get_option('box_chat_header_color', '#0061D5');
$submit_color = get_option('box_chat_submit_color', '#0061D5');

// Check for messages
if (isset($_GET['auth_success'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Successfully authenticated with Box!', 'box-api-integration') . '</p></div>';
}

if (isset($_GET['auth_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . __('Authentication failed. Please try again.', 'box-api-integration') . '</p></div>';
}

if (isset($_GET['settings-updated'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'box-api-integration') . '</p></div>';
}
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-cloud"></span>
        <?php echo esc_html(get_admin_page_title()); ?>
    </h1>
    
    <div class="box-admin-container">

        <!-- Box Plan Requirements -->
        <div class="box-section">
            <div class="notice notice-info inline">
                <p>
                    <strong><span class="dashicons dashicons-info"></span> <?php _e('Box Plan Requirements', 'box-api-integration'); ?></strong>
                </p>
                <p>
                    <?php _e('This plugin works with all Box plans. However, certain features require specific plan levels:', 'box-api-integration'); ?>
                </p>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li><strong><?php _e('Basic File Operations:', 'box-api-integration'); ?></strong> <?php _e('All Box plans (Individual, Business, Enterprise)', 'box-api-integration'); ?></li>
                    <li><strong><?php _e('Box AI (Chat with AI):', 'box-api-integration'); ?></strong> <?php _e('Requires Box Enterprise Plus plan or higher with Box AI enabled', 'box-api-integration'); ?></li>
                    <li><strong><?php _e('Advanced Features:', 'box-api-integration'); ?></strong> <?php _e('Some features may require Box Business or Enterprise plans', 'box-api-integration'); ?></li>
                </ul>
                <p style="margin-top: 10px;">
                    <?php _e('For more information about Box plans and pricing, visit', 'box-api-integration'); ?>
                    <a href="https://www.box.com/pricing" target="_blank"><?php _e('Box Pricing', 'box-api-integration'); ?></a>
                </p>
            </div>
        </div>

        <!-- Connection Status -->
        <div class="box-section">
            <h2><?php _e('Connection Status', 'box-api-integration'); ?></h2>
            <?php echo Box_Admin::get_connection_status(); ?>
        </div>
        
        <!-- API Credentials -->
        <div class="box-section">
            <h2><?php _e('Box API Credentials', 'box-api-integration'); ?></h2>
            
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php _e('Security Notice:', 'box-api-integration'); ?></strong>
                    <?php _e('Never share your API credentials publicly. Store them securely and rotate them regularly.', 'box-api-integration'); ?>
                </p>
            </div>
            
            <form method="post" action="options.php" id="box-settings-form">
                <?php settings_fields('box_api_settings'); ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="box_client_id"><?php _e('Client ID', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="box_client_id" 
                                   name="box_client_id" 
                                   value="<?php echo esc_attr($client_id); ?>" 
                                   class="regular-text code" 
                                   placeholder="<?php esc_attr_e('Your Box App Client ID', 'box-api-integration'); ?>" />
                            <p class="description">
                                <?php _e('Get this from your Box App in the Box Developer Console', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="box_client_secret"><?php _e('Client Secret', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="password" 
                                   id="box_client_secret" 
                                   name="box_client_secret" 
                                   value="<?php echo esc_attr($client_secret); ?>" 
                                   class="regular-text code" 
                                   placeholder="<?php esc_attr_e('Your Box App Client Secret', 'box-api-integration'); ?>" />
                            <button type="button" class="button" id="toggle-secret">
                                <?php _e('Show/Hide', 'box-api-integration'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Keep this secret and secure!', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="box_enterprise_id"><?php _e('Enterprise ID', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="box_enterprise_id" 
                                   name="box_enterprise_id" 
                                   value="<?php echo esc_attr($enterprise_id); ?>" 
                                   class="regular-text" 
                                   placeholder="<?php esc_attr_e('Optional - Your Box Enterprise ID', 'box-api-integration'); ?>" />
                            <p class="description">
                                <?php _e('Optional - Required only for enterprise features', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="box_redirect_uri"><?php _e('Redirect URI', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                   id="box_redirect_uri"
                                   name="box_redirect_uri"
                                   value="<?php echo esc_attr($redirect_uri); ?>"
                                   class="large-text code"
                                   readonly />
                            <button type="button" class="button" id="copy-redirect-uri">
                                <?php _e('Copy', 'box-api-integration'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Add this URL to your Box App\'s Redirect URIs in the Box Developer Console', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="box_keep_alive"><?php _e('Keep Connection Alive', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="box_keep_alive"
                                       name="box_keep_alive"
                                       value="1"
                                       <?php checked($keep_alive, true); ?> />
                                <?php _e('Automatically refresh tokens to maintain continuous authentication', 'box-api-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('When enabled, tokens will be automatically refreshed every 45 minutes to maintain an active connection indefinitely. This overrides the manual refresh interval setting below.', 'box-api-integration'); ?>
                            </p>
                            <p class="description">
                                <strong><?php _e('Recommended:', 'box-api-integration'); ?></strong>
                                <?php _e('Enable this option if you need uninterrupted access to Box without re-authentication.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="manual-interval-row">
                        <th scope="row">
                            <label for="box_token_refresh_interval"><?php _e('Manual Refresh Interval', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="box_token_refresh_interval"
                                   name="box_token_refresh_interval"
                                   value="<?php echo esc_attr($refresh_interval); ?>"
                                   class="small-text"
                                   min="5"
                                   max="55"
                                   step="1"
                                   <?php disabled($keep_alive, true); ?> />
                            <span><?php _e('minutes', 'box-api-integration'); ?></span>
                            <p class="description">
                                <?php _e('How often to refresh the access token (Box tokens expire after 60 minutes). Recommended: 50 minutes. Valid range: 5-55 minutes.', 'box-api-integration'); ?>
                            </p>
                            <p class="description">
                                <strong><?php _e('Note:', 'box-api-integration'); ?></strong>
                                <?php _e('This setting is only used when "Keep Connection Alive" is disabled. Changes take effect after the next token refresh or when you re-authorize with Box.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <?php submit_button(__('Save Settings', 'box-api-integration'), 'primary', 'submit', false); ?>
                    <button type="button" class="button" id="test-connection">
                        <?php _e('Test Connection', 'box-api-integration'); ?>
                    </button>
                    <?php if (!empty($client_id) && !empty($client_secret)) : ?>
                        <a href="<?php echo esc_url(Box_Admin::get_auth_url()); ?>" class="button">
                            <?php _e('Authorize with Box', 'box-api-integration'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        
        <!-- Quick Actions -->
        <div class="box-section">
            <h2><?php _e('Quick Actions', 'box-api-integration'); ?></h2>
            
            <div class="box-quick-actions">
                <a href="<?php echo admin_url('admin.php?page=box-file-manager'); ?>" class="button button-large">
                    <span class="dashicons dashicons-media-document"></span>
                    <?php _e('File Manager', 'box-api-integration'); ?>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=box-upload'); ?>" class="button button-large">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Upload Files', 'box-api-integration'); ?>
                </a>
                
                <button type="button" class="button button-large" id="create-folder">
                    <span class="dashicons dashicons-category"></span>
                    <?php _e('Create Folder', 'box-api-integration'); ?>
                </button>
                
                <button type="button" class="button button-large" id="refresh-token">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh Token', 'box-api-integration'); ?>
                </button>

                <button type="button" class="button button-large" id="flush-permalinks">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php _e('Flush Permalinks', 'box-api-integration'); ?>
                </button>
            </div>
            <p class="description" style="margin-top: 10px;">
                <?php _e('Click "Flush Permalinks" if box-document links are not working (returning 404 errors).', 'box-api-integration'); ?>
            </p>
        </div>
        
        <!-- Storage Info -->
        <?php Box_Admin::render_storage_info(); ?>
        
        <!-- Recent Activity -->
        <?php Box_Admin::render_recent_activity(10); ?>

        <!-- Chat Customization -->
        <div class="box-section">
            <h2><?php _e('Box AI Chat Settings', 'box-api-integration'); ?></h2>

            <div class="notice notice-warning inline">
                <p>
                    <strong><span class="dashicons dashicons-warning"></span> <?php _e('Note:', 'box-api-integration'); ?></strong>
                    <?php _e('Box AI requires a Box Enterprise Plus plan or higher. If you do not have this plan, you can disable the Chat with AI button below.', 'box-api-integration'); ?>
                </p>
            </div>

            <form method="post" action="options.php" id="box-chat-customization-form">
                <?php settings_fields('box_chat_customization'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="box_chat_ai_enabled"><?php _e('Enable Chat with AI Button', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="box_chat_ai_enabled"
                                       name="box_chat_ai_enabled"
                                       value="1"
                                       <?php checked($chat_ai_enabled, true); ?> />
                                <?php _e('Show "Chat with AI" button on document viewer pages', 'box-api-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Enable this to show the Chat with AI button on document pages. Requires Box Enterprise Plus plan with Box AI enabled.', 'box-api-integration'); ?>
                            </p>
                            <p class="description">
                                <strong><?php _e('Disable this setting if:', 'box-api-integration'); ?></strong>
                                <?php _e('You do not have Box AI enabled or you want to hide the chat functionality.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="box_chat_ai_button_color"><?php _e('AI Chat Button Color', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="color"
                                   id="box_chat_ai_button_color"
                                   name="box_chat_ai_button_color"
                                   value="<?php echo esc_attr($ai_button_color); ?>"
                                   class="box-color-picker" />
                            <input type="text"
                                   id="box_chat_ai_button_color_text"
                                   value="<?php echo esc_attr($ai_button_color); ?>"
                                   class="regular-text code box-color-text"
                                   placeholder="#0061D5"
                                   maxlength="7"
                                   pattern="^#[0-9A-Fa-f]{6}$" />
                            <p class="description">
                                <?php _e('Color of the "Chat with AI" button on document pages. Enter a hex color code (e.g., #0061D5) or use the color picker.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="box_chat_header_color"><?php _e('Chat Header Color', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="color"
                                   id="box_chat_header_color"
                                   name="box_chat_header_color"
                                   value="<?php echo esc_attr($header_color); ?>"
                                   class="box-color-picker" />
                            <input type="text"
                                   id="box_chat_header_color_text"
                                   value="<?php echo esc_attr($header_color); ?>"
                                   class="regular-text code box-color-text"
                                   placeholder="#0061D5"
                                   maxlength="7"
                                   pattern="^#[0-9A-Fa-f]{6}$" />
                            <p class="description">
                                <?php _e('Background color of the chat popup header. Enter a hex color code or use the color picker.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="box_chat_submit_color"><?php _e('Submit Button Color', 'box-api-integration'); ?></label>
                        </th>
                        <td>
                            <input type="color"
                                   id="box_chat_submit_color"
                                   name="box_chat_submit_color"
                                   value="<?php echo esc_attr($submit_color); ?>"
                                   class="box-color-picker" />
                            <input type="text"
                                   id="box_chat_submit_color_text"
                                   value="<?php echo esc_attr($submit_color); ?>"
                                   class="regular-text code box-color-text"
                                   placeholder="#0061D5"
                                   maxlength="7"
                                   pattern="^#[0-9A-Fa-f]{6}$" />
                            <p class="description">
                                <?php _e('Color of the send button and user message bubbles. Enter a hex color code or use the color picker.', 'box-api-integration'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <?php submit_button(__('Save Chat Colors', 'box-api-integration'), 'primary', 'submit', false); ?>
                    <button type="button" class="button" id="reset-chat-colors">
                        <?php _e('Reset to Default', 'box-api-integration'); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- Help Section -->
        <div class="box-section">
            <h2><?php _e('Setup Instructions', 'box-api-integration'); ?></h2>
            
            <ol>
                <li><?php _e('Create a Box App at', 'box-api-integration'); ?> <a href="https://app.box.com/developers/console" target="_blank">Box Developer Console</a></li>
                <li><?php _e('Choose "Custom App" and select "User Authentication (OAuth 2.0)"', 'box-api-integration'); ?></li>
                <li><?php _e('Copy the Client ID and Client Secret from your Box App', 'box-api-integration'); ?></li>
                <li><?php _e('Add the Redirect URI shown above to your Box App configuration', 'box-api-integration'); ?></li>
                <li><?php _e('Save your settings here and click "Authorize with Box"', 'box-api-integration'); ?></li>
                <li><?php _e('Grant permission when prompted by Box', 'box-api-integration'); ?></li>
            </ol>
            
            <h3><?php _e('Important Security Notes', 'box-api-integration'); ?></h3>
            <ul>
                <li><?php _e('Never commit credentials to version control', 'box-api-integration'); ?></li>
                <li><?php _e('Use environment variables for production deployments', 'box-api-integration'); ?></li>
                <li><?php _e('Regularly rotate your API credentials', 'box-api-integration'); ?></li>
                <li><?php _e('Limit access to admin users only', 'box-api-integration'); ?></li>
            </ul>
        </div>
        
        <!-- Developer Tools -->
        <div class="box-section">
            <h2><?php _e('Developer Tools', 'box-api-integration'); ?></h2>
            
            <p>
                <button type="button" class="button" id="clear-auth">
                    <?php _e('Clear Authentication', 'box-api-integration'); ?>
                </button>
                <button type="button" class="button" id="export-logs">
                    <?php _e('Export Logs', 'box-api-integration'); ?>
                </button>
                <button type="button" class="button" id="clear-logs">
                    <?php _e('Clear Logs', 'box-api-integration'); ?>
                </button>
            </p>
        </div>
        
    </div>
</div>

<!-- Create Folder Modal -->
<div id="create-folder-modal" class="box-modal" style="display: none;">
    <div class="box-modal-content">
        <h2><?php _e('Create New Folder', 'box-api-integration'); ?></h2>
        
        <p>
            <label for="new-folder-name"><?php _e('Folder Name:', 'box-api-integration'); ?></label>
            <input type="text" id="new-folder-name" class="regular-text" />
        </p>
        
        <p>
            <label for="parent-folder"><?php _e('Parent Folder:', 'box-api-integration'); ?></label>
            <?php Box_Admin::render_folder_select('0', 'parent_folder_id', 'parent-folder'); ?>
        </p>
        
        <p>
            <button type="button" class="button button-primary" id="create-folder-submit">
                <?php _e('Create', 'box-api-integration'); ?>
            </button>
            <button type="button" class="button" id="create-folder-cancel">
                <?php _e('Cancel', 'box-api-integration'); ?>
            </button>
        </p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Helper function to validate hex color
    function isValidHex(hex) {
        return /^#[0-9A-Fa-f]{6}$/i.test(hex);
    }

    // Color picker to text field synchronization
    $('.box-color-picker').on('input change', function() {
        var colorValue = $(this).val().toUpperCase();
        var textFieldId = $(this).attr('id') + '_text';
        $('#' + textFieldId).val(colorValue);
    });

    // Text field to color picker synchronization (two-way binding)
    $('.box-color-text').on('input', function() {
        var $textField = $(this);
        var colorValue = $textField.val().trim();

        // Auto-add # if missing
        if (colorValue && !colorValue.startsWith('#')) {
            colorValue = '#' + colorValue;
            $textField.val(colorValue);
        }

        // Convert to uppercase for consistency
        if (colorValue) {
            colorValue = colorValue.toUpperCase();
            $textField.val(colorValue);
        }

        // Update color picker if valid hex
        if (isValidHex(colorValue)) {
            var pickerFieldId = $textField.attr('id').replace('_text', '');
            $('#' + pickerFieldId).val(colorValue);
            $textField.css('border-color', ''); // Remove error styling
        } else if (colorValue.length >= 4) {
            // Show error styling for invalid hex codes
            $textField.css('border-color', '#dc3232');
        } else {
            $textField.css('border-color', ''); // Remove error styling while typing
        }
    });

    // Validate on blur
    $('.box-color-text').on('blur', function() {
        var $textField = $(this);
        var colorValue = $textField.val().trim();

        if (colorValue && !isValidHex(colorValue)) {
            alert('<?php _e('Please enter a valid hex color code (e.g., #0061D5)', 'box-api-integration'); ?>');
            $textField.focus().select();
        }
    });

    // Reset chat colors to default (Box blue)
    $('#reset-chat-colors').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to reset all chat colors to Box blue?', 'box-api-integration'); ?>')) {
            var defaultColor = '#0061D5';
            $('#box_chat_ai_button_color').val(defaultColor);
            $('#box_chat_ai_button_color_text').val(defaultColor).css('border-color', '');
            $('#box_chat_header_color').val(defaultColor);
            $('#box_chat_header_color_text').val(defaultColor).css('border-color', '');
            $('#box_chat_submit_color').val(defaultColor);
            $('#box_chat_submit_color_text').val(defaultColor).css('border-color', '');
        }
    });
});
</script>
