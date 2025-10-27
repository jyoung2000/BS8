<?php
/**
 * Search Boxes Admin Page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get all search boxes
$search_boxes = Box_Search::get_search_boxes();

// Check for edit mode
$edit_id = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : '';
$edit_box = $edit_id ? Box_Search::get_search_box($edit_id) : null;
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php echo $edit_box ? __('Edit Search Box', 'box-api-integration') : __('Search Boxes', 'box-api-integration'); ?>
    </h1>

    <?php if (!$edit_box) : ?>
        <a href="<?php echo admin_url('admin.php?page=box-search-boxes&action=new'); ?>" class="page-title-action">
            <?php _e('Add New', 'box-api-integration'); ?>
        </a>
    <?php else : ?>
        <a href="<?php echo admin_url('admin.php?page=box-search-boxes'); ?>" class="page-title-action">
            <?php _e('Back to List', 'box-api-integration'); ?>
        </a>
    <?php endif; ?>

    <hr class="wp-header-end">

    <div class="box-admin-container">

        <?php if (isset($_GET['action']) && $_GET['action'] === 'new' || $edit_box) : ?>
            <!-- Add/Edit Search Box Form -->
            <div class="box-section">
                <h2><?php echo $edit_box ? __('Edit Search Box', 'box-api-integration') : __('Create New Search Box', 'box-api-integration'); ?></h2>

                <form id="box-search-box-form" class="box-search-box-form">
                    <input type="hidden" name="id" value="<?php echo $edit_box ? esc_attr($edit_box['id']) : ''; ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="search_box_name"><?php _e('Name', 'box-api-integration'); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="search_box_name"
                                       name="name"
                                       class="regular-text"
                                       value="<?php echo $edit_box ? esc_attr($edit_box['name']) : ''; ?>"
                                       required>
                                <p class="description">
                                    <?php _e('A descriptive name for this search box (internal use only)', 'box-api-integration'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="search_box_shortcode"><?php _e('Shortcode Name', 'box-api-integration'); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="search_box_shortcode"
                                       name="shortcode"
                                       class="regular-text"
                                       value="<?php echo $edit_box ? esc_attr($edit_box['shortcode']) : ''; ?>"
                                       placeholder="box_search_products"
                                       required>
                                <p class="description">
                                    <?php _e('The shortcode name to use (e.g., "box_search_products"). Use: ', 'box-api-integration'); ?>
                                    <code>[<?php echo $edit_box ? esc_html($edit_box['shortcode']) : 'your_shortcode_name'; ?>]</code>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="search_box_folder"><?php _e('Search Folder', 'box-api-integration'); ?></label>
                            </th>
                            <td>
                                <?php
                                Box_Admin::render_folder_select(
                                    $edit_box ? $edit_box['folder_id'] : '0',
                                    'folder_id',
                                    'search_box_folder'
                                );
                                ?>
                                <p class="description">
                                    <?php _e('Select a specific folder to search within, or choose Root Folder to search all files', 'box-api-integration'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="search_box_placeholder"><?php _e('Placeholder Text', 'box-api-integration'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="search_box_placeholder"
                                       name="placeholder"
                                       class="regular-text"
                                       value="<?php echo $edit_box ? esc_attr($edit_box['placeholder']) : 'Search files...'; ?>">
                                <p class="description">
                                    <?php _e('The placeholder text shown in the search input field', 'box-api-integration'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="search_box_results_per_page"><?php _e('Results Per Page', 'box-api-integration'); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="search_box_results_per_page"
                                       name="results_per_page"
                                       class="small-text"
                                       value="<?php echo $edit_box ? esc_attr($edit_box['results_per_page']) : '10'; ?>"
                                       min="1"
                                       max="100">
                                <p class="description">
                                    <?php _e('Number of results to display (1-100)', 'box-api-integration'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="save-search-box">
                            <?php echo $edit_box ? __('Update Search Box', 'box-api-integration') : __('Create Search Box', 'box-api-integration'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=box-search-boxes'); ?>" class="button">
                            <?php _e('Cancel', 'box-api-integration'); ?>
                        </a>
                    </p>
                </form>
            </div>

        <?php else : ?>
            <!-- List Search Boxes -->
            <div class="box-section">
                <h2><?php _e('Your Search Boxes', 'box-api-integration'); ?></h2>

                <?php if (empty($search_boxes)) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php _e('No search boxes created yet. Click "Add New" to create your first search box.', 'box-api-integration'); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Name', 'box-api-integration'); ?></th>
                                <th><?php _e('Shortcode', 'box-api-integration'); ?></th>
                                <th><?php _e('Folder', 'box-api-integration'); ?></th>
                                <th><?php _e('Results', 'box-api-integration'); ?></th>
                                <th><?php _e('Actions', 'box-api-integration'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_boxes as $box) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($box['name']); ?></strong>
                                    </td>
                                    <td>
                                        <code>[<?php echo esc_html($box['shortcode']); ?>]</code>
                                        <button type="button" class="button button-small copy-shortcode" data-shortcode="[<?php echo esc_attr($box['shortcode']); ?>]">
                                            <?php _e('Copy', 'box-api-integration'); ?>
                                        </button>
                                    </td>
                                    <td>
                                        <?php echo $box['folder_id'] === '0' ? __('Root Folder (All Files)', 'box-api-integration') : esc_html($box['folder_id']); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($box['results_per_page']); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=box-search-boxes&edit=' . urlencode($box['id'])); ?>" class="button button-small">
                                            <?php _e('Edit', 'box-api-integration'); ?>
                                        </a>
                                        <button type="button" class="button button-small delete-search-box" data-id="<?php echo esc_attr($box['id']); ?>" data-name="<?php echo esc_attr($box['name']); ?>">
                                            <?php _e('Delete', 'box-api-integration'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Usage Instructions -->
            <div class="box-section">
                <h2><?php _e('How to Use', 'box-api-integration'); ?></h2>
                <div class="notice notice-info inline">
                    <p>
                        <strong><?php _e('Using Search Boxes:', 'box-api-integration'); ?></strong>
                    </p>
                    <ol>
                        <li><?php _e('Create a search box above and give it a unique shortcode name', 'box-api-integration'); ?></li>
                        <li><?php _e('Choose which folder to search in (or select Root to search all files)', 'box-api-integration'); ?></li>
                        <li><?php _e('Copy the shortcode and paste it into any WordPress page, post, or widget', 'box-api-integration'); ?></li>
                        <li><?php _e('Visitors can then search for files in that specific folder', 'box-api-integration'); ?></li>
                    </ol>
                    <p>
                        <strong><?php _e('Examples:', 'box-api-integration'); ?></strong><br>
                        <code>[box_search_products]</code> - <?php _e('Search products folder', 'box-api-integration'); ?><br>
                        <code>[box_search_documents]</code> - <?php _e('Search documents folder', 'box-api-integration'); ?><br>
                        <code>[box_search_all]</code> - <?php _e('Search all files', 'box-api-integration'); ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Save search box
    $('#box-search-box-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $('#save-search-box');
        var originalText = $button.text();

        $button.prop('disabled', true).text('Saving...');

        $.post(box_api.ajax_url, {
            action: 'box_save_search_box',
            nonce: box_api.nonce,
            id: $form.find('[name="id"]').val(),
            name: $form.find('[name="name"]').val(),
            shortcode: $form.find('[name="shortcode"]').val(),
            folder_id: $form.find('[name="folder_id"]').val(),
            placeholder: $form.find('[name="placeholder"]').val(),
            results_per_page: $form.find('[name="results_per_page"]').val()
        }, function(response) {
            if (response.success) {
                alert('Search box saved successfully!');
                window.location.href = '<?php echo admin_url('admin.php?page=box-search-boxes'); ?>';
            } else {
                alert('Error: ' + (response.data || 'Failed to save search box'));
                $button.prop('disabled', false).text(originalText);
            }
        }).fail(function() {
            alert('Failed to save search box');
            $button.prop('disabled', false).text(originalText);
        });
    });

    // Delete search box
    $('.delete-search-box').on('click', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');

        if (!confirm('Are you sure you want to delete "' + name + '"?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('Deleting...');

        $.post(box_api.ajax_url, {
            action: 'box_delete_search_box',
            nonce: box_api.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                $button.closest('tr').fadeOut(function() {
                    $(this).remove();
                });
            } else {
                alert('Error: ' + (response.data || 'Failed to delete search box'));
                $button.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            alert('Failed to delete search box');
            $button.prop('disabled', false).text('Delete');
        });
    });

    // Copy shortcode
    $('.copy-shortcode').on('click', function() {
        var shortcode = $(this).data('shortcode');
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(shortcode).select();
        document.execCommand('copy');
        $temp.remove();

        var $button = $(this);
        var originalText = $button.text();
        $button.text('Copied!');
        setTimeout(function() {
            $button.text(originalText);
        }, 2000);
    });
});
</script>
