/**
 * Box AI Chat - Frontend JavaScript
 */

(function($) {
    'use strict';

    console.log('Box AI Chat script loaded');

    // Wait for DOM to be ready
    $(document).ready(function() {
        console.log('DOM ready, initializing Box AI Chat...');

        var $modal = $('#box-ai-chat-modal');
        var $chatButton = $('#box-ai-chat-button');
        var $closeButton = $('#box-ai-chat-close');
        var $overlay = $('.box-ai-chat-overlay');
        var $messagesContainer = $('#box-ai-chat-messages');
        var $inputField = $('#box-ai-chat-input');
        var $sendButton = $('#box-ai-chat-send');

        console.log('Elements found:');
        console.log('- Modal:', $modal.length);
        console.log('- Chat button:', $chatButton.length);
        console.log('- Messages container:', $messagesContainer.length);

        if ($chatButton.length === 0) {
            console.error('Chat button not found! Make sure you are on a document viewer page.');
            return;
        }

        var fileId = $chatButton.data('file-id');
        console.log('File ID from button:', fileId);

        if (!fileId) {
            console.error('No file ID found on chat button!');
            return;
        }

        var isProcessing = false;
        var summaryLoaded = false;

    console.log('Box AI Chat initialized successfully');

    // Open modal
    $chatButton.on('click', function(e) {
        e.preventDefault();
        console.log('Chat button clicked. Summary loaded:', summaryLoaded);

        // Show modal with animation
        $modal.fadeIn(300, function() {
            console.log('Modal is now visible');

            // Auto-generate summary on first open
            if (!summaryLoaded) {
                console.log('Starting auto-summary generation...');
                // Small delay to ensure modal is fully rendered
                setTimeout(function() {
                    generateSummary();
                }, 100);
            } else {
                console.log('Summary already loaded, focusing input');
                $inputField.focus();
            }
        });
    });

    // Close modal
    $closeButton.on('click', closeModal);
    $overlay.on('click', closeModal);

    function closeModal() {
        $modal.fadeOut(300);
    }

    // Close on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.is(':visible')) {
            closeModal();
        }
    });

    // Auto-resize textarea and toggle button state (Claude-style)
    $inputField.on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';

        // Toggle button appearance based on text presence
        if ($(this).val().trim()) {
            $sendButton.addClass('has-text');
        } else {
            $sendButton.removeClass('has-text');
        }
    });

    // Send message on Enter (Shift+Enter for new line)
    $inputField.on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Send message on button click
    $sendButton.on('click', sendMessage);

    function sendMessage() {
        var message = $inputField.val().trim();

        if (!message || isProcessing) {
            return;
        }

        // Remove welcome message if it exists
        $('.box-ai-welcome-message').fadeOut(300, function() {
            $(this).remove();
        });

        // Add user message
        addMessage(message, 'user');

        // Clear input and reset button state
        $inputField.val('').css('height', 'auto');
        $sendButton.removeClass('has-text');

        // Show loading
        var $loadingMessage = $('<div class="box-ai-message box-ai-message-ai">')
            .append('<div class="box-ai-message-content box-ai-message-loading"><span class="spinner"></span>Thinking...</div>');
        $messagesContainer.append($loadingMessage);
        scrollToBottom();

        // Disable sending
        isProcessing = true;
        $sendButton.prop('disabled', true);
        $inputField.prop('disabled', true);

        // Send AJAX request
        $.ajax({
            url: boxAiChat.ajaxUrl,
            type: 'POST',
            data: {
                action: 'box_ai_chat',
                nonce: boxAiChat.nonce,
                file_id: fileId,
                message: message
            },
            success: function(response) {
                $loadingMessage.remove();

                if (response.success) {
                    addMessage(response.data.answer, 'ai');
                } else {
                    addMessage('Sorry, I encountered an error: ' + (response.data || 'Unknown error'), 'ai', true);
                }
            },
            error: function() {
                $loadingMessage.remove();
                addMessage('Sorry, I encountered a connection error. Please try again.', 'ai', true);
            },
            complete: function() {
                isProcessing = false;
                $sendButton.prop('disabled', false);
                $inputField.prop('disabled', false).focus();
            }
        });
    }

    function generateSummary() {
        console.log('generateSummary() called');

        // Remove welcome message immediately
        var $welcomeMsg = $('.box-ai-welcome-message');
        console.log('Welcome messages found:', $welcomeMsg.length);
        $welcomeMsg.remove();

        // Show loading message
        var $loadingMessage = $('<div class="box-ai-message box-ai-message-ai">')
            .append('<div class="box-ai-message-content box-ai-message-loading"><span class="spinner"></span>Generating document summary...</div>');
        $messagesContainer.append($loadingMessage);
        console.log('Loading message added to chat');
        scrollToBottom();

        // Disable input while loading
        isProcessing = true;
        $sendButton.prop('disabled', true);
        $inputField.prop('disabled', true);

        console.log('Sending AJAX request for summary...');
        console.log('File ID:', fileId);
        console.log('AJAX URL:', boxAiChat.ajaxUrl);

        // Send AJAX request for summary
        $.ajax({
            url: boxAiChat.ajaxUrl,
            type: 'POST',
            data: {
                action: 'box_ai_chat',
                nonce: boxAiChat.nonce,
                file_id: fileId,
                message: 'Please provide a comprehensive summary of this document, including the main topics, key points, and any important conclusions.'
            },
            success: function(response) {
                console.log('Summary AJAX response received:', response);
                $loadingMessage.remove();

                if (response.success) {
                    console.log('Summary generated successfully');
                    addMessage(response.data.answer, 'ai');
                    summaryLoaded = true;
                } else {
                    console.error('Summary generation failed:', response.data);
                    addMessage('Unable to generate summary: ' + (response.data || 'Unknown error'), 'ai', true);
                }
            },
            error: function(xhr, status, error) {
                console.error('Summary AJAX error:', status, error);
                console.error('Response:', xhr.responseText);
                $loadingMessage.remove();
                addMessage('Failed to generate summary. Please try again.', 'ai', true);
            },
            complete: function() {
                console.log('Summary request completed');
                isProcessing = false;
                $sendButton.prop('disabled', false);
                $inputField.prop('disabled', false).focus();
            }
        });
    }

    function addMessage(text, type, isError) {
        console.log('addMessage called:', {type: type, isError: isError, textLength: text.length});

        var messageClass = type === 'user' ? 'box-ai-message-user' : 'box-ai-message-ai';
        var $message = $('<div class="box-ai-message ' + messageClass + '">');
        var $content = $('<div class="box-ai-message-content">');

        // Convert newlines to <br> tags for error messages (which may have instructions)
        if (isError && text.indexOf('\n') !== -1) {
            // Escape HTML but preserve line breaks
            var escapedText = escapeHtml(text);
            var htmlText = escapedText.replace(/\n/g, '<br>');
            $content.html(htmlText);
            $content.css({
                'color': '#d32f2f',
                'white-space': 'normal',
                'max-width': '90%'
            });
        } else if (type === 'ai' && !isError) {
            // Format AI messages with markdown-like formatting
            console.log('Formatting AI response...');
            console.log('Original text:', text.substring(0, 200));
            var formattedText = formatAIResponse(text);
            console.log('Formatted text:', formattedText.substring(0, 200));
            $content.html(formattedText);
        } else {
            $content.text(text);
            if (isError) {
                $content.css('color', '#d32f2f');
            }
        }

        $message.append($content);
        $messagesContainer.append($message);
        console.log('Message added to container');
        scrollToBottom();
    }

    function formatAIResponse(text) {
        console.log('formatAIResponse called with text length:', text.length);

        // Escape HTML first to prevent XSS
        var formatted = escapeHtml(text);

        // Process in specific order to avoid conflicts

        // 1. Convert markdown headings (must be at start of line)
        formatted = formatted.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
        formatted = formatted.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        formatted = formatted.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        formatted = formatted.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // 2. Convert **bold** to <strong> (use placeholder to avoid conflicts)
        formatted = formatted.replace(/\*\*([^*]+?)\*\*/g, '___BOLD_START___$1___BOLD_END___');

        // 3. Convert *italic* to <em> (single asterisks only)
        formatted = formatted.replace(/\*([^*]+?)\*/g, '<em>$1</em>');

        // 4. Replace bold placeholders with actual tags
        formatted = formatted.replace(/___BOLD_START___/g, '<strong>');
        formatted = formatted.replace(/___BOLD_END___/g, '</strong>');

        // 5. Convert numbered lists (1. Item, 2. Item)
        formatted = formatted.replace(/^(\d+)\.\s+(.+)$/gm, '___LISTITEM_NUM___$2___LISTITEM_END___');

        // 6. Convert bullet points (- Item or • Item)
        formatted = formatted.replace(/^[-•]\s+(.+)$/gm, '___LISTITEM_BULLET___$1___LISTITEM_END___');

        // 7. Wrap numbered list items
        var lines = formatted.split('\n');
        var result = [];
        var inNumList = false;
        var inBulletList = false;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];

            if (line.indexOf('___LISTITEM_NUM___') !== -1) {
                if (!inNumList) {
                    result.push('<ol>');
                    inNumList = true;
                    inBulletList = false;
                }
                line = line.replace('___LISTITEM_NUM___', '<li>').replace('___LISTITEM_END___', '</li>');
                result.push(line);
            } else if (line.indexOf('___LISTITEM_BULLET___') !== -1) {
                if (!inBulletList) {
                    if (inNumList) {
                        result.push('</ol>');
                        inNumList = false;
                    }
                    result.push('<ul>');
                    inBulletList = true;
                }
                line = line.replace('___LISTITEM_BULLET___', '<li>').replace('___LISTITEM_END___', '</li>');
                result.push(line);
            } else {
                if (inNumList) {
                    result.push('</ol>');
                    inNumList = false;
                }
                if (inBulletList) {
                    result.push('</ul>');
                    inBulletList = false;
                }
                result.push(line);
            }
        }

        // Close any open lists
        if (inNumList) result.push('</ol>');
        if (inBulletList) result.push('</ul>');

        formatted = result.join('\n');

        // 8. Convert code blocks with triple backticks
        formatted = formatted.replace(/```([^`]+?)```/gs, '<pre><code>$1</code></pre>');

        // 9. Convert inline code with single backticks
        formatted = formatted.replace(/`([^`]+?)`/g, '<code>$1</code>');

        // 10. Convert links [text](url)
        formatted = formatted.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

        // 11. Handle paragraphs - split by double line breaks
        var sections = formatted.split(/\n\n+/);
        formatted = sections.map(function(section) {
            section = section.trim();

            // Don't wrap if already has block-level HTML tags
            if (section.match(/^<(h[1-4]|ul|ol|pre|div)/i)) {
                return section;
            }

            // Don't wrap if empty
            if (!section) {
                return '';
            }

            // Wrap in paragraph
            return '<p>' + section + '</p>';
        }).filter(function(s) { return s; }).join('\n');

        // 12. Convert remaining single line breaks to <br>
        formatted = formatted.replace(/\n/g, '<br>');

        console.log('formatAIResponse complete');
        return formatted;
    }

    function scrollToBottom() {
        $messagesContainer.animate({
            scrollTop: $messagesContainer[0].scrollHeight
        }, 300);
    }

    // Escape HTML to prevent XSS
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

    }); // End document.ready
})(jQuery); // End IIFE
