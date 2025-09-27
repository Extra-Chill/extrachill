/**
 * ExtraChill Newsletter Popup JavaScript
 *
 * Handles newsletter popup functionality as a dedicated module.
 * Manages popup creation, display, scroll detection, and form submission.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.1
 */

(function($) {
    'use strict';

    // Prevent multiple initialization
    if (window.ExtraChillNewsletterPopup) {
        return;
    }

    /**
     * Newsletter Popup System
     *
     * Creates and manages newsletter subscription popup
     */
    function initNewsletterPopup() {
        // Don't show popup if user has already subscribed recently
        if (localStorage.getItem('subscribed') === 'true') {
            return;
        }

        // Create overlay if it doesn't exist
        let overlay = document.querySelector('.overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'overlay';
            document.body.appendChild(overlay);
        }

        /**
         * Create popup with specified content
         */
        function createPopup(headerText, inputPlaceholder, buttonText, popupClass, replaceExisting = false) {
            let popup;
            const closeButtonText = localStorage.getItem('subscribed') === 'true' ? "Close" : newsletter_popup_vars.close_text;

            if (replaceExisting) {
                popup = document.querySelector('.' + popupClass);
                if (popup) {
                    popup.innerHTML = `
                        <p>${headerText}</p>
                        <button class="close-popup">${closeButtonText}</button>
                        <button class="follow-instagram">Follow Instagram</button>`;
                }
            } else {
                popup = document.createElement('div');
                popup.className = popupClass;

                let linkHTML = '';
                if (popupClass === 'subscribe-popup') {
                    linkHTML = `<p style="text-align:center;"><a href="${newsletter_popup_vars.newsletters_url}" target="_blank">See past Newsletters</a></p>`;
                }

                popup.innerHTML = `
                    <p>${headerText}</p>
                    <form>
                        <input type="text" name="email" placeholder="${inputPlaceholder}" required>
                        <button class="subscribe-button" type="submit">${buttonText}</button>
                    </form>
                    ${linkHTML}
                    <span class="popup-buttons">
                    <button class="follow-instagram">Follow Instagram</button>
                    <button class="close-popup">${closeButtonText}</button>
                    </span>`;

                document.body.appendChild(popup);
                overlay.style.display = 'block';
            }

            return popup;
        }

        /**
         * Handle scroll-triggered popup display
         */
        function handleScrollAndPopup() {
            const subscribed = localStorage.getItem('subscribed');
            let popupTriggered = false;

            // Check for trigger elements
            const primaryTriggerDiv = document.querySelector('.community-cta');
            const secondaryTriggerDiv = document.querySelector('#extra-footer');
            const targetDiv = primaryTriggerDiv || secondaryTriggerDiv;

            if (!targetDiv) return;

            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !popupTriggered) {
                        popupTriggered = true;
                        observer.unobserve(entry.target);

                        if (subscribed !== 'true') {
                            createPopup(
                                newsletter_popup_vars.popup_text,
                                newsletter_popup_vars.placeholder_text,
                                newsletter_popup_vars.button_text,
                                'subscribe-popup'
                            );
                        }
                    }
                });
            }, { threshold: 0.5 });

            observer.observe(targetDiv);
        }

        /**
         * Handle popup interactions
         */
        function initPopupInteractions() {
            document.addEventListener('pointerup', function(event) {
                if (event.pointerType === 'mouse' && event.button !== 0) return;

                // Close popup
                if (event.target.classList.contains('close-popup')) {
                    event.stopPropagation();
                    const popup = event.target.closest('.subscribe-popup');
                    if (popup) {
                        popup.remove();
                        overlay.style.display = 'none';
                    }
                    sessionStorage.removeItem('popupShown');
                }

                // Follow Instagram
                if (event.target.classList.contains('follow-instagram')) {
                    window.open(newsletter_popup_vars.instagram_url, '_blank');
                }
            }, true);

            // Handle popup form submission
            document.body.addEventListener('submit', function(event) {
                if (event.target.closest('.subscribe-popup form')) {
                    event.preventDefault();

                    const form = event.target;
                    const emailInput = form.querySelector('input[name="email"]');
                    const submitButton = form.querySelector('.subscribe-button');

                    if (!emailInput || !submitButton) return;

                    // Disable button
                    submitButton.disabled = true;
                    const originalText = submitButton.textContent;
                    submitButton.textContent = 'Subscribing...';

                    const formData = new FormData();
                    formData.append('action', 'submit_newsletter_popup_form');
                    formData.append('email', emailInput.value);
                    formData.append('nonce', newsletter_popup_vars.nonce);

                    fetch(newsletter_popup_vars.ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            createPopup(
                                'Thank you for subscribing! Stay tuned for updates.',
                                '', '', 'subscribe-popup', true
                            );

                            // Update localStorage
                            localStorage.setItem('subscribed', 'true');
                            localStorage.setItem('lastSubscribedTime', Date.now().toString());
                        } else {
                            alert('Error: ' + (data.data || 'Subscription failed'));
                        }
                    })
                    .catch(error => {
                        console.error('Newsletter popup error:', error);
                        alert('Error: Network problem. Please try again.');
                    })
                    .finally(() => {
                        // Reset button
                        submitButton.disabled = false;
                        submitButton.textContent = originalText;
                    });
                }
            });
        }

        // Initialize popup system
        initPopupInteractions();
        handleScrollAndPopup();
    }

    /**
     * Initialize popup when DOM is ready
     */
    function initPopup() {
        // Check if popup should be initialized on this page
        const shouldShowPopup = !document.body.classList.contains('home') &&
                               !document.body.classList.contains('page-template-contact') &&
                               !document.body.classList.contains('post-type-archive-festival_wire');

        if (shouldShowPopup) {
            initNewsletterPopup();
        }

        console.log('ExtraChill Newsletter Popup initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPopup);
    } else {
        initPopup();
    }

    // Mark as initialized to prevent double loading
    window.ExtraChillNewsletterPopup = {
        version: '1.0.1',
        initialized: true
    };

})(jQuery || function(selector) {
    // Fallback if jQuery is not available
    return document.querySelector(selector);
});