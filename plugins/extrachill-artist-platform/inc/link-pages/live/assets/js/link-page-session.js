// JavaScript for WordPress multisite native authentication and edit button display

(function() {
    'use strict';

    // Check if required data is available from wp_localize_script
    if (typeof extrchSessionData === 'undefined' || !extrchSessionData.rest_url || !extrchSessionData.artist_id) {
        return;
    }

    const { rest_url, artist_id } = extrchSessionData;

    /**
     * Checks user permissions via REST API using WordPress multisite authentication
     * and shows the edit button if the user has management access.
     */
    function checkManageAccess(retryCount = 0) {
        const maxRetries = 1;

        // Don't create multiple edit buttons if one already exists
        if (document.querySelector('.extrch-link-page-edit-btn')) {
            return;
        }

        const apiUrl = `${rest_url}extrachill/v1/check-artist-manage-access/${artist_id}`;

        fetch(apiUrl, {
            method: 'GET',
            credentials: 'include', // Ensures WordPress authentication cookies are sent
            headers: {
                'Content-Type': 'application/json',
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data && data.canManage) {
                    createEditButton();
                }
                // No button shown for unauthorized users - this is expected behavior
            })
            .catch(error => {
                // Simple retry logic for network errors only
                if (retryCount < maxRetries && isNetworkError(error)) {
                    setTimeout(() => checkManageAccess(retryCount + 1), 1000);
                    return;
                }

                // Show button in development environments for debugging
                if (isDevelopmentEnvironment()) {
                    createEditButton();
                }
            });
    }

    /**
     * Creates and injects the edit button into the page
     */
    function createEditButton() {
        const manageUrl = `https://community.extrachill.com/manage-link-page/?artist_id=${artist_id}`;

        // Create the edit button element
        const editButton = document.createElement('a');
        editButton.href = manageUrl;
        editButton.className = 'extrch-link-page-edit-btn';
        editButton.innerHTML = '<i class="fas fa-pencil-alt"></i>';

        // Insert the button at the top of the body (after noscript)
        const noscript = document.querySelector('noscript');
        if (noscript && noscript.nextSibling) {
            document.body.insertBefore(editButton, noscript.nextSibling);
        } else {
            document.body.appendChild(editButton);
        }
    }

    /**
     * Determines if the error is a network error that should be retried
     */
    function isNetworkError(error) {
        return error.name === 'TypeError' ||
               error.message.includes('Failed to fetch') ||
               error.message.includes('NetworkError');
    }

    /**
     * Checks if we're running in a development environment
     */
    function isDevelopmentEnvironment() {
        const url = new URL(window.location.href);

        // Check for debug mode
        if (url.searchParams.has('debug_edit_button') || localStorage.getItem('debug_edit_button')) {
            return true;
        }

        // Check if running on localhost/development
        return window.location.hostname === 'localhost' ||
               window.location.hostname === '127.0.0.1' ||
               window.location.hostname.includes('dev') ||
               window.location.hostname.includes('staging');
    }

    // Run the check when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', checkManageAccess);

})(); 
