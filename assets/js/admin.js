/**
 * Box API Integration - Admin JavaScript
 */

jQuery(document).ready(function($) {

    // Global variables
    var currentFileId = null;
    var currentFolderId = '0';
    var selectedItems = [];
    var uploadQueue = [];

    // Toggle password visibility
    $('#toggle-secret').on('click', function() {
        var $input = $('#box_client_secret');
        var type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
        $(this).text(type === 'password' ? 'Show' : 'Hide');
    });

    // Validate token refresh interval
    $('#box_token_refresh_interval').on('change', function() {
        var value = parseInt($(this).val());

        if (isNaN(value) || value < 5 || value > 55) {
            showNotice('Token refresh interval must be between 5 and 55 minutes. Using default value of 50 minutes.', 'warning');
            $(this).val(50);
        }
    });

    // Add validation to form submission
    $('#box-settings-form').on('submit', function(e) {
        var keepAlive = $('#box_keep_alive').is(':checked');
        var refreshInterval = parseInt($('#box_token_refresh_interval').val());

        // Skip interval validation if keep-alive is enabled
        if (!keepAlive && (isNaN(refreshInterval) || refreshInterval < 5 || refreshInterval > 55)) {
            e.preventDefault();
            showNotice('Please enter a valid token refresh interval (5-55 minutes).', 'error');
            $('#box_token_refresh_interval').focus();
            return false;
        }
    });

    // Handle keep-alive checkbox toggle
    $('#box_keep_alive').on('change', function() {
        var isChecked = $(this).is(':checked');
        var $intervalInput = $('#box_token_refresh_interval');
        var $intervalRow = $('#manual-interval-row');

        if (isChecked) {
            // Disable manual interval when keep-alive is enabled
            $intervalInput.prop('disabled', true).css('opacity', '0.5');
            $intervalRow.find('.description').first().html(
                '<em>Keep Connection Alive is enabled. Tokens will refresh automatically every 45 minutes.</em>'
            );
        } else {
            // Enable manual interval when keep-alive is disabled
            $intervalInput.prop('disabled', false).css('opacity', '1');
            $intervalRow.find('.description').first().html(
                'How often to refresh the access token (Box tokens expire after 60 minutes). Recommended: 50 minutes. Valid range: 5-55 minutes.'
            );
        }
    });

    // Initialize keep-alive state on page load
    if ($('#box_keep_alive').is(':checked')) {
        $('#box_keep_alive').trigger('change');
    }

    // Copy redirect URI
    $('#copy-redirect-uri').on('click', function() {
        var $input = $('#box_redirect_uri');
        $input.select();
        document.execCommand('copy');
        
        var $button = $(this);
        var originalText = $button.text();
        $button.text('Copied!');
        setTimeout(function() {
            $button.text(originalText);
        }, 2000);
    });
    
    // Test connection
    $('#test-connection').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Testing...');
        
        $.post(box_api.ajax_url, {
            action: 'box_test_connection',
            nonce: box_api.nonce
        }, function(response) {
            if (response.success) {
                showNotice('Connection successful: ' + response.message, 'success');
                if (response.user) {
                    $('#connection-status').html('<p class="success">Connected as: ' + response.user.login + '</p>');
                }
            } else {
                showNotice('Connection failed: ' + response.message, 'error');
                if (response.needs_auth) {
                    $('#connection-status').html('<p class="error">Please authorize the application first</p>');
                }
            }
        }).fail(function() {
            showNotice('Connection test failed', 'error');
        }).always(function() {
            $button.prop('disabled', false).text('Test Connection');
        });
    });
    
    // Refresh token
    $('#refresh-token, #refresh-token').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Refreshing...');

        $.post(box_api.ajax_url, {
            action: 'box_refresh_token',
            nonce: box_api.nonce
        }, function(response) {
            if (response.success) {
                showNotice('Token refreshed successfully', 'success');
                location.reload();
            } else {
                showNotice('Failed to refresh token', 'error');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Refresh Token');
        });
    });

    // Logout from Box
    $('#box-logout').on('click', function() {
        if (!confirm('Are you sure you want to logout from Box? You will need to re-authenticate to use Box features again.')) {
            return;
        }

        var $button = $(this);
        var originalHtml = $button.html();
        $button.prop('disabled', true).html('Logging out...');

        $.post(box_api.ajax_url, {
            action: 'box_logout',
            nonce: box_api.nonce
        }, function(response) {
            if (response.success) {
                showNotice('Successfully logged out from Box', 'success');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                showNotice('Failed to logout: ' + (response.data || 'Unknown error'), 'error');
                $button.prop('disabled', false).html(originalHtml);
            }
        }).fail(function() {
            showNotice('Logout request failed', 'error');
            $button.prop('disabled', false).html(originalHtml);
        });
    });

    // File upload handling
    var $uploadArea = $('.drag-drop-area');
    var $fileInput = $('#box-file-input');
    
    // Drag and drop
    $uploadArea.on('dragenter dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('dragover');
    });
    
    $uploadArea.on('dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
    });
    
    $uploadArea.on('drop', function(e) {
        var files = e.originalEvent.dataTransfer.files;
        handleFileUpload(files);
    });
    
    // File input change
    $fileInput.on('change', function() {
        handleFileUpload(this.files);
    });
    
    // Handle file upload
    function handleFileUpload(files) {
        if (files.length === 0) return;
        
        var folderId = $('#upload-folder-id').val() || $('#upload-destination').val() || '0';
        
        $('.upload-progress').show();
        $('.upload-results').hide().find('.results-list').empty();
        
        var totalFiles = files.length;
        var uploadedFiles = 0;
        var results = [];
        
        // Upload files sequentially
        function uploadNextFile() {
            if (uploadedFiles >= totalFiles) {
                // All files uploaded
                showUploadResults(results);
                return;
            }
            
            var file = files[uploadedFiles];
            var formData = new FormData();
            
            formData.append('action', 'box_upload_file');
            formData.append('nonce', box_api.nonce);
            formData.append('folder_id', folderId);
            formData.append('file', file);
            
            // Update progress
            var progress = Math.round((uploadedFiles / totalFiles) * 100);
            $('.progress-fill').css('width', progress + '%');
            $('.progress-text').text('Uploading ' + file.name + ' (' + (uploadedFiles + 1) + '/' + totalFiles + ')');
            
            $.ajax({
                url: box_api.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        results.push({
                            name: file.name,
                            success: true,
                            message: response.message,
                            file: response.file
                        });
                    } else {
                        results.push({
                            name: file.name,
                            success: false,
                            message: response.message
                        });
                    }
                },
                error: function() {
                    results.push({
                        name: file.name,
                        success: false,
                        message: 'Upload failed'
                    });
                },
                complete: function() {
                    uploadedFiles++;
                    uploadNextFile();
                }
            });
        }
        
        uploadNextFile();
    }
    
    // Show upload results
    function showUploadResults(results) {
        $('.upload-progress').hide();
        $('.upload-results').show();
        
        var $list = $('.upload-results .results-list');
        
        results.forEach(function(result) {
            var className = result.success ? 'success' : 'error';
            var icon = result.success ? '✓' : '✗';
            
            $list.append(
                '<li class="' + className + '">' +
                icon + ' ' + result.name + ': ' + result.message +
                '</li>'
            );
        });
        
        // Refresh file list if in file manager
        if ($('#refresh-files').length) {
            $('#refresh-files').click();
        }
    }
    
    // Upload from URL
    $('#upload-from-url').on('click', function() {
        var urls = $('#upload-urls').val().split('\n').filter(function(url) {
            return url.trim() !== '';
        });
        
        if (urls.length === 0) {
            showNotice('Please enter at least one URL', 'error');
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Uploading...');
        
        var folderId = $('#upload-destination').val() || '0';
        var completed = 0;
        
        urls.forEach(function(url) {
            $.post(box_api.ajax_url, {
                action: 'box_upload_from_url',
                nonce: box_api.nonce,
                url: url.trim(),
                folder_id: folderId
            }, function(response) {
                completed++;
                
                if (response.success) {
                    showNotice('Uploaded: ' + response.file.name, 'success');
                } else {
                    showNotice('Failed to upload from: ' + url, 'error');
                }
                
                if (completed === urls.length) {
                    $button.prop('disabled', false).text('Upload from URLs');
                    $('#upload-urls').val('');
                }
            });
        });
    });
    
    // File Manager Functions
    
    // Refresh files
    $('#refresh-files').on('click', function() {
        loadFiles(currentFolderId);
    });
    
    // Navigate folders
    $(document).on('click', '.folder-link', function(e) {
        e.preventDefault();
        var folderId = $(this).data('folder-id');
        loadFiles(folderId);
    });
    
    // Load files
    function loadFiles(folderId) {
        var $browser = $('.box-file-browser');
        $browser.css('opacity', '0.5');
        
        $.post(box_api.ajax_url, {
            action: 'box_list_files',
            nonce: box_api.nonce,
            folder_id: folderId
        }, function(response) {
            if (response.success && response.data) {
                updateFileBrowser(response.data);
                currentFolderId = folderId;
                updateBreadcrumb(folderId);
            } else {
                showNotice('Failed to load files', 'error');
            }
        }).always(function() {
            $browser.css('opacity', '1');
        });
    }
    
    // Update file browser
    function updateFileBrowser(data) {
        var $tbody = $('.box-file-browser tbody');
        $tbody.empty();
        
        if (!data.entries || data.entries.length === 0) {
            $tbody.append(
                '<tr><td colspan="5" class="no-items">No files or folders found</td></tr>'
            );
            return;
        }
        
        data.entries.forEach(function(item) {
            var isFolder = item.type === 'folder';
            var icon = isFolder ? 'dashicons-category' : getFileIcon(item.name);
            var size = isFolder ? '-' : formatSize(item.size);
            
            var row = '<tr data-id="' + item.id + '" data-type="' + item.type + '">';
            row += '<th scope="row" class="check-column">';
            row += '<input type="checkbox" name="items[]" value="' + item.id + '">';
            row += '</th>';
            row += '<td class="name column-name">';
            row += '<span class="dashicons ' + icon + '"></span>';
            
            if (isFolder) {
                row += '<a href="#" class="folder-link" data-folder-id="' + item.id + '">';
                row += '<strong>' + item.name + '</strong>';
                row += '</a>';
            } else {
                row += '<strong>' + item.name + '</strong>';
            }
            
            row += '</td>';
            row += '<td class="size column-size">' + size + '</td>';
            row += '<td class="modified column-modified">' + formatDate(item.modified_at) + '</td>';
            row += '<td class="actions column-actions">';
            
            if (!isFolder) {
                row += '<a href="#" class="button button-small download-file" data-file-id="' + item.id + '">Download</a> ';
                row += '<a href="#" class="button button-small share-file" data-file-id="' + item.id + '">Share</a> ';
            }
            
            row += '<a href="#" class="button button-small delete-item" data-id="' + item.id + '" data-type="' + item.type + '">Delete</a>';
            row += '</td>';
            row += '</tr>';
            
            $tbody.append(row);
        });
    }
    
    // Download file
    $(document).on('click', '.download-file', function(e) {
        e.preventDefault();
        var fileId = $(this).data('file-id');
        
        // Get download URL
        $.post(box_api.ajax_url, {
            action: 'box_get_download_url',
            nonce: box_api.nonce,
            file_id: fileId
        }, function(response) {
            if (response.success && response.url) {
                window.open(response.url, '_blank');
            } else {
                showNotice('Failed to get download URL', 'error');
            }
        });
    });
    
    // Delete item
    $(document).on('click', '.delete-item', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var itemId = $button.data('id');
        var itemType = $button.data('type');
        
        if (!confirm(box_api.strings.confirm_delete)) {
            return;
        }
        
        $button.prop('disabled', true).text('Deleting...');
        
        var action = itemType === 'folder' ? 'box_delete_folder' : 'box_delete_file';
        
        $.post(box_api.ajax_url, {
            action: action,
            nonce: box_api.nonce,
            item_id: itemId
        }, function(response) {
            if (response.success) {
                $button.closest('tr').fadeOut(function() {
                    $(this).remove();
                });
                showNotice('Item deleted successfully', 'success');
            } else {
                showNotice('Failed to delete item', 'error');
                $button.prop('disabled', false).text('Delete');
            }
        });
    });
    
    // Create folder
    $('#create-folder, #new-folder, #create-folder-btn').on('click', function() {
        var folderName = $('#new-folder-name, #folder-name').val();
        var parentId = $('#parent-folder-id').val() || currentFolderId || '0';
        
        if (!folderName) {
            showNotice('Please enter a folder name', 'error');
            return;
        }
        
        $.post(box_api.ajax_url, {
            action: 'box_create_folder',
            nonce: box_api.nonce,
            name: folderName,
            parent_id: parentId
        }, function(response) {
            if (response.success) {
                showNotice('Folder created successfully', 'success');
                $('#new-folder-name, #folder-name').val('');
                $('.box-modal').hide();
                
                if ($('#refresh-files').length) {
                    $('#refresh-files').click();
                }
            } else {
                showNotice('Failed to create folder: ' + response.message, 'error');
            }
        });
    });
    
    // Share file
    $(document).on('click', '.share-file', function(e) {
        e.preventDefault();
        currentFileId = $(this).data('file-id');
        $('#share-modal').show();
    });
    
    // Create share link
    $('#create-share').on('click', function() {
        if (!currentFileId) return;
        
        var access = $('#share-access').val();
        var password = $('#share-password-enabled').is(':checked') ? $('#share-password').val() : null;
        
        $.post(box_api.ajax_url, {
            action: 'box_create_shared_link',
            nonce: box_api.nonce,
            file_id: currentFileId,
            access: access,
            password: password
        }, function(response) {
            if (response.success && response.shared_link) {
                $('#shared-link-url').val(response.shared_link);
                $('#share-link-result').show();
            } else {
                showNotice('Failed to create shared link', 'error');
            }
        });
    });
    
    // Copy share link
    $('#copy-share-link').on('click', function() {
        $('#shared-link-url').select();
        document.execCommand('copy');
        
        var $button = $(this);
        var originalText = $button.text();
        $button.text('Copied!');
        setTimeout(function() {
            $button.text(originalText);
        }, 2000);
    });
    
    // Add collaborator
    $('#add-collaborator').on('click', function() {
        var email = $('#collaborator-email').val();
        var role = $('#collaborator-role').val();
        
        if (!email) {
            showNotice('Please enter an email address', 'error');
            return;
        }
        
        $.post(box_api.ajax_url, {
            action: 'box_add_collaborator',
            nonce: box_api.nonce,
            folder_id: currentFolderId,
            email: email,
            role: role
        }, function(response) {
            if (response.success) {
                showNotice('Collaborator added successfully', 'success');
                $('#collaborator-email').val('');
            } else {
                showNotice('Failed to add collaborator: ' + response.message, 'error');
            }
        });
    });
    
    // Search files
    $('#box-search').on('keyup', debounce(function() {
        var query = $(this).val();
        
        if (query.length < 2) {
            loadFiles(currentFolderId);
            return;
        }
        
        $.post(box_api.ajax_url, {
            action: 'box_search',
            nonce: box_api.nonce,
            query: query
        }, function(response) {
            if (response.success && response.data) {
                updateFileBrowser(response.data);
            }
        });
    }, 500));
    
    // Modal handling
    $('.box-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    $('.modal-close').on('click', function() {
        $(this).closest('.box-modal').hide();
    });
    
    $('#upload-files').on('click', function() {
        $('#upload-modal').show();
    });
    
    $('#new-folder').on('click', function() {
        $('#new-folder-modal').show();
    });
    
    // Password field toggle
    $('#share-password-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#share-password').show();
        } else {
            $('#share-password').hide();
        }
    });
    
    // Checkbox handling
    $('.check-all').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('.box-file-browser tbody input[type="checkbox"]').prop('checked', isChecked);
        updateBulkActions();
    });
    
    $(document).on('change', '.box-file-browser tbody input[type="checkbox"]', function() {
        updateBulkActions();
    });
    
    function updateBulkActions() {
        var checkedCount = $('.box-file-browser tbody input[type="checkbox"]:checked').length;
        
        if (checkedCount > 0) {
            $('.box-bulk-actions').show();
        } else {
            $('.box-bulk-actions').hide();
        }
    }
    
    // Clear auth data
    $('#clear-auth').on('click', function() {
        if (!confirm('Are you sure you want to clear all authentication data?')) {
            return;
        }
        
        $.post(box_api.ajax_url, {
            action: 'box_clear_auth',
            nonce: box_api.nonce
        }, function(response) {
            showNotice('Authentication data cleared', 'success');
            location.reload();
        });
    });
    
    // Clear logs
    $('#clear-logs').on('click', function() {
        if (!confirm('Are you sure you want to clear all logs?')) {
            return;
        }
        
        $.post(box_api.ajax_url, {
            action: 'box_clear_logs',
            nonce: box_api.nonce
        }, function(response) {
            showNotice('Logs cleared', 'success');
            location.reload();
        });
    });
    
    // Export logs
    $('#export-logs').on('click', function() {
        window.location.href = box_api.ajax_url + '?action=box_export_logs&nonce=' + box_api.nonce;
    });
    
    // Utility functions
    
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap > h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    function getFileIcon(filename) {
        var ext = filename.split('.').pop().toLowerCase();
        
        var icons = {
            'doc': 'dashicons-media-document',
            'docx': 'dashicons-media-document',
            'pdf': 'dashicons-pdf',
            'xls': 'dashicons-media-spreadsheet',
            'xlsx': 'dashicons-media-spreadsheet',
            'ppt': 'dashicons-media-interactive',
            'pptx': 'dashicons-media-interactive',
            'jpg': 'dashicons-format-image',
            'jpeg': 'dashicons-format-image',
            'png': 'dashicons-format-image',
            'gif': 'dashicons-format-image',
            'mp4': 'dashicons-format-video',
            'mp3': 'dashicons-format-audio',
            'zip': 'dashicons-media-archive'
        };
        
        return icons[ext] || 'dashicons-media-default';
    }
    
    function formatSize(bytes) {
        if (bytes === 0) return '0 B';
        
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function formatDate(dateString) {
        var date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }
    
    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }
    
    function updateBreadcrumb(folderId) {
        // Update URL
        var url = new URL(window.location);
        url.searchParams.set('folder', folderId);
        window.history.pushState({}, '', url);
    }
    
    // Initialize on page load
    if ($('.box-file-browser').length) {
        var urlParams = new URLSearchParams(window.location.search);
        currentFolderId = urlParams.get('folder') || '0';
    }
    
});
