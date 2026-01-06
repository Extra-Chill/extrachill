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

    function handleSubmit(form) {
        const context = form.dataset.newsletterContext;
        const emailInput = form.querySelector('input[type="email"], input[name="email"]');
        const submitButton = form.querySelector('button[type="submit"]');
        const feedback = findFeedback(form);

        if (!emailInput || !submitButton || !context) return;

        const originalButtonText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Subscribing...';
        if (feedback) feedback.style.display = 'none';

        const endpoint = new URL('extrachill/v1/newsletter/subscribe', newsletterParams.restUrl);

        fetch(endpoint.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': newsletterParams.restNonce
            },
            body: JSON.stringify({
                emails: [{ email: emailInput.value, name: '' }],
                context: context,
                source_url: window.location.href
            })
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