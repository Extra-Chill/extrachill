/**
 * Google Sign-In Module
 *
 * Integrates Google Identity Services (GIS) library for authentication.
 * Uses ECAuthUtils for shared functionality.
 */
(function () {
    'use strict';

    var utils = window.ECAuthUtils;
    var initialized = false;

    /**
     * Initialize Google Sign-In for all button containers on the page.
     *
     * @param {Object} config Configuration object.
     * @param {string} config.clientId Google OAuth client ID.
     * @param {string} config.restUrl REST API base URL.
     */
    function init(config) {
        if (!config || !config.clientId) {
            console.error('ECGoogleSignIn: clientId is required');
            return;
        }

        if (!utils) {
            console.error('ECGoogleSignIn: ECAuthUtils not loaded');
            return;
        }

        if (typeof google === 'undefined' || !google.accounts) {
            console.error('ECGoogleSignIn: Google Identity Services not loaded');
            return;
        }

        if (initialized) {
            return;
        }

        initialized = true;

        google.accounts.id.initialize({
            client_id: config.clientId,
            callback: function (response) {
                handleCredentialResponse(response, config);
            },
            auto_select: false,
            cancel_on_tap_outside: true
        });

        var containers = document.querySelectorAll('.google-signin-button');
        containers.forEach(function (container) {
            renderButton(container);
        });
    }

    /**
     * Render Google Sign-In button in a container.
     *
     * @param {HTMLElement} container Button container element.
     */
    function renderButton(container) {
        if (!container) {
            return;
        }

        google.accounts.id.renderButton(container, {
            type: 'standard',
            theme: 'outline',
            size: 'large',
            text: 'continue_with',
            shape: 'rectangular',
            logo_alignment: 'left',
            width: container.offsetWidth || 300
        });
    }

    /**
     * Get success redirect URL from form hidden field.
     *
     * @return {string} Redirect URL or current page URL.
     */
    function getSuccessRedirectUrl() {
        var input = document.querySelector('input[name="success_redirect_url"]');
        if (input && input.value) {
            return input.value;
        }
        return window.location.href;
    }

    /**
     * Handle credential response from Google.
     *
     * @param {Object} response Google credential response.
     * @param {Object} config Configuration object.
     */
    function handleCredentialResponse(response, config) {
        if (!response || !response.credential) {
            showError('Google Sign-In failed. Please try again.');
            return;
        }

        var deviceId = utils.getDeviceId();
        if (!deviceId) {
            showError('Unable to generate device ID.');
            return;
        }

        var fromJoin = isFromJoinFlow();
        var successRedirectUrl = getSuccessRedirectUrl();

        setGlobalLoading(true);

        var url = new URL('auth/google', config.restUrl || utils.getRestRoot());

        fetch(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id_token: response.credential,
                device_id: deviceId,
                device_name: 'Web',
                from_join: fromJoin,
                set_cookie: true,
                remember: true,
                success_redirect_url: successRedirectUrl
            })
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        var message = data && data.message ? data.message : 'Google Sign-In failed.';
                        throw new Error(message);
                    }
                    return data;
                });
            })
            .then(function (data) {
                var redirectUrl = data && data.redirect_url ? data.redirect_url : window.location.href;
                window.location.assign(redirectUrl);
            })
            .catch(function (err) {
                setGlobalLoading(false);
                showError(err && err.message ? err.message : 'Google Sign-In failed.');
            });
    }

    /**
     * Check if current page is from /join flow.
     *
     * @return {boolean}
     */
    function isFromJoinFlow() {
        try {
            var params = new URL(window.location.href).searchParams;
            return params.get('from_join') === 'true';
        } catch (e) {
            return false;
        }
    }

    /**
     * Show error message in the closest form container.
     *
     * @param {string} message Error message.
     */
    function showError(message) {
        var container = document.querySelector('.login-register-form');
        if (container && utils) {
            utils.renderNotice(container, 'error', message);
        } else {
            console.error('ECGoogleSignIn:', message);
        }
    }

    /**
     * Set loading state on all Google buttons.
     *
     * @param {boolean} loading Whether loading.
     */
    function setGlobalLoading(loading) {
        var containers = document.querySelectorAll('.google-signin-button');
        containers.forEach(function (container) {
            container.style.opacity = loading ? '0.5' : '1';
            container.style.pointerEvents = loading ? 'none' : 'auto';
        });
    }

    window.ECGoogleSignIn = {
        init: init
    };
})();
