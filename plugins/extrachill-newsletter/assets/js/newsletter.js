/**
 * Newsletter Form Handler
 *
 * Generic REST API subscription handler for all newsletter forms.
 * Forms must use data-newsletter-form and data-newsletter-context attributes.
 *
 * @package ExtraChillNewsletter
 * @since 1.1.0
 */

(function() {
    'use strict';

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

        fetch('/wp-json/extrachill/v1/newsletter/subscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': newsletterParams.restNonce
            },
            body: JSON.stringify({
                email: emailInput.value,
                context: context
            })
        })
        .then(response => response.json())
        .then(data => {
            if (feedback) {
                feedback.style.display = 'block';
                feedback.textContent = data.message || (data.success ? 'Successfully subscribed!' : 'Subscription failed.');
                feedback.className = 'newsletter-feedback ' + (data.success ? 'success' : 'error');
            }

            if (data.success) {
                emailInput.value = '';
                if (window.localStorage) {
                    localStorage.setItem('subscribed', 'true');
                    localStorage.setItem('lastSubscribedTime', Date.now().toString());
                }
            }
        })
        .catch(error => {
            console.error('Newsletter subscription error:', error);
            if (feedback) {
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.className = 'newsletter-feedback error';
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