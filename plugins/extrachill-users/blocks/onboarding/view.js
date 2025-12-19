(function () {
    'use strict';

    var utils = window.ECAuthUtils;

    function init() {
        if (!utils) {
            console.error('ECAuthUtils not loaded');
            return;
        }

        var container = document.getElementById('extrachill-onboarding-form');
        if (!container) {
            return;
        }

        var form = document.getElementById('onboarding-form');
        if (!form) {
            return;
        }

        var restUrl = container.dataset.restUrl || '';
        var nonce = container.dataset.nonce || '';
        var redirectUrl = container.dataset.redirectUrl || '/';
        var fromJoin = container.dataset.fromJoin === 'true';

        var usernameInput = document.getElementById('onboarding-username');
        var artistCheckbox = document.getElementById('user_is_artist');
        var professionalCheckbox = document.getElementById('user_is_professional');
        var submitButton = document.getElementById('onboarding-submit');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            utils.clearNotice(container);

            var username = usernameInput ? usernameInput.value.trim() : '';
            var isArtist = artistCheckbox ? artistCheckbox.checked : false;
            var isProfessional = professionalCheckbox ? professionalCheckbox.checked : false;

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

            var restore = utils.setSubmitting(submitButton, 'Saving\u2026');

            var url = restUrl + 'users/onboarding';

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                    username: username,
                    user_is_artist: isArtist,
                    user_is_professional: isProfessional
                })
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            var message = data && data.message ? data.message : 'Something went wrong. Please try again.';
                            throw new Error(message);
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    var finalRedirect = data && data.redirect_url ? data.redirect_url : redirectUrl;
                    window.location.assign(finalRedirect);
                })
                .catch(function (err) {
                    var message = err && err.message ? err.message : 'Something went wrong. Please try again.';
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
