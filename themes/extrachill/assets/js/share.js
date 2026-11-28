/**
 * Native sharing, fallback menu actions, and share funnel tracking.
 * Dropdown behavior provided by mini-dropdown.js.
 */
(function() {
    'use strict';

    /**
     * Track share click via analytics endpoint.
     *
     * @param {string} destination Share destination or funnel marker
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

        if (!navigator.sendBeacon || !navigator.sendBeacon(endpoint, new Blob([JSON.stringify(data)], { type: 'application/json' }))) {
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

    let nativeSharePending = false;

    // Capture before the mini-dropdown click handler. It won't open a toggle
    // whose click was handled by native sharing.
    document.addEventListener('click', async function(event) {
        const toggle = event.target.closest('.share-dropdown .ec-mini-dropdown-toggle');
        if (!toggle || event.defaultPrevented) {
            return;
        }

        const dropdown = toggle.closest('.share-dropdown');
        if (dropdown.getAttribute('aria-expanded') === 'true') {
            return; // Let the dropdown handle closing, without counting another open.
        }
        if (nativeSharePending) {
            event.preventDefault();
            return;
        }

        ecTrackShare('toggle');
        const shareData = { title: toggle.dataset.shareTitle, url: toggle.dataset.nativeShareUrl };
        if (!shareData.url || typeof navigator.share !== 'function' ||
            (typeof navigator.canShare === 'function' && !navigator.canShare(shareData))) {
            return;
        }

        nativeSharePending = true;
        try {
            // Invoke while the click still grants transient user activation.
            const result = navigator.share(shareData);
            event.preventDefault();
            await result;
        } catch (error) {
            // A synchronous failure leaves the normal dropdown click intact.
            // For a rejected promise the click was already intercepted.
            if (!event.defaultPrevented) {
                return;
            }
            if (error.name === 'AbortError') {
                ecTrackShare('native_cancelled');
            } else {
                dropdown.setAttribute('aria-expanded', 'true');
            }
            return;
        } finally {
            nativeSharePending = false;
        }
        ecTrackShare('native', shareData.url);
    }, true);

    document.addEventListener('click', function(event) {
        const option = event.target.closest('.share-dropdown a[data-share-destination]');
        if (!option) {
            return;
        }

        const destination = option.dataset.shareDestination;
        if (destination === 'copy_link') {
            event.preventDefault();
            const url = option.dataset.shareUrl;
            if (!url) {
                return;
            }

            ecTrackShare('copy_link', url);
            ecCopyToClipboard(url, option);
            return;
        }

        if (destination !== 'copy_markdown') {
            ecTrackShare(destination);
            return;
        }

        event.preventDefault();
        const dropdown = option.closest('.share-dropdown');
        const postId = dropdown.getAttribute('data-post-id');
        if (!postId) {
            return;
        }

        const blogId = dropdown.getAttribute('data-blog-id');

        option.textContent = 'Loading...';
        ecTrackShare('copy_markdown', window.location.href);
        ecFetchMarkdownExport(postId, blogId)
            .then(function(markdown) {
                return ecCopyToClipboard(markdown, option);
            })
            .catch(function() {
                // Silent failure
            })
            .finally(function() {
                option.textContent = 'Copy Markdown';
            });
    });
})();
