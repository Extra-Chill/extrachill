/**
 * Share Button Component
 *
 * Handles toggle, click-outside-close, and clipboard copy for share buttons.
 * Supports multiple share button instances on a single page via event delegation.
 *
 * @package ExtraChill
 * @since 1.0.0
 */
(function() {
    'use strict';

    document.addEventListener('click', function(event) {
        var button = event.target.closest('.share-button');
        var container = event.target.closest('.share-button-container');

        if (button && container) {
            var options = container.querySelector('.share-options');
            if (options) {
                options.style.display = options.style.display === 'block' ? 'none' : 'block';
            }
            return;
        }

        document.querySelectorAll('.share-options').forEach(function(options) {
            options.style.display = 'none';
        });
    });

    document.addEventListener('click', function(event) {
        var copyLink = event.target.closest('.copy-link a');
        if (!copyLink) {
            return;
        }

        event.preventDefault();
        var url = copyLink.getAttribute('data-share-url');
        if (!url) {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url)
                .then(function() {
                    var originalText = copyLink.textContent;
                    copyLink.textContent = 'Copied!';
                    setTimeout(function() {
                        copyLink.textContent = originalText;
                    }, 2000);
                })
                .catch(function() {
                    window.prompt('Copy this link:', url);
                });
        } else {
            window.prompt('Copy this link:', url);
        }
    });
})();
