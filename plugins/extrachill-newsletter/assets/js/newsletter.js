/**
 * Newsletter Form Handlers
 *
 * AJAX subscription form handling and validation for all newsletter forms.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

(function($) {
    'use strict';

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
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    function initFestivalTipForm() {
        const form = document.getElementById('festival-wire-tip-form');
        const messageDiv = form?.querySelector('.festival-wire-tip-message');
        const submitButton = form?.querySelector('.festival-wire-tip-submit');
        const textarea = document.getElementById('festival-wire-tip-content');
        const charCount = document.getElementById('char-count');

        if (!form) {
            return; // Form not present on this page
        }

        // Character counter functionality
        if (textarea && charCount) {
            textarea.addEventListener('input', function() {
                const currentLength = this.value.length;
                charCount.textContent = currentLength;

                // Change color when approaching limit
                const parent = charCount.parentElement;
                if (currentLength > 900) {
                    parent.style.color = '#d32f2f';
                } else if (currentLength > 800) {
                    parent.style.color = '#ff9800';
                } else {
                    parent.style.color = '#666';
                }
            });
        }

        // Form submission
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (submitButton.disabled) {
                return;
            }

            // Get form data
            const content = document.getElementById('festival-wire-tip-content')?.value;
            const email = document.getElementById('festival-wire-tip-email')?.value;
            const isCommunityMember = form.dataset.communityMember === 'true';

            // Basic validation
            if (!content || content.trim().length < 10) {
                showTipMessage('Please provide a more detailed tip (at least 10 characters).', 'error');
                return;
            }

            if (!isCommunityMember && (!email || !isValidEmail(email))) {
                showTipMessage('Please enter a valid email address.', 'error');
                return;
            }

            // Get Turnstile response if present
            const turnstileResponse = window.turnstile ? window.turnstile.getResponse() : '';

            // Prepare data for AJAX
            const formData = new FormData();
            formData.append('action', 'newsletter_festival_wire_tip_submission');
            formData.append('content', content);
            if (!isCommunityMember) {
                formData.append('email', email);
            }
            formData.append('cf-turnstile-response', turnstileResponse);

            // Add nonce if present
            const nonceField = form.querySelector('input[name="newsletter_festival_tip_nonce_field"]');
            if (nonceField) {
                formData.append('newsletter_festival_tip_nonce_field', nonceField.value);
            }

            // Disable submit button and show loading state
            submitButton.disabled = true;
            const originalText = submitButton.textContent;
            submitButton.textContent = 'Submitting...';
            messageDiv.innerHTML = '';

            // Submit via AJAX
            fetch(newsletterParams?.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showTipMessage(data.data.message || 'Thank you for your tip!', 'success');

                    // Reset form
                    form.reset();

                    // Reset character counter
                    if (charCount) {
                        charCount.textContent = '0';
                        charCount.parentElement.style.color = '#666';
                    }

                    // Reset turnstile if it exists
                    if (window.turnstile) {
                        window.turnstile.reset();
                    }
                } else {
                    showTipMessage(data.data.message || 'There was an error submitting your tip. Please try again.', 'error');
                }
            })
            .catch(error => {
                console.error('Festival tip submission error:', error);
                showTipMessage('There was a network error. Please try again.', 'error');
            })
            .finally(() => {
                // Re-enable submit button
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            });
        });

        function showTipMessage(message, type) {
            if (messageDiv) {
                messageDiv.innerHTML = `<div class="form-status ${type}">${message}</div>`;
                messageDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    }

    function initNewsletter() {
        // Initialize form handlers
        initArchiveForm();
        initHomepageForm();
        initNavigationForm();
        initContentForm();
        initFooterForm();
        initShortcodeForms();
        initFestivalTipForm();
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