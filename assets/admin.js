/**
 * Admin JavaScript for Vernal Contentum
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Copy connection data button
        $('#vernal-copy-btn').on('click', function() {
            var $textarea = $('#vernal-connection-data');
            $textarea.select();
            
            try {
                document.execCommand('copy');
                $('#vernal-copy-success').fadeIn().delay(2000).fadeOut();
                
                // Visual feedback
                $(this).text('Copied!').addClass('button-secondary');
                setTimeout(function() {
                    $('#vernal-copy-btn').text('Copy Connection Data').removeClass('button-secondary');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                alert('Failed to copy. Please select and copy manually.');
            }
        });
        
        // Auto-select textarea on click
        $('#vernal-connection-data').on('click', function() {
            $(this).select();
        });
        
        // Test backend connection button
        $('#test-backend-connection').on('click', function() {
            var $button = $(this);
            var $status = $('#backend-connection-status');
            var originalText = $button.text();
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Testing...');
            $status.html('<span style="color: #2271b1;">⏳ Testing connection...</span>');
            
            // Make AJAX request
            $.ajax({
                url: vernalContentum.ajax_url,
                type: 'POST',
                data: {
                    action: 'vernal_test_backend_connection',
                    nonce: vernalContentum.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                        if (response.data.data && response.data.data.user) {
                            $status.append('<br><small>User: ' + response.data.data.user.username + ' (' + response.data.data.user.email + ')</small>');
                        }
                    } else {
                        $status.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Connection failed') + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('<span style="color: #dc3232;">✗ Connection error: ' + error + '</span>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    
})(jQuery);

