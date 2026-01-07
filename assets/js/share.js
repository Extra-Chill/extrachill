/**
 * Share Button - Copy Link Handler
 *
 * Handles clipboard copy for share button copy-link functionality.
 * Dropdown behavior provided by mini-dropdown.js.
 * Tracks share clicks via analytics endpoint.
 *
 * @package ExtraChill
 */
(function() {
    'use strict';

    /**
     * Track share click via analytics endpoint.
     *
     * @param {string} destination - Share destination (facebook, twitter, etc.)
     * @param {string} shareUrl - URL being shared
     */
    function ecTrackShare(destination, shareUrl) {
        var endpoint = '/wp-json/extrachill/v1/analytics/click';
        var data = {
            click_type: 'share',
            share_destination: destination,
            source_url: window.location.href,
            destination_url: shareUrl || window.location.href
        };

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([JSON.stringify(data)], { type: 'application/json' }));
        } else {
            fetch(endpoint, {
                method: 'POST',
                body: JSON.stringify(data),
                headers: { 'Content-Type': 'application/json' },
                keepalive: true
            }).catch(function() {});
        }
    }

    function ecCopyToClipboard(text, linkEl, promptLabel) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text)
                .then(function() {
                    var originalText = linkEl.textContent;
                    linkEl.textContent = 'Copied!';
                    setTimeout(function() {
                        linkEl.textContent = originalText;
                    }, 2000);
                })
                .catch(function() {
                    window.prompt(promptLabel, text);
                });
        }

        window.prompt(promptLabel, text);
        return Promise.resolve();
    }

    function ecFetchMarkdownExport(postId, blogId) {
        var url = '/wp-json/extrachill/v1/tools/markdown-export?post_id=' + encodeURIComponent(postId);
        if (blogId) {
            url += '&blog_id=' + encodeURIComponent(blogId);
        }

        return window.fetch(url, { credentials: 'same-origin' })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then(function(json) {
                if (!json || !json.markdown) {
                    throw new Error('Invalid response');
                }
                return json.markdown;
            });
    }

    // Track social share link clicks (these open in new tabs, no preventDefault needed)
    document.addEventListener('click', function(event) {
        var shareOption = event.target.closest('.share-dropdown .share-option a');
        if (!shareOption) {
            return;
        }

        var optionContainer = shareOption.closest('.share-option');
        if (!optionContainer) {
            return;
        }

        // Determine destination from class name
        var destination = null;
        if (optionContainer.classList.contains('facebook')) {
            destination = 'facebook';
        } else if (optionContainer.classList.contains('twitter')) {
            destination = 'twitter';
        } else if (optionContainer.classList.contains('reddit')) {
            destination = 'reddit';
        } else if (optionContainer.classList.contains('bluesky')) {
            destination = 'bluesky';
        } else if (optionContainer.classList.contains('email')) {
            destination = 'email';
        }

        // Track if it's a social share (not copy-link or copy-markdown, handled separately)
        if (destination && !optionContainer.classList.contains('copy-link') && !optionContainer.classList.contains('copy-markdown')) {
            ecTrackShare(destination, window.location.href);
        }
    });

    // Handle copy-link and copy-markdown (these need preventDefault)
    document.addEventListener('click', function(event) {
        var copyLink = event.target.closest('.copy-link a');
        var copyMarkdown = event.target.closest('.copy-markdown a');

        if (!copyLink && !copyMarkdown) {
            return;
        }

        event.preventDefault();

        if (copyLink) {
            var url = copyLink.getAttribute('data-share-url');
            if (!url) {
                return;
            }

            ecTrackShare('copy_link', url);
            ecCopyToClipboard(url, copyLink, 'Copy this link:');
            return;
        }

        var dropdown = event.target.closest('.share-dropdown');
        if (!dropdown) {
            return;
        }

        var postId = dropdown.getAttribute('data-post-id');
        if (!postId) {
            return;
        }

        var blogId = dropdown.getAttribute('data-blog-id');

        copyMarkdown.textContent = 'Loading...';
        ecTrackShare('copy_markdown', window.location.href);
        ecFetchMarkdownExport(postId, blogId)
            .then(function(markdown) {
                return ecCopyToClipboard(markdown, copyMarkdown, 'Copy this markdown:');
            })
            .catch(function() {
                window.prompt('Copy this markdown:', '');
            })
            .finally(function() {
                copyMarkdown.textContent = 'Copy Markdown';
            });
    });
})();
