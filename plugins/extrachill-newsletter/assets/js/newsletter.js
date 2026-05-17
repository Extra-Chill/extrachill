/**
 * Newsletter Form Handler
 *
 * Generic REST API subscription handler for all newsletter forms.
 * Forms must use data-newsletter-form and data-newsletter-context attributes.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.2
 */

(function() {
    'use strict';

    if (!window.newsletterParams || !newsletterParams.restNonce || !newsletterParams.restUrl) {
        console.error('extrachill-newsletter: newsletterParams missing, aborting form handler.');
        return;
    }

    function findFeedback(form) {
        return form.querySelector('[data-newsletter-feedback]') ||
               form.parentNode.querySelector('[data-newsletter-feedback]');
    }

    /**
     * Get a Turnstile token for a form.
     *
     * - If Turnstile isn't loaded on the page (not configured, or script blocked),
     *   resolve to '' so submission proceeds. The server-side check decides whether
     *   that's acceptable (it isn't, in production — the route will 403).
     * - If a token is already attached to the widget (e.g. resolved on render),
     *   reuse it.
     * - Otherwise, call turnstile.execute() to fetch a fresh token.
     */
    function getTurnstileToken(form) {
        return new Promise(function(resolve) {
            if (typeof window.turnstile === 'undefined') {
                resolve('');
                return;
            }

            const widget = form.querySelector('.cf-turnstile');
            if (!widget) {
                resolve('');
                return;
            }

            try {
                const existing = window.turnstile.getResponse(widget);
                if (existing) {
                    resolve(existing);
                    return;
                }
            } catch (e) {
                // getResponse can throw if the widget isn't fully initialized;
                // fall through to execute().
            }

            try {
                window.turnstile.execute(widget, {
                    callback: function(token) {
                        resolve(token || '');
                    },
                    'error-callback': function() {
                        resolve('');
                    }
                });
            } catch (e) {
                resolve('');
            }
        });
    }

    function submitToServer(form, turnstileToken) {
        const context = form.dataset.newsletterContext;
        const emailInput = form.querySelector('input[type="email"], input[name="email"]');
        const submitButton = form.querySelector('button[type="submit"]');
        const feedback = findFeedback(form);
        const originalButtonText = submitButton.textContent;

        const endpoint = new URL('extrachill/v1/newsletter/subscribe', newsletterParams.restUrl);

        const body = {
            emails: [{ email: emailInput.value, name: '' }],
            context: context,
            source_url: window.location.href
        };

        if (turnstileToken) {
            body.turnstile_response = turnstileToken;
        }

        return fetch(endpoint.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': newsletterParams.restNonce
            },
            body: JSON.stringify(body)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => Promise.reject(err));
            }
            return response.json();
        })
        .then(data => {
            if (feedback) {
                feedback.style.display = 'block';
                feedback.textContent = data.message || 'Successfully subscribed!';
                feedback.className = 'notice notice-success';
            }

            emailInput.value = '';
            if (window.localStorage) {
                localStorage.setItem('subscribed', 'true');
                localStorage.setItem('lastSubscribedTime', Date.now().toString());
            }
        })
        .catch(error => {
            if (feedback) {
                feedback.style.display = 'block';
                feedback.textContent = error.message || 'An error occurred. Please try again.';
                feedback.className = 'notice notice-error';
            }
        })
        .finally(() => {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;

            // Reset the Turnstile widget so the next submission gets a fresh token.
            // Turnstile tokens are single-use; reusing one will 403 on the server.
            if (typeof window.turnstile !== 'undefined') {
                const widget = form.querySelector('.cf-turnstile');
                if (widget) {
                    try {
                        window.turnstile.reset(widget);
                    } catch (e) {
                        // Widget may not be initialized yet; ignore.
                    }
                }
            }
        });
    }

    function handleSubmit(form) {
        const context = form.dataset.newsletterContext;
        const emailInput = form.querySelector('input[type="email"], input[name="email"]');
        const submitButton = form.querySelector('button[type="submit"]');
        const feedback = findFeedback(form);

        if (!emailInput || !submitButton || !context) return;

        submitButton.disabled = true;
        submitButton.textContent = 'Subscribing...';
        if (feedback) feedback.style.display = 'none';

        getTurnstileToken(form).then(function(token) {
            return submitToServer(form, token);
        });
    }

    function init() {
        document.querySelectorAll('[data-newsletter-form]').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleSubmit(form);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
