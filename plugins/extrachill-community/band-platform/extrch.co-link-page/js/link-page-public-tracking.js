// Public Link Page Tracking Script

(function() {
    // Check if tracking data is available from wp_localize_script
    if (typeof extrchTrackingData === 'undefined' || !extrchTrackingData.ajax_url || !extrchTrackingData.link_page_id) {
        return;
    }

    const { ajax_url, link_page_id } = extrchTrackingData;
    // const nonce = extrchTrackingData.nonce; // Use if nonce check is implemented

    /**
     * Sends tracking data to the backend.
     * Uses navigator.sendBeacon if available for better reliability on unload.
     * Falls back to fetch for page view tracking.
     */
    function sendTrackingEvent(eventType, eventIdentifier) {
        // console.log(`Tracking event: ${eventType}, Identifier: ${eventIdentifier}`); // For debugging

        const formData = new FormData();
        formData.append('action', 'extrch_record_link_event');
        formData.append('link_page_id', link_page_id);
        formData.append('event_type', eventType);
        formData.append('event_identifier', eventIdentifier);
        // if (nonce) {
        //     formData.append('security_nonce', nonce);
        // }

        // Use sendBeacon for clicks if available (more reliable during page unload)
        if (eventType === 'link_click' && navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(ajax_url, formData)) {
                    // console.log('Beacon sent successfully.');
                } else {
                    // console.warn('Beacon failed to queue.');
                     // Fallback or just log? sendBeacon failure is hard to handle reliably.
                }
            } catch (e) {
                console.error('Error sending beacon:', e);
            }
        } else {
            // Use fetch for page view or as fallback
            fetch(ajax_url, {
                method: 'POST',
                body: formData,
                keepalive: eventType === 'link_click' // Attempt to keep connection alive for clicks
            })
            .then(response => {
                if (!response.ok) {
                    console.warn(`Tracking request failed with status: ${response.status}`);
                }
                 return response.json(); // Attempt to read body even on failure for messages
            })
            .then(data => {
                if (data && data.success) {
                    // console.log('Tracking success:', data.data?.message);
                } else {
                    console.warn('Tracking failed:', data?.data?.message || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('Error sending tracking data:', error);
            });
        }
    }

    // --- Track Page View --- //
    document.addEventListener('DOMContentLoaded', () => {
        sendTrackingEvent('page_view', 'page');
    });

    // --- Track Link Clicks --- //
    // Use event delegation on a container that includes all user links
    // Adjust selector if needed - '.extrch-link-page-content' is from extrch-link-page-template.php
    const linkContainer = document.querySelector('.extrch-link-page-content-wrapper');
    if (linkContainer) {
        linkContainer.addEventListener('click', (event) => {
            // Find the nearest ancestor anchor tag
            const linkElement = event.target.closest('a');

            // Ensure it's a valid link with an href, and not the edit button
            if (linkElement && linkElement.href && !linkElement.classList.contains('extrch-link-page-edit-btn')) {
                const linkUrl = linkElement.href;
                sendTrackingEvent('link_click', linkUrl);

                // Note: We don't preventDefault. sendBeacon handles background sending.
                // If using fetch fallback, `keepalive: true` attempts the same, but isn't guaranteed.
                // If tracking *must* complete before navigation, you might need preventDefault,
                // send with fetch, and then manually set window.location = linkUrl in the .then() or .finally(),
                // but this can slightly delay navigation.
            }
        });
    } else {
        console.warn('Extrch Tracking Error: Could not find link container (.extrch-link-page-content-wrapper) for click tracking.');
    }

})(); 