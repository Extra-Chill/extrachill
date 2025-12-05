/**
 * Handles frontend behavior for the Band Link Page Subscription feature.
 * Uses REST API for form submission.
 */

document.addEventListener('DOMContentLoaded', function() {
    const subscribeModal = document.getElementById('extrch-subscribe-modal');
    const subscribeIconTrigger = document.querySelector('.extrch-subscribe-icon-trigger');
    const modalCloseButton = subscribeModal ? subscribeModal.querySelector('.extrch-subscribe-modal-close') : null;
    const modalOverlay = subscribeModal ? subscribeModal.querySelector('.extrch-subscribe-modal-overlay') : null;
    const modalForm = subscribeModal ? subscribeModal.querySelector('#extrch-subscribe-form-modal') : null;
    const inlineForm = document.getElementById('extrch-subscribe-form-inline');

    function closeSubscribeModal() {
        if (subscribeModal) {
            subscribeModal.classList.add('extrch-modal-hidden');
            document.body.classList.remove('extrch-modal-open');
            if (modalForm) {
                modalForm.reset();
                const formMessage = modalForm.querySelector('.extrch-form-message');
                if (formMessage) formMessage.textContent = '';
            }
        }
    }

    if (subscribeModal && subscribeIconTrigger) {
        subscribeIconTrigger.addEventListener('click', function() {
            subscribeModal.classList.remove('extrch-modal-hidden');
            document.body.classList.add('extrch-modal-open');
        });

        if (modalCloseButton) {
            modalCloseButton.addEventListener('click', closeSubscribeModal);
        }
        if (modalOverlay) {
            modalOverlay.addEventListener('click', closeSubscribeModal);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSubscribeModal();
            }
        });
    }

    function handleFormSubmission(event) {
        event.preventDefault();

        const form = event.target;
        const submitButton = form.querySelector('button[type="submit"]');
        const formMessage = form.querySelector('.extrch-form-message');
        const emailInput = form.querySelector('input[type="email"]');
        const artistId = form.dataset.artistId;

        if (!emailInput || !emailInput.value) {
            if (formMessage) {
                formMessage.textContent = 'Please enter your email address.';
                formMessage.style.color = 'red';
            }
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Subscribing...';
        }
        if (formMessage) {
            formMessage.textContent = '';
            formMessage.style.color = '';
        }

        fetch(extrchSubscribeData.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                artist_id: parseInt(artistId, 10),
                email: emailInput.value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (formMessage) {
                    formMessage.textContent = data.message || 'Success!';
                    formMessage.style.color = 'green';
                }
                form.reset();
                if (form.id === 'extrch-subscribe-form-modal') {
                    setTimeout(closeSubscribeModal, 2000);
                }
            } else {
                if (formMessage) {
                    formMessage.textContent = data.message || 'An error occurred.';
                    formMessage.style.color = 'red';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (formMessage) {
                formMessage.textContent = 'An unexpected error occurred.';
                formMessage.style.color = 'red';
            }
        })
        .finally(() => {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Subscribe';
            }
        });
    }

    if (modalForm) {
        modalForm.addEventListener('submit', handleFormSubmission);
    }

    if (inlineForm) {
        inlineForm.addEventListener('submit', handleFormSubmission);
    }
});
