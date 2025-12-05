/**
 * Public Link Page Tracking - Link Click Analytics
 *
 * Page views are now tracked via theme-level ec_post_views system (WordPress post meta).
 * This script only handles link-specific click analytics.
 */

(function() {
    // Check if tracking data is available from wp_localize_script
    if (typeof extrchTrackingData === 'undefined' || !extrchTrackingData.ajax_url || !extrchTrackingData.link_page_id) {
        return;
    }

    const { ajax_url, link_page_id } = extrchTrackingData;

    /**
     * Strips auto-generated Google Analytics parameters from URLs.
     * Removes _gl, _ga, and _ga_* query params while preserving affiliate IDs and custom params.
     */
    function normalizeTrackedUrl(url) {
        try {
            const urlObj = new URL(url);
            const paramsToStrip = ['_gl', '_ga'];
            
            paramsToStrip.forEach(param => urlObj.searchParams.delete(param));
            
            // Remove any _ga_* parameters
            const keysToRemove = [];
            urlObj.searchParams.forEach((value, key) => {
                if (key.startsWith('_ga_')) {
                    keysToRemove.push(key);
                }
            });
            keysToRemove.forEach(key => urlObj.searchParams.delete(key));
            
            return urlObj.toString();
        } catch (e) {
            return url;
        }
    }

    /**
     * Sends link click tracking data to the backend
     */
    function trackLinkClick(linkUrl) {
        const normalizedUrl = normalizeTrackedUrl(linkUrl);
        const formData = new FormData();
        formData.append('action', 'link_page_click_tracking');
        formData.append('link_page_id', link_page_id);
        formData.append('link_url', normalizedUrl);

        // Use sendBeacon for better reliability during page unload
        if (navigator.sendBeacon) {
            try {
                navigator.sendBeacon(ajax_url, formData);
            } catch (e) {
                console.error('Error sending beacon:', e);
            }
        } else {
            // Fallback to fetch with keepalive
            fetch(ajax_url, {
                method: 'POST',
                body: formData,
                keepalive: true
            }).catch(error => {
                console.error('Error sending tracking data:', error);
            });
        }
    }

    // --- Track Link Clicks --- //
    // Use event delegation on a container that includes all user links
    const linkContainer = document.querySelector('.extrch-link-page-content-wrapper');
    if (linkContainer) {
        linkContainer.addEventListener('click', (event) => {
            // Find the nearest ancestor anchor tag
            const linkElement = event.target.closest('a');

            // Ensure it's a valid link with an href, and not the edit button
            if (linkElement && linkElement.href && !linkElement.classList.contains('extrch-link-page-edit-btn')) {
                trackLinkClick(linkElement.href);
                // Note: We don't preventDefault. sendBeacon handles background sending.
            }
        });
    } else {
        console.warn('Extrch Tracking: Could not find link container for click tracking.');
    }

})(); 