/**
 * Edit Button Client-Side Permission System (bearer-token auth)
 *
 * Security model: zero server-side HTML. JavaScript checks whether the current
 * visitor may manage this artist and renders the edit button only if authorized.
 * Unauthorized/anonymous visitors get no button and no DOM elements.
 *
 * Why bearer tokens (not cookies): extrachill.link is a different registrable
 * domain than *.extrachill.com, where the WordPress auth cookie lives. A cookie
 * can never reach extrachill.link, and the legacy SameSite=None cross-site
 * cookie hack is blocked by modern browser privacy (Safari ITP, Chrome
 * third-party-cookie phase-out), so the button silently failed for many
 * logged-in artists.
 *
 * Instead we use a wp-native bearer token. It rides in an Authorization header,
 * which is immune to SameSite / third-party-cookie restrictions and resolves the
 * user network-wide. The token is bootstrapped via a one-time redirect to the
 * artist site (where the cookie IS first-party), which mints a short-lived token
 * and hands it back in the URL fragment. We cache it in localStorage and attach
 * it to the cross-origin permissions request.
 *
 * Related:
 * - Mint endpoint: extrachill-api/inc/auth/extrachill-link-token-handoff.php
 * - Permissions route: extrachill-api/inc/routes/artists/permissions.php
 */
(function () {
    'use strict';

    var TOKEN_STORAGE_KEY = 'ecLinkEditToken';
    // Marker so an anonymous visitor only round-trips through the mint handoff
    // once per browser tab session instead of redirecting on every page view.
    var HANDOFF_ATTEMPTED_KEY = 'ecLinkEditHandoffAttempted';

    /**
     * Read a non-expired cached token from localStorage.
     *
     * @return {string|null} The token string, or null if absent/expired.
     */
    function getStoredToken() {
        try {
            var raw = window.localStorage.getItem(TOKEN_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.token || !parsed.expiresAt) {
                return null;
            }
            // Treat as expired a little early to avoid using a token that dies
            // mid-request. wp-native access tokens live ~15 minutes.
            if (Date.now() >= (parsed.expiresAt - 5000)) {
                window.localStorage.removeItem(TOKEN_STORAGE_KEY);
                return null;
            }
            return parsed.token;
        } catch (e) {
            return null;
        }
    }

    /**
     * Persist a token with a client-side expiry (~15 min, matching wp-native).
     *
     * @param {string} token The plaintext access token.
     * @return {void}
     */
    function storeToken(token) {
        try {
            window.localStorage.setItem(
                TOKEN_STORAGE_KEY,
                JSON.stringify({
                    token: token,
                    // 15 minutes; the server is the source of truth and a 401
                    // will clear it early if it dies sooner.
                    expiresAt: Date.now() + (15 * 60 * 1000)
                })
            );
        } catch (e) {
            // localStorage unavailable (private mode quotas etc.) — the button
            // just won't render. Non-fatal.
        }
    }

    /**
     * Clear the cached token.
     *
     * @return {void}
     */
    function clearStoredToken() {
        try {
            window.localStorage.removeItem(TOKEN_STORAGE_KEY);
        } catch (e) {}
    }

    /**
     * Parse the token (or the tokenless marker) out of the URL fragment left by
     * the mint handoff redirect, then scrub the fragment from the address bar.
     *
     * @return {{token: (string|null), attempted: boolean}}
     */
    function consumeFragmentToken() {
        var result = { token: null, attempted: false };
        var hash = window.location.hash || '';
        if (!hash || hash.length < 2) {
            return result;
        }

        var fragment = hash.charAt(0) === '#' ? hash.substring(1) : hash;
        var params = new URLSearchParams(fragment);

        if (params.has('ec_link_token')) {
            result.token = params.get('ec_link_token');
            result.attempted = true;
        } else if (params.has('ec_link_token_none')) {
            // Handoff ran and the visitor was not logged in.
            result.attempted = true;
        } else {
            return result;
        }

        // Scrub our keys from the fragment, preserving any unrelated fragment.
        params.delete('ec_link_token');
        params.delete('ec_link_token_none');
        var remaining = params.toString();
        var cleanUrl = window.location.pathname + window.location.search + (remaining ? '#' + remaining : '');
        try {
            window.history.replaceState(null, '', cleanUrl);
        } catch (e) {
            // history API unavailable — leave the URL as-is.
        }

        return result;
    }

    /**
     * Redirect to the mint handoff endpoint on the artist site, which mints a
     * short-lived token (if the visitor is logged in there) and 302s back with
     * the token in the fragment. Guarded by a per-session marker so anonymous
     * visitors only round-trip once.
     *
     * @param {string} handoffUrl Base handoff URL from the body data attribute.
     * @return {void}
     */
    function attemptHandoff(handoffUrl) {
        if (!handoffUrl) {
            return;
        }
        try {
            if (window.sessionStorage.getItem(HANDOFF_ATTEMPTED_KEY)) {
                return;
            }
            window.sessionStorage.setItem(HANDOFF_ATTEMPTED_KEY, '1');
        } catch (e) {
            // sessionStorage unavailable — fall through; without the guard we'd
            // risk a redirect loop, so bail rather than redirect.
            return;
        }

        var returnUrl = window.location.href.split('#')[0];
        var separator = handoffUrl.indexOf('?') === -1 ? '?' : '&';
        var destination = handoffUrl + separator + 'return=' + encodeURIComponent(returnUrl);
        window.location.assign(destination);
    }

    /**
     * Call the permissions endpoint with a bearer token and render the button if
     * authorized. On 401 (token expired/invalid) clears the cached token.
     *
     * @param {string} apiUrl The permissions endpoint URL.
     * @param {string} token  The bearer access token.
     * @return {void}
     */
    function checkPermission(apiUrl, token) {
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + token
            }
        })
            .then(function (response) {
                if (response.status === 401) {
                    clearStoredToken();
                    return null;
                }
                if (!response.ok) {
                    return null;
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || data.can_edit !== true || !data.manage_url) {
                    return;
                }
                renderEditButton(data.manage_url);
            })
            .catch(function () {});
    }

    /**
     * Render the fixed-position edit button.
     *
     * @param {string} manageUrl URL to the link page management interface.
     * @return {void}
     */
    function renderEditButton(manageUrl) {
        if (document.querySelector('.extrch-link-page-edit-btn')) {
            return;
        }

        var editButton = document.createElement('a');
        editButton.href = manageUrl;
        editButton.className = 'extrch-link-page-edit-btn';
        editButton.setAttribute('aria-label', 'Edit link page');
        editButton.innerHTML = '<i class="fas fa-pencil-alt"></i>';

        document.body.appendChild(editButton);
    }

    /**
     * Orchestrate the edit-button auth flow.
     *
     * @return {void}
     */
    function init() {
        var body = document.body;
        if (!body || !body.dataset) {
            return;
        }

        var apiUrl = body.dataset.extrchPermissionsApiUrl || '';
        var handoffUrl = body.dataset.extrchTokenHandoffUrl || '';
        if (!apiUrl) {
            return;
        }

        // 1. If we just came back from the mint handoff, capture the token.
        var fragment = consumeFragmentToken();
        if (fragment.token) {
            storeToken(fragment.token);
        }

        // 2. Use a cached/just-captured token if we have one.
        var token = getStoredToken();
        if (token) {
            checkPermission(apiUrl, token);
            return;
        }

        // 3. No token. If the handoff already ran this session (including the
        //    tokenless "not logged in" return), stop — anonymous visitor.
        if (fragment.attempted) {
            return;
        }

        // 4. Bootstrap a token via the one-time handoff redirect.
        attemptHandoff(handoffUrl);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
