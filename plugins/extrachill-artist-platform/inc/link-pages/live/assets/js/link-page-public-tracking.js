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
     * Sends link click tracking data to the backend
     * Uses legacy handle_link_click_tracking AJAX handler
     */
    function trackLinkClick(linkUrl) {
        const formData = new FormData();
        formData.append('action', 'link_page_click_tracking');
        formData.append('link_page_id', link_page_id);
        formData.append('link_url', linkUrl);

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