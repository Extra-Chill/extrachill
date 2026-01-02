/**
 * Share Button - Copy Link Handler
 *
 * Handles clipboard copy for share button copy-link functionality.
 * Dropdown behavior provided by mini-dropdown.js.
 *
 * @package ExtraChill
 */
(function() {
    'use strict';

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
