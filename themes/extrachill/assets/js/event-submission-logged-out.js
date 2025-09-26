document.addEventListener('DOMContentLoaded', function () {
    // Scope queries to the events bar container
    const eventsBar = document.querySelector('[data-js="tribe-events-events-bar"]');
    if (!eventsBar) {
        console.warn('Events bar not found. Modal script (logged-out) will not run.');
        return;
    }

    // Query modal elements (only need login prompt container for logged-out users)
    const eventSubmissionModal = eventsBar.querySelector('#event-submission-modal');
    const eventLoginPromptContainer = eventSubmissionModal ? eventSubmissionModal.querySelector('#event-login-prompt-container') : null;

    if (!eventSubmissionModal || !eventLoginPromptContainer) {
        console.error('Modal elements for logged-out users are missing.');
        return;
    }


    /**
     * Update modal content for logged-out users (show login prompt).
     */
    function updateModalContentLoggedOut() {
        eventSubmissionModal.querySelector('#event-submission-form-container').style.display = 'none'; // Hide form container
        eventLoginPromptContainer.style.display = 'block'; // Show login prompt
    }

    const addEventButton = eventsBar.querySelector('#add-event-btn');
    if (addEventButton) {
        addEventButton.addEventListener('click', function () {
            console.log('Add event button clicked (logged-out user)');
            eventSubmissionModal.style.display = 'flex';
            document.body.classList.add('no-scroll');
            updateModalContentLoggedOut(); // Show login prompt for logged-out users
        });
    }


    // Close modal (same logic as in main script)
    const closeModalButton = eventSubmissionModal ? eventSubmissionModal.querySelector('#close-modal') : null;
    const cancelModalButton = eventSubmissionModal ? eventSubmissionModal.querySelector('#cancel-modal') : null;


    function closeModal() {
        eventSubmissionModal.style.display = 'none';
        document.body.classList.remove('no-scroll');
    }

    if (closeModalButton) {
        closeModalButton.addEventListener('click', closeModal);
    }
    if (cancelModalButton) {
        cancelModalButton.addEventListener('click', closeModal);
    }


    eventSubmissionModal.addEventListener('click', function (event) {
        if (event.target === eventSubmissionModal) closeModal();
    });

});