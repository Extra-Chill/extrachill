(function () {
    'use strict';

    function init() {
        const container = document.getElementById('extrachill-onboarding-form');
        if (!container) {
            return;
        }

		const analyticsUrl = container.dataset.analyticsUrl || '';
		const analyticsNonce = container.dataset.analyticsNonce || '';
		const sceneGapUrl = container.dataset.sceneGapUrl || '';
		const sceneGapNonce = container.dataset.sceneGapNonce || '';
		function track(outcome, errorCode) {
			if (!analyticsUrl || !analyticsNonce) {
				return;
			}

			const body = new URLSearchParams({
				action: 'extrachill_onboarding_analytics',
				nonce: analyticsNonce,
				outcome
			});
			if (errorCode) {
				body.set('error_code', errorCode);
			}

			fetch(analyticsUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body
			}).catch(function () {});
		}

		const utils = window.ECAuthUtils;
		if (!utils) {
			track('client_failed', 'auth_utils_missing');
			return;
		}

        const form = document.getElementById('onboarding-form');
        if (!form) {
			track('client_failed', 'form_missing');
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
		const serverFailureCodes = [
			'invalid_user',
			'already_completed',
			'username_too_short',
			'username_too_long',
			'username_invalid_chars',
			'username_exists',
			'username_reserved',
			'artist_or_professional_required',
			'invalid_local_scene_visibility',
			'events_site_unavailable',
			'location_taxonomy_unavailable',
			'location_search_failed',
			'invalid_location_mode',
			'invalid_location_slug',
			'location_not_found',
			'user_event_locations_invalid_response',
			'update_failed'
		];

		function fail(code, message, field) {
			track('client_failed', code);
			utils.renderNotice(container, 'error', message);
			if (field) {
				field.focus();
			}
		}

		// Record an unmatched Local Scene search as a zero-result `search` event.
		//
		// This deliberately does NOT use the onboarding analytics endpoint: the
		// onboarding payload contract is explicitly limited to non-PII fields and
		// forbids free-form user input and Local Scene names, so a raw search term
		// cannot travel that path. The `search` contract is the one that already
		// owns search_term/result_count, already routes through the server-side
		// attack classifier, and already feeds search-gaps reporting.
		function trackSceneGap(term) {
			if (!sceneGapUrl || !sceneGapNonce || !term) {
				return;
			}

			fetch(sceneGapUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({
					action: 'extrachill_onboarding_local_scene_gap',
					nonce: sceneGapNonce,
					search_term: term
				})
			}).catch(function () {});
		}

        if (sceneInput && sceneSlugInput && sceneResults) {
			sceneInput.disabled = false;
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
							if (!response.ok) {
								throw new Error('Local Scene search is temporarily unavailable.');
							}
                            return response.json();
                        })
                        .then(function (response) {
							if (request !== searchRequest) {
								return;
							}
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
							if (request === searchRequest) {
								utils.renderNotice(container, 'error', err.message);
							}
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
				fail('username_empty', 'Please enter a username.', usernameInput);
                return;
            }

            if (username.length < 3) {
				fail('username_too_short', 'Username must be at least 3 characters.', usernameInput);
                return;
            }

            if (username.length > 60) {
				fail('username_too_long', 'Username must be 60 characters or less.', usernameInput);
                return;
            }

            if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
				fail('username_invalid_chars', 'Username can only contain letters, numbers, hyphens, and underscores.', usernameInput);
                return;
            }

            // Local Scene is optional. Typed text with no selected slug means the
            // member searched for a scene we have no location term for, so there was
            // never anything to click. Blocking here stranded them with no way out
            // (Extra-Chill/extrachill-users#380), so we submit without a scene and
            // record the unmatched text as a normal zero-result `search` event. That
            // reuses the existing search/search-gaps primitive — no new event type,
            // no new storage — so unmet Local Scene demand shows up in
            // `wp extrachill analytics search-gaps` alongside every other gap.
            if (sceneInput && sceneInput.value.trim() && !localScene) {
                trackSceneGap(sceneInput.value.trim());
            }

            if (fromJoin && !isArtist && !isProfessional) {
				fail('role_required', 'Please select "I am a musician" or "I work in the music industry" to continue.');
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
					return response.json().catch(function () {
						const error = new Error('The server returned an invalid response. Please try again.');
						error.onboardingClientCode = 'invalid_response';
						throw error;
					}).then(function (data) {
                        if (!response.ok) {
                            const message = data && data.message ? data.message : 'Something went wrong. Please try again.';
							const error = new Error(message);
							if (data && serverFailureCodes.includes(data.code)) {
								error.onboardingServerReported = true;
							} else {
								error.onboardingClientCode = 'response_rejected';
							}
							throw error;
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
					if (!err || !err.onboardingServerReported) {
						track('client_failed', err && err.onboardingClientCode ? err.onboardingClientCode : 'request_failed');
					}
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
