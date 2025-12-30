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
    });
    
})(jQuery);

