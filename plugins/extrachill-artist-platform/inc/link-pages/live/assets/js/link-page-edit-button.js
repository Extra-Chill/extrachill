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
        const body = document.body;
        const artistId = body && body.dataset ? body.dataset.extrchArtistId : '';
        const apiUrl = body && body.dataset ? body.dataset.extrchPermissionsApiUrl : '';

        if (!artistId || !apiUrl) {
            return;
        }

        const artistIdInt = parseInt(artistId, 10);
        if (!Number.isFinite(artistIdInt) || artistIdInt <= 0) {
            return;
        }

        const url = new URL(apiUrl);
        url.searchParams.set('artist_id', String(artistIdInt));

        fetch(url, {
            method: 'GET',
            credentials: 'include'
        })
        .then((response) => {
            if (!response.ok) {
                return null;
            }
            return response.json();
        })
        .then((data) => {
            if (!data || data.can_edit !== true || !data.manage_url) {
                return;
            }

            renderEditButton(data.manage_url);
        })
        .catch(() => {});
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
