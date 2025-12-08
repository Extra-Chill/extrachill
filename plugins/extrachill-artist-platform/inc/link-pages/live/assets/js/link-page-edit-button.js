/**
 * Edit Button Client-Side Permission System
 *
 * Security model: Zero server-side HTML rendering. JavaScript performs CORS permission
 * check to artist.extrachill.com and renders edit button only if authorized. Unauthorized
 * users receive no HTML or DOM elements. Requires WordPress authentication cookies with
 * SameSite=None; Secure attributes (configured by extrachill-users plugin).
 */
(function() {
    'use strict';

    /**
     * Performs CORS permission check and renders edit button if authorized
     *
     * Makes fetch request with credentials to artist.extrachill.com REST API endpoint.
     * WordPress cookies with SameSite=None; Secure are sent via credentials: 'include'.
     * Only renders button if server validates permission via ec_can_manage_artist().
     *
     * @return {void}
     */
    function checkEditPermission() {
        if (typeof extrchEditButton === 'undefined' || !extrchEditButton.artist_id) {
            return;
        }

        const artistId = extrchEditButton.artist_id;
        const apiUrl = extrchEditButton.api_url;

        // Construct URL with query parameters
        const url = new URL(apiUrl);
        url.searchParams.append('artist_id', artistId);

        fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.can_edit) {
                renderEditButton(data.manage_url);
            }
        })
        .catch(error => {
            console.error('[Edit Button] Error:', error);
        });
    }

    /**
     * Renders fixed-position edit button with management URL
     *
     * Creates anchor element with Font Awesome icon and appends to body.
     * Includes duplicate check to prevent multiple button instances.
     *
     * @param {string} manageUrl - URL to link page management interface
     * @return {void}
     */
    function renderEditButton(manageUrl) {
        if (document.querySelector('.extrch-link-page-edit-btn')) {
            return;
        }

        const editButton = document.createElement('a');
        editButton.href = manageUrl;
        editButton.className = 'extrch-link-page-edit-btn';
        editButton.setAttribute('aria-label', 'Edit link page');
        editButton.innerHTML = '<i class="fas fa-pencil-alt"></i>';

        document.body.appendChild(editButton);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkEditPermission);
    } else {
        checkEditPermission();
    }

})();
