/**
 * Link Page edit button (bearer-token auth).
 *
 * Renders no server-side HTML. The host site supplies two body attributes:
 * `data-extrch-permissions-api-url` (GET with a bearer token, returns
 * `{can_edit, manage_url}`) and `data-extrch-token-handoff-url` (a one-time
 * redirect that returns a token in the URL fragment). Bearer tokens are used
 * because the public Link Page host may not share the auth cookie domain.
 * Anonymous or unauthorized visitors get no button and no DOM.
 *
 * The handoff is a full top-level navigation, so it only runs when there is a
 * signal the visitor may be an editor: they arrived from the host site's
 * network, this browser has received an edit token before, or the host has
 * primed an `ec_link_editor` hint cookie for a signed-in artist (see
 * extrachill-network editor-hint.php). Everyone else loads the page once with
 * no cross-site redirect.
 */
(function () {
    'use strict';

    var TOKEN_STORAGE_KEY = 'ecLinkEditToken';
    // Marker so an anonymous visitor only round-trips through the mint handoff
    // once per browser tab session instead of redirecting on every page view.
    var HANDOFF_ATTEMPTED_KEY = 'ecLinkEditHandoffAttempted';
    // Persistent marker: this browser has received an edit token before, so a
    // handoff is worth a redirect once the short-lived token expires.
    var KNOWN_EDITOR_KEY = 'ecLinkEditKnownEditor';

    /**
     * Whether this browser has ever received an edit token.
     *
     * @return {boolean}
     */
    function isKnownEditor() {
        try {
            return window.localStorage.getItem(KNOWN_EDITOR_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    /**
     * Whether the host primed this browser as a signed-in artist's.
     *
     * @return {boolean}
     */
    function hasEditorHint() {
        return /(?:^|;\s*)ec_link_editor=1(?:;|$)/.test(document.cookie || '');
    }

    /**
     * Forget editor signals after a handoff proves the visitor is signed out.
     *
     * @return {void}
     */
    function forgetEditor() {
        try {
            window.localStorage.removeItem(KNOWN_EDITOR_KEY);
        } catch (e) {}
        document.cookie = 'ec_link_editor=; Max-Age=0; Path=/; SameSite=Lax';
    }

    /**
     * Remember that this browser belongs to someone who can receive tokens.
     *
     * @return {void}
     */
    function markKnownEditor() {
        try {
            window.localStorage.setItem(KNOWN_EDITOR_KEY, '1');
        } catch (e) {}
    }

    /**
     * Whether the referrer is the host site or a sibling on its parent domain
     * (e.g. the handoff host's network), i.e. the visitor may be signed in.
     *
     * @param {string} handoffUrl Handoff URL on the host site.
     * @param {string} referrer   document.referrer.
     * @return {boolean}
     */
    function cameFromHostNetwork(handoffUrl, referrer) {
        if (!handoffUrl || !referrer) {
            return false;
        }
        try {
            var hostname = new URL(handoffUrl).hostname;
            var from = new URL(referrer).hostname;
            var labels = hostname.split('.');
            var parent = labels.length > 2 ? labels.slice(1).join('.') : hostname;
            return from === hostname || from === parent || from.slice(-(parent.length + 1)) === '.' + parent;
        } catch (e) {
            return false;
        }
    }

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
     * Redirect to the mint handoff endpoint on the host site, which mints a
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
            markKnownEditor();
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
            forgetEditor();
            return;
        }

        // 4. Bootstrap a token via the one-time handoff redirect, but only for
        //    likely editors. Anonymous visitors never leave the page.
        if (isKnownEditor() || hasEditorHint() || cameFromHostNetwork(handoffUrl, document.referrer || '')) {
            attemptHandoff(handoffUrl);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
