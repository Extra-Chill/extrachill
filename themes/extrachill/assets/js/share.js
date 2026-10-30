/**
 * Share Button - Copy Link Handler
 *
 * Handles clipboard copy for share button copy-link functionality.
 * Dropdown behavior provided by mini-dropdown.js.
 * Tracks share clicks via analytics endpoint.
 */
(function() {
    'use strict';

    /**
     * Track share click via analytics endpoint.
     *
     * @param {string} destination Share destination (facebook, twitter, etc.)
     * @param {string} shareUrl    URL being shared
     */
    function ecTrackShare(destination, shareUrl) {
        const endpoint = '/wp-json/extrachill/v1/analytics/click';
        const data = {
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

    function ecCopyToClipboard(text, linkEl) {
        const originalText = linkEl.textContent;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text)
                .then(function() {
                    linkEl.textContent = 'Copied!';
                    setTimeout(function() {
                        linkEl.textContent = originalText;
                    }, 2000);
                })
                .catch(function() {
                    // Silent failure
                });
        }

        return Promise.resolve();
    }

    function ecFetchMarkdownExport(postId, blogId) {
        let url = '/wp-json/extrachill/v1/tools/markdown-export?post_id=' + encodeURIComponent(postId);
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
        const shareOption = event.target.closest('.share-dropdown .share-option a');
        if (!shareOption) {
            return;
        }

        const optionContainer = shareOption.closest('.share-option');
        if (!optionContainer) {
            return;
        }

        // Determine destination from class name
        let destination = null;
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
        const copyLink = event.target.closest('.copy-link a');
        const copyMarkdown = event.target.closest('.copy-markdown a');

        if (!copyLink && !copyMarkdown) {
            return;
        }

        event.preventDefault();

        if (copyLink) {
            const url = copyLink.getAttribute('data-share-url');
            if (!url) {
                return;
            }

            ecTrackShare('copy_link', url);
            ecCopyToClipboard(url, copyLink);
            return;
        }

        const dropdown = event.target.closest('.share-dropdown');
        if (!dropdown) {
            return;
        }

        const postId = dropdown.getAttribute('data-post-id');
        if (!postId) {
            return;
        }

        const blogId = dropdown.getAttribute('data-blog-id');

        copyMarkdown.textContent = 'Loading...';
        ecTrackShare('copy_markdown', window.location.href);
        ecFetchMarkdownExport(postId, blogId)
            .then(function(markdown) {
                return ecCopyToClipboard(markdown, copyMarkdown);
            })
            .catch(function() {
                // Silent failure
            })
            .finally(function() {
                copyMarkdown.textContent = 'Copy Markdown';
            });
    });
})();
