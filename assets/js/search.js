/**
 * Box Search - Frontend JavaScript
 */

jQuery(document).ready(function($) {

    // Handle search form submission
    $(document).on('click', '.box-search-button', function(e) {
        e.preventDefault();

        var $container = $(this).closest('.box-search-container');
        var $input = $container.find('.box-search-input');
        var query = $input.val().trim();

        if (query.length < 2) {
            showError($container, 'Please enter at least 2 characters to search');
            return;
        }

        performSearch($container, query);
    });

    // Handle Enter key in search input
    $(document).on('keypress', '.box-search-input', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).closest('.box-search-container').find('.box-search-button').click();
        }
    });

    // Handle clear button
    $(document).on('click', '.box-search-clear', function(e) {
        e.preventDefault();

        var $container = $(this).closest('.box-search-container');
        $container.find('.box-search-input').val('');
        $container.find('.box-search-results').hide();
        $container.find('.box-search-error').hide();
    });

    /**
     * Perform search
     */
    function performSearch($container, query) {
        var boxId = $container.data('box-id');
        var folderId = $container.data('folder-id');

        // Show loading
        $container.find('.box-search-results').hide();
        $container.find('.box-search-error').hide();
        $container.find('.box-search-loading').show();

        // Send AJAX request
        $.ajax({
            url: boxSearch.ajaxUrl,
            type: 'POST',
            data: {
                action: 'box_search_files',
                nonce: boxSearch.nonce,
                query: query,
                box_id: boxId,
                folder_id: folderId
            },
            success: function(response) {
                $container.find('.box-search-loading').hide();

                if (response.success) {
                    displayResults($container, response.data.results, response.data.total_count, query);
                } else {
                    showError($container, response.data || 'Search failed');
                }
            },
            error: function() {
                $container.find('.box-search-loading').hide();
                showError($container, 'Search request failed. Please try again.');
            }
        });
    }

    /**
     * Display search results
     */
    function displayResults($container, results, totalCount, query) {
        var $resultsList = $container.find('.box-search-results-list');
        var $resultsCount = $container.find('.box-search-results-count');

        $resultsList.empty();

        if (results.length === 0) {
            $resultsList.html('<div class="box-search-no-results">No files found for "' + escapeHtml(query) + '"</div>');
        } else {
            $resultsCount.text('Found ' + totalCount + ' file' + (totalCount !== 1 ? 's' : '') + ' for "' + escapeHtml(query) + '"');

            results.forEach(function(file) {
                var $item = $('<div class="box-search-result-item"></div>');

                var icon = getFileIcon(file.extension);
                var size = formatFileSize(file.size);
                var date = formatDate(file.modified_at);
                var documentUrl = file.url || (boxSearch.siteUrl + '/box-document/' + file.id + '/');

                $item.html(
                    '<div class="box-search-result-icon">' +
                        '<span class="dashicons ' + icon + '"></span>' +
                    '</div>' +
                    '<div class="box-search-result-info">' +
                        '<div class="box-search-result-name">' + escapeHtml(file.name) + '</div>' +
                        '<div class="box-search-result-meta">' +
                            '<span class="box-search-result-size">' + size + '</span>' +
                            '<span class="box-search-result-date">Modified ' + date + '</span>' +
                            '<span class="box-search-result-folder">in ' + escapeHtml(file.parent) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="box-search-result-actions">' +
                        '<a href="' + documentUrl + '" class="box-search-view-file" target="_blank" rel="noopener noreferrer">View</a>' +
                    '</div>'
                );

                $resultsList.append($item);
            });
        }

        $container.find('.box-search-results').show();
    }

    /**
     * Show error message
     */
    function showError($container, message) {
        var $error = $container.find('.box-search-error');
        $error.text(message).show();

        setTimeout(function() {
            $error.fadeOut();
        }, 5000);
    }

    /**
     * Get file icon based on extension
     */
    function getFileIcon(extension) {
        var icons = {
            'pdf': 'dashicons-pdf',
            'doc': 'dashicons-media-document',
            'docx': 'dashicons-media-document',
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
            'zip': 'dashicons-media-archive',
            'txt': 'dashicons-media-text'
        };

        return icons[extension.toLowerCase()] || 'dashicons-media-default';
    }

    /**
     * Format file size
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';

        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    /**
     * Format date
     */
    function formatDate(dateString) {
        var date = new Date(dateString);
        var now = new Date();
        var diff = now - date;

        // Less than a minute
        if (diff < 60000) {
            return 'just now';
        }

        // Less than an hour
        if (diff < 3600000) {
            var minutes = Math.floor(diff / 60000);
            return minutes + ' minute' + (minutes !== 1 ? 's' : '') + ' ago';
        }

        // Less than a day
        if (diff < 86400000) {
            var hours = Math.floor(diff / 3600000);
            return hours + ' hour' + (hours !== 1 ? 's' : '') + ' ago';
        }

        // Less than a week
        if (diff < 604800000) {
            var days = Math.floor(diff / 86400000);
            return days + ' day' + (days !== 1 ? 's' : '') + ' ago';
        }

        // Default format
        return date.toLocaleDateString();
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

});
