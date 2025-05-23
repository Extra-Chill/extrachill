// JavaScript for handling session validation and showing the edit button on the link page

(function() {
    console.log('link-page-session.js script started.'); // DEBUG
    console.log('Localized data:', extrchSessionData); // DEBUG

    // Check if required data is available from wp_localize_script
    if (typeof extrchSessionData === 'undefined' || !extrchSessionData.rest_url || !extrchSessionData.band_id) {
        console.warn('Extrch Session Error: Missing required data (rest_url or band_id).');
        return;
    }

    const { rest_url, band_id } = extrchSessionData; // Get band_id instead of link_page_id

    /**
     * Checks user permissions via the REST API and shows the edit button if allowed.
     */
    function checkManageAccess() {
        console.log('checkManageAccess function started.'); // DEBUG
        const editButton = document.querySelector('.extrch-link-page-edit-btn');
         // Hide button by default until access is confirmed by API
        if (editButton) {
            editButton.style.display = 'none';
        }

        console.log('Extrch Session: Making API call to check manage access on main site.'); // DEBUG
        // Construct the full API endpoint URL using the main site's REST URL and band_id
        const apiUrl = `${rest_url}extrachill/v1/check-band-manage-access/${band_id}`; // Use new endpoint and band_id

        fetch(apiUrl, {
            method: 'GET',
             credentials: 'include' // Important: Ensures cookies are sent with cross-origin requests
        })
            .then(response => {
                if (!response.ok) {
                    console.error(`API request failed with status: ${response.status}`);
                     // Optionally, log response body for more details
                    return response.json().catch(() => ({})); // Attempt to parse JSON even on error
                }
                return response.json();
            })
            .then(data => {
                console.log('Extrch Session API response from main site:', data); // Debugging
                if (data && data.canManage) {
                    // If canManage is true, show the edit button
                    console.log('Extrch Session: User can manage, showing edit button.'); // DEBUG
                    if (editButton) {
                        editButton.style.display = 'flex'; // Or 'block', depending on your desired layout
                    }
                } else {
                    // If canManage is false, ensure the edit button is hidden
                    console.log('Extrch Session: User cannot manage, hiding edit button.'); // DEBUG
                     if (editButton) {
                         editButton.style.display = 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching manage access data from main site:', error);
                 // Ensure button is hidden on error
                 if (editButton) {
                      editButton.style.display = 'none';
                 }
            });
    }

    // Run the check when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', checkManageAccess);

})(); 