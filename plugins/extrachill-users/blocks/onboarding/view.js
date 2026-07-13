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
        const abilitiesUrl = container.dataset.abilitiesUrl || '';
        const nonce = container.dataset.nonce || '';
        const redirectUrl = container.dataset.redirectUrl || '/';
        const fromJoin = container.dataset.fromJoin === 'true';

        const usernameInput = document.getElementById('onboarding-username');
        const artistCheckbox = document.getElementById('user_is_artist');
        const professionalCheckbox = document.getElementById('user_is_professional');
        const submitButton = document.getElementById('onboarding-submit');
        const sceneInput = document.getElementById('onboarding-local-scene');
        const sceneSlugInput = document.getElementById('onboarding-local-scene-slug');
        const sceneResults = document.getElementById('onboarding-local-scene-results');
        let searchTimer;
        let searchRequest = 0;

        if (sceneInput && sceneSlugInput && sceneResults) {
            sceneInput.addEventListener('input', function () {
                sceneSlugInput.value = '';
                window.clearTimeout(searchTimer);
                const search = sceneInput.value.trim();
                if (!search) {
                    sceneResults.hidden = true;
                    sceneResults.replaceChildren();
                    return;
                }

                const request = ++searchRequest;
                searchTimer = window.setTimeout(function () {
                    fetch(abilitiesUrl + 'extrachill%2Fuser-event-locations/run', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify({ input: { mode: 'search', search, limit: 10 } })
                    })
                        .then(function (response) {
                            if (!response.ok) throw new Error('Local Scene search is temporarily unavailable.');
                            return response.json();
                        })
                        .then(function (response) {
                            if (request !== searchRequest) return;
                            const data = response && response.result ? response.result : response;
                            const locations = data && Array.isArray(data.locations) ? data.locations : [];
                            sceneResults.replaceChildren();
                            locations.forEach(function (location) {
                                const option = document.createElement('button');
                                option.type = 'button';
                                option.className = 'onboarding-local-scene-option';
                                option.setAttribute('role', 'option');
                                option.textContent = location.hierarchy && location.hierarchy.label ? location.hierarchy.label : location.name;
                                option.addEventListener('click', function () {
                                    sceneInput.value = option.textContent;
                                    sceneSlugInput.value = location.slug;
                                    sceneResults.hidden = true;
                                });
                                sceneResults.appendChild(option);
                            });
                            sceneResults.hidden = locations.length === 0;
                        })
                        .catch(function (err) {
                            if (request === searchRequest) utils.renderNotice(container, 'error', err.message);
                        });
                }, 250);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            utils.clearNotice(container);

            const username = usernameInput ? usernameInput.value.trim() : '';
            const isArtist = artistCheckbox ? artistCheckbox.checked : false;
            const isProfessional = professionalCheckbox ? professionalCheckbox.checked : false;
            const localScene = sceneSlugInput ? sceneSlugInput.value : '';
            const visibilityInput = form.querySelector('input[name="local_scene_visibility"]:checked');
            const localSceneVisibility = visibilityInput ? visibilityInput.value : 'public';

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

            if (sceneInput && sceneInput.value.trim() && !localScene) {
                utils.renderNotice(container, 'error', 'Choose a Local Scene from the search results, or clear the field to skip it.');
                sceneInput.focus();
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
                body: JSON.stringify(Object.assign({
                    username,
                    user_is_artist: isArtist,
                    user_is_professional: isProfessional,
                    local_scene_visibility: localSceneVisibility
                }, localScene ? { local_scene: localScene } : {}))
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
