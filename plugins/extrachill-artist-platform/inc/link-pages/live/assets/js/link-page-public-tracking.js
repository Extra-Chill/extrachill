/**
 * Public Link Page Tracking - Link Click Analytics
 *
 * Tracks link clicks via REST API. URL normalization (GA param stripping)
 * is handled server-side by the API endpoint.
 */

(function() {
    'use strict';

    if (typeof extrchTrackingData === 'undefined' || !extrchTrackingData.restUrl || !extrchTrackingData.link_page_id) {
        return;
    }

    const { restUrl, link_page_id } = extrchTrackingData;

    function trackLinkClick(linkUrl) {
        const data = JSON.stringify({
            link_page_id: link_page_id,
            link_url: linkUrl
        });

        if (navigator.sendBeacon) {
            const blob = new Blob([data], { type: 'application/json' });
            navigator.sendBeacon(restUrl, blob);
        } else {
            fetch(restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: data,
                keepalive: true
            }).catch(() => {});
        }
    }

    const linkContainer = document.querySelector('.extrch-link-page-content-wrapper');
    if (linkContainer) {
        linkContainer.addEventListener('click', (event) => {
            const linkElement = event.target.closest('a');
            if (linkElement && linkElement.href && !linkElement.classList.contains('extrch-link-page-edit-btn')) {
                trackLinkClick(linkElement.href);
            }
        });
    }
})();
