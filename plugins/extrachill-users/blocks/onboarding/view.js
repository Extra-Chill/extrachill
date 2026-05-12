(function () {
    'use strict';

    const utils = window.ECAuthUtils;

    function init() {
        if (!utils) {
            console.error('ECAuthUtils not loaded');
            return;
        }

        const container = document.getElementById('extrachill-onboarding-form');
        if (!container) {
            return;
        }

        const form = document.getElementById('onboarding-form');
        if (!form) {
            return;
        }

        const restUrl = container.dataset.restUrl || '';
        const nonce = container.dataset.nonce || '';
        const redirectUrl = container.dataset.redirectUrl || '/';
        const fromJoin = container.dataset.fromJoin === 'true';

        const usernameInput = document.getElementById('onboarding-username');
        const artistCheckbox = document.getElementById('user_is_artist');
        const professionalCheckbox = document.getElementById('user_is_professional');
        const submitButton = document.getElementById('onboarding-submit');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            utils.clearNotice(container);

            const username = usernameInput ? usernameInput.value.trim() : '';
            const isArtist = artistCheckbox ? artistCheckbox.checked : false;
            const isProfessional = professionalCheckbox ? professionalCheckbox.checked : false;

            if (!username) {
                utils.renderNotice(container, 'error', 'Please enter a username.');
                return;
            }

            if (username.length < 3) {
                utils.renderNotice(container, 'error', 'Username must be at least 3 characters.');
                return;
            }

            if (username.length > 60) {
                utils.renderNotice(container, 'error', 'Username must be 60 characters or less.');
                return;
            }

            if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
                utils.renderNotice(container, 'error', 'Username can only contain letters, numbers, hyphens, and underscores.');
                return;
            }

            if (fromJoin && !isArtist && !isProfessional) {
                utils.renderNotice(container, 'error', 'Please select "I am a musician" or "I work in the music industry" to continue.');
                return;
            }

            const restore = utils.setSubmitting(submitButton, 'Saving\u2026');

            const url = restUrl + 'users/onboarding';

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                    username,
                    user_is_artist: isArtist,
                    user_is_professional: isProfessional
                })
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            const message = data && data.message ? data.message : 'Something went wrong. Please try again.';
                            throw new Error(message);
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    const finalRedirect = data && data.redirect_url ? data.redirect_url : redirectUrl;
                    window.location.assign(finalRedirect);
                })
                .catch(function (err) {
                    const message = err && err.message ? err.message : 'Something went wrong. Please try again.';
                    utils.renderNotice(container, 'error', message);
                    restore();
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
