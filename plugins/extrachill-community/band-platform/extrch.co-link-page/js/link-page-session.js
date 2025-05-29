// JavaScript for handling session validation and showing the edit button on the link page

(function() {
    // Check if required data is available from wp_localize_script
    if (typeof extrchSessionData === 'undefined' || !extrchSessionData.rest_url || !extrchSessionData.band_id) {
        return;
    }

    const { rest_url, band_id } = extrchSessionData; // Get band_id instead of link_page_id

    /**
     * Checks user permissions via the REST API and shows the edit button if allowed.
     */
    function checkManageAccess() {
        const editButton = document.querySelector('.extrch-link-page-edit-btn');
         // Hide button by default until access is confirmed by API
        if (editButton) {
            editButton.style.display = 'none';
        }

        const apiUrl = `${rest_url}extrachill/v1/check-band-manage-access/${band_id}`; // Use new endpoint and band_id

        fetch(apiUrl, {
            method: 'GET',
             credentials: 'include' // Important: Ensures cookies are sent with cross-origin requests
        })
            .then(response => {
                if (!response.ok) {
                    return response.json().catch(() => ({})); // Attempt to parse JSON even on error
                }
                return response.json();
            })
            .then(data => {
                if (data && data.canManage) {
                    // If canManage is true, show the edit button
                    if (editButton) {
                        editButton.style.display = 'flex'; // Or 'block', depending on your desired layout
                    }
                } else {
                    // If canManage is false, ensure the edit button is hidden
                     if (editButton) {
                         editButton.style.display = 'none';
                    }
                }
            })
            .catch(() => {
                // Ensure button is hidden on error
                 if (editButton) {
                      editButton.style.display = 'none';
                 }
            });
    }

    // Run the check when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', checkManageAccess);

})(); 