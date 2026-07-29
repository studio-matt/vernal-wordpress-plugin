/**
 * Admin JavaScript for Vernal Contentum
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Copy site URL / inbound API key fields
        $('.vernal-copy-field').on('click', function() {
            var targetId = $(this).data('target');
            var $input = $('#' + targetId);
            if (!$input.length) {
                return;
            }
            $input.trigger('select');
            try {
                document.execCommand('copy');
                var $btn = $(this);
                var original = $btn.text();
                $btn.text('Copied!');
                setTimeout(function() {
                    $btn.text(original);
                }, 2000);
            } catch (err) {
                alert('Failed to copy. Please select and copy manually.');
            }
        });
        
        // Test backend connection button (admin + nonce required server-side)
        $('#test-backend-connection').on('click', function() {
            var $button = $(this);
            var $status = $('#backend-connection-status');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text('Testing...');
            $status.html('<span style="color: #2271b1;">Testing connection…</span>');
            
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
                        $('#vernal-outbound-status-label').text('Connected').css('color', '#46b450');
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Connection failed';
                        $status.html('<span style="color: #dc3232;">✗ ' + msg + '</span>');
                        $('#vernal-outbound-status-label').text('Error').css('color', '#dc3232');
                    }
                },
                error: function() {
                    $status.html('<span style="color: #dc3232;">✗ Connection error</span>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    
})(jQuery);
