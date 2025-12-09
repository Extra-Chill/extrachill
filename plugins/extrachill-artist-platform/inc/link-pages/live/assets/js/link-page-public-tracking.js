/**
 * Public Link Page Tracking - Views and Link Clicks
 *
 * Tracks page views and link clicks for artist link pages via REST API.
 * Views: Fires on page load, tracked to both all-time counter and 90-day rolling table.
 * Clicks: Fires on link click, URL normalization handled server-side.
 */

(function() {
    'use strict';

    if (typeof extrchTrackingData === 'undefined' || !extrchTrackingData.link_page_id) {
        return;
    }

    const { clickRestUrl, viewRestUrl, link_page_id } = extrchTrackingData;

    function sendBeacon(url, data) {
        const jsonData = JSON.stringify(data);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([jsonData], { type: 'application/json' }));
        } else {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: jsonData,
                keepalive: true
            }).catch(() => {});
        }
    }

    // Track page view on load
    if (viewRestUrl) {
        sendBeacon(viewRestUrl, { post_id: link_page_id });
    }

    // Track link clicks
    if (clickRestUrl) {
        const linkContainer = document.querySelector('.extrch-link-page-content-wrapper');
        if (linkContainer) {
            linkContainer.addEventListener('click', (event) => {
                const linkElement = event.target.closest('a');
                if (linkElement && linkElement.href && !linkElement.classList.contains('extrch-link-page-edit-btn')) {
                    const linkTextEl = linkElement.querySelector('.extrch-link-page-link-text');
                    const linkText = linkTextEl ? linkTextEl.textContent.trim() : '';

                    sendBeacon(clickRestUrl, {
                        link_page_id: link_page_id,
                        link_url: linkElement.href,
                        link_text: linkText
                    });
                }
            });
        }
    }
})();
