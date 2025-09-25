/**
 * ExtraChill Newsletter Plugin JavaScript
 *
 * Handles all newsletter subscription forms, popup functionality,
 * and AJAX interactions throughout the site.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Newsletter Archive Form Handler
     *
     * Handles subscription form on newsletter archive pages
     */
    function initArchiveForm() {
        const archiveForm = document.getElementById('newsletterArchiveForm');
        if (!archiveForm) return;

        archiveForm.addEventListener('submit', function(event) {
            event.preventDefault();

            const emailInput = archiveForm.querySelector('input[name="email"]');
            const submitButton = archiveForm.querySelector('button[type="submit"]');
            const nonceField = archiveForm.querySelector('input[name="newsletter_nonce_field"]');

            if (!emailInput || !submitButton || !nonceField) return;

            // Disable button and show loading state
            const originalButtonText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'submit_newsletter_form');
            formData.append('email', emailInput.value);
            formData.append('nonce', nonceField.value);

            // Send AJAX request
            fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Subscription successful! Check your email.');
                    emailInput.value = '';

                    // Update localStorage to coordinate with popup script
                    if (window.localStorage) {
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', Date.now().toString());
                    }
                } else {
                    alert('Error: ' + (data.data || 'Subscription failed'));
                }
            })
            .catch(error => {
                console.error('Newsletter subscription error:', error);
                alert('Error: Network problem. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
        });
    }

    /**
     * Homepage Newsletter Form Handler
     *
     * Handles the newsletter form on the homepage
     */
    function initHomepageForm() {
        const homepageForm = document.getElementById('homepageNewsletterForm');
        if (!homepageForm) return;

        const emailInput = document.getElementById('newsletter-email-home');
        const feedback = document.querySelector('.newsletter-feedback');
        const submitButton = homepageForm.querySelector('button[type="submit"]');
        const nonceField = document.getElementById('subscribe_to_sendy_home_nonce_field');

        if (!emailInput || !feedback || !submitButton) return;

        homepageForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Disable button and show loading state
            const originalButtonText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';
            feedback.style.display = 'none';

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'subscribe_to_sendy_home');
            formData.append('email', emailInput.value);
            formData.append('nonce', nonceField ? nonceField.value : '');

            // Use localized AJAX URL from plugin
            const ajaxUrl = newsletterParams?.ajaxurl ||
                           '/wp-admin/admin-ajax.php';

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                feedback.style.display = 'block';

                if (data.success) {
                    feedback.textContent = data.data || 'Successfully subscribed!';
                    feedback.style.color = '#28a745';
                    feedback.className = 'newsletter-feedback success';
                    emailInput.value = '';

                    // Update localStorage for popup coordination
                    if (window.localStorage) {
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', Date.now().toString());
                    }
                } else {
                    feedback.textContent = data.data || 'Subscription failed. Please try again.';
                    feedback.style.color = '#dc3545';
                    feedback.className = 'newsletter-feedback error';
                }
            })
            .catch(error => {
                console.error('Newsletter subscription error:', error);
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.style.color = '#dc3545';
                feedback.className = 'newsletter-feedback error';
            })
            .finally(() => {
                // Reset button state
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
        });
    }

    /**
     * Navigation Menu Newsletter Form Handler
     *
     * Handles newsletter subscription in navigation menu
     */
    function initNavigationForm() {
        const navForm = document.querySelector('.newsletter-form');
        if (!navForm) return;

        const emailInput = document.querySelector('#newsletter-email-nav');
        const submitButton = navForm.querySelector('button[type="submit"]');
        const nonceField = navForm.querySelector('input[name="subscribe_nonce"]');

        if (!emailInput || !submitButton) return;

        // Create feedback element
        let feedback = navForm.querySelector('.newsletter-feedback');
        if (!feedback) {
            feedback = document.createElement('p');
            feedback.className = 'newsletter-feedback';
            feedback.style.display = 'none';
            navForm.appendChild(feedback);
        }

        navForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Disable submit button
            const originalButtonText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';
            feedback.style.display = 'none';

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'subscribe_to_sendy');
            formData.append('email', emailInput.value);
            formData.append('subscribe_nonce', nonceField ? nonceField.value : '');

            fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                feedback.style.display = 'block';

                if (data.success) {
                    feedback.textContent = data.data || 'Successfully subscribed!';
                    feedback.style.color = '#28a745';
                    emailInput.value = '';

                    // Update localStorage
                    if (window.localStorage) {
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', Date.now().toString());
                    }
                } else {
                    feedback.textContent = data.data || 'Subscription failed. Please try again.';
                    feedback.style.color = '#dc3545';
                }
            })
            .catch(error => {
                console.error('Newsletter subscription error:', error);
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.style.color = '#dc3545';
            })
            .finally(() => {
                // Reset button
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
        });
    }

    /**
     * Content Newsletter Form Handler
     *
     * Handles subscription form that appears after post content
     */
    function initContentForm() {
        const contentForm = document.getElementById('contentNewsletterForm');
        if (!contentForm) return;

        const emailInput = contentForm.querySelector('input[name="email"]');
        const submitButton = contentForm.querySelector('button[type="submit"]');
        const nonceField = contentForm.querySelector('input[name="nonce"]');
        const feedback = contentForm.parentNode.querySelector('.newsletter-feedback');

        if (!emailInput || !submitButton || !nonceField || !feedback) return;

        contentForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Disable submit button
            const originalButtonText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';
            feedback.style.display = 'none';

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'submit_newsletter_content_form');
            formData.append('email', emailInput.value);
            formData.append('nonce', nonceField.value);

            fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                feedback.style.display = 'block';

                if (data.success) {
                    feedback.textContent = data.data || 'Successfully subscribed!';
                    feedback.style.color = '#28a745';
                    emailInput.value = '';

                    // Update localStorage
                    if (window.localStorage) {
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', Date.now().toString());
                    }
                } else {
                    feedback.textContent = data.data || 'Subscription failed. Please try again.';
                    feedback.style.color = '#dc3545';
                }
            })
            .catch(error => {
                console.error('Newsletter subscription error:', error);
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.style.color = '#dc3545';
            })
            .finally(() => {
                // Reset button
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
        });
    }

    /**
     * Footer Newsletter Form Handler
     *
     * Handles subscription form that appears above the footer
     */
    function initFooterForm() {
        const footerForm = document.getElementById('footerNewsletterForm');
        if (!footerForm) return;

        const emailInput = footerForm.querySelector('input[name="email"]');
        const submitButton = footerForm.querySelector('button[type="submit"]');
        const nonceField = footerForm.querySelector('input[name="nonce"]');
        const feedback = footerForm.parentNode.querySelector('.newsletter-feedback');

        if (!emailInput || !submitButton || !nonceField || !feedback) return;

        footerForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Disable submit button
            const originalButtonText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';
            feedback.style.display = 'none';

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'submit_newsletter_footer_form');
            formData.append('email', emailInput.value);
            formData.append('nonce', nonceField.value);

            fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                feedback.style.display = 'block';

                if (data.success) {
                    feedback.textContent = data.data || 'Successfully subscribed!';
                    feedback.style.color = '#28a745';
                    emailInput.value = '';

                    // Update localStorage
                    if (window.localStorage) {
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', Date.now().toString());
                    }
                } else {
                    feedback.textContent = data.data || 'Subscription failed. Please try again.';
                    feedback.style.color = '#dc3545';
                }
            })
            .catch(error => {
                console.error('Newsletter subscription error:', error);
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.style.color = '#dc3545';
            })
            .finally(() => {
                // Reset button
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            });
        });
    }

    /**
     * Newsletter Popup System
     *
     * Creates and manages newsletter subscription popup
     */
    function initNewsletterPopup() {
        // Don't show popup if user has already subscribed recently or has session token
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
            const closeButtonText = localStorage.getItem('subscribed') === 'true' ? "Close" : "Sorry, I'm Not That Chill";

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
                    linkHTML = `<p style="text-align:center;"><a href="/newsletters" target="_blank">See past Newsletters</a></p>`;
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
                                'Independent music journalism with personality! Enter your email for a good time.',
                                'Enter your email',
                                'Subscribe',
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
                    window.open('https://www.instagram.com/extrachill', '_blank');
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
                    formData.append('nonce', newsletter_vars?.nonce || '');

                    fetch(newsletter_vars?.ajaxurl || '/wp-admin/admin-ajax.php', {
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
     * Initialize shortcode forms
     *
     * Handles newsletter subscription forms created by shortcodes
     */
    function initShortcodeForms() {
        const shortcodeForms = document.querySelectorAll('.newsletter-shortcode-form');

        shortcodeForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const emailInput = form.querySelector('input[name="email"]');
                const submitButton = form.querySelector('.newsletter-submit-button');
                const feedback = form.querySelector('.newsletter-form-feedback');
                const nonceField = form.querySelector('input[name="newsletter_nonce_field"]');
                const listField = form.querySelector('input[name="list"]');

                if (!emailInput || !submitButton || !feedback) return;

                // Disable button and show loading state
                const originalText = submitButton.textContent;
                submitButton.disabled = true;
                submitButton.textContent = 'Subscribing...';
                feedback.style.display = 'none';

                // Prepare form data
                const formData = new FormData();
                formData.append('action', 'submit_newsletter_shortcode_form');
                formData.append('email', emailInput.value);
                formData.append('list', listField ? listField.value : 'archive');
                formData.append('newsletter_nonce_field', nonceField ? nonceField.value : '');

                fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    feedback.style.display = 'block';

                    if (data.success) {
                        feedback.textContent = data.data || 'Successfully subscribed!';
                        feedback.className = 'newsletter-form-feedback success';
                        emailInput.value = '';
                    } else {
                        feedback.textContent = data.data || 'Subscription failed. Please try again.';
                        feedback.className = 'newsletter-form-feedback error';
                    }
                })
                .catch(error => {
                    console.error('Newsletter shortcode error:', error);
                    feedback.style.display = 'block';
                    feedback.textContent = 'An error occurred. Please try again.';
                    feedback.className = 'newsletter-form-feedback error';
                })
                .finally(() => {
                    // Reset button
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                });
            });
        });
    }

    /**
     * Utility function to validate email addresses
     */
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    /**
     * Initialize all newsletter functionality when DOM is ready
     */
    function initNewsletter() {
        // Initialize form handlers
        initArchiveForm();
        initHomepageForm();
        initNavigationForm();
        initContentForm();
        initFooterForm();
        initShortcodeForms();

        // Initialize popup system (only on appropriate pages)
        // Note: Logged-in user filtering handled server-side via enqueue_newsletter_popup_scripts()
        const shouldShowPopup = !document.body.classList.contains('home') &&
                               !document.body.classList.contains('page-template-contact') &&
                               !document.body.classList.contains('post-type-archive-festival_wire');

        if (shouldShowPopup) {
            initNewsletterPopup();
        }

        // Add form validation to all newsletter forms
        const newsletterForms = document.querySelectorAll('.newsletter-form, .newsletter-shortcode-form');
        newsletterForms.forEach(form => {
            const emailInput = form.querySelector('input[type="email"], input[name="email"]');
            if (emailInput) {
                emailInput.addEventListener('blur', function() {
                    if (this.value && !isValidEmail(this.value)) {
                        this.setCustomValidity('Please enter a valid email address');
                        this.style.borderColor = '#dc3545';
                    } else {
                        this.setCustomValidity('');
                        this.style.borderColor = '';
                    }
                });

                emailInput.addEventListener('input', function() {
                    if (this.style.borderColor === 'rgb(220, 53, 69)') {
                        this.style.borderColor = '';
                    }
                });
            }
        });

        console.log('ExtraChill Newsletter plugin initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNewsletter);
    } else {
        initNewsletter();
    }

    // Expose utility functions globally if needed
    window.ExtraChillNewsletter = {
        isValidEmail: isValidEmail,
        version: '1.0.0'
    };

})(jQuery || function(selector) {
    // Fallback if jQuery is not available
    return document.querySelector(selector);
});