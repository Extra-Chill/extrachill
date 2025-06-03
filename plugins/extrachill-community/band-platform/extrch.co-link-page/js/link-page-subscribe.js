/**
 * Handles frontend behavior for the Band Link Page Subscription feature.
 * This includes opening the modal (if applicable) and submitting the subscribe forms via AJAX.
 */

document.addEventListener('DOMContentLoaded', function() {
    const subscribeModal = document.getElementById('extrch-subscribe-modal');
    const subscribeIconTrigger = document.querySelector('.extrch-subscribe-icon-trigger'); // Button to open modal
    const modalCloseButton = subscribeModal ? subscribeModal.querySelector('.extrch-subscribe-modal-close') : null;
    const modalOverlay = subscribeModal ? subscribeModal.querySelector('.extrch-subscribe-modal-overlay') : null;
    const modalForm = subscribeModal ? subscribeModal.querySelector('#extrch-subscribe-form-modal') : null;

    const inlineForm = document.getElementById('extrch-subscribe-form-inline');

    // --- Modal Logic (if icon_modal display mode is active) ---
    if (subscribeModal && subscribeIconTrigger) {
        // Open modal when icon is clicked
        subscribeIconTrigger.addEventListener('click', function() {
            subscribeModal.style.display = 'flex';
            // Optional: Add a class to the body or html to prevent scrolling
            document.body.classList.add('extrch-modal-open');
        });

        // Close modal when close button or overlay is clicked
        if (modalCloseButton) {
            modalCloseButton.addEventListener('click', function() {
                closeSubscribeModal();
            });
        }
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function() {
                closeSubscribeModal();
            });
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSubscribeModal();
            }
        });

        function closeSubscribeModal() {
            if (subscribeModal) {
                subscribeModal.style.display = 'none';
                document.body.classList.remove('extrch-modal-open');
                 // Clear form and messages on close
                if (modalForm) {
                    modalForm.reset();
                    const formMessage = modalForm.querySelector('.extrch-form-message');
                     if(formMessage) formMessage.textContent = '';
                }
            }
        }
    }

    // --- Form Submission Logic (for both modal and inline forms) ---
    const handleFormSubmission = function(event) {
        event.preventDefault();

        const form = event.target;
        const submitButton = form.querySelector('button[type="submit"]');
        const formMessage = form.querySelector('.extrch-form-message');
        const formData = new FormData(form);

        // Basic client-side validation
        const emailInput = form.querySelector('input[type="email"]');
        if (emailInput && !emailInput.value) {
            if(formMessage) {
                formMessage.textContent = 'Please enter your email address.';
                formMessage.style.color = 'red';
            }
            return;
        }
        // More robust email format validation would typically happen server-side

        // Disable button and show loading indicator
        if(submitButton) {
             submitButton.disabled = true;
             submitButton.textContent = 'Subscribing...'; // Or use a spinner
        }
        if(formMessage) {
            formMessage.textContent = ''; // Clear previous messages
            formMessage.style.color = '';
        }


        // Perform AJAX submission
        fetch(ajaxurl, {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if(formMessage) {
                    formMessage.textContent = data.data.message || 'Success!';
                    formMessage.style.color = 'green';
                }
                form.reset(); // Clear the form on success
                // If it's the modal form, maybe close the modal after a short delay
                if (form.id === 'extrch-subscribe-form-modal') {
                    setTimeout(closeSubscribeModal, 2000); // Close after 2 seconds
                }
            } else {
                if(formMessage) {
                    formMessage.textContent = data.data.message || 'An error occurred.';
                    formMessage.style.color = 'red';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if(formMessage) {
                formMessage.textContent = 'An unexpected error occurred.';
                formMessage.style.color = 'red';
            }
        })
        .finally(() => {
            // Re-enable button
            if(submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Subscribe'; // Restore original text
            }
        });
    };

    // Attach event listener to modal form (if it exists)
    if (modalForm) {
        modalForm.addEventListener('submit', handleFormSubmission);
    }

    // Attach event listener to inline form (if it exists)
    if (inlineForm) {
        inlineForm.addEventListener('submit', handleFormSubmission);
    }
}); 