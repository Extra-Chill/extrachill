console.log('Event submission modal script loaded.');

document.addEventListener('DOMContentLoaded', function () {
    // Scope queries to the events bar container
    const eventsBar = document.querySelector('[data-js="tribe-events-events-bar"]');
    if (!eventsBar) {
        console.warn('Events bar not found. Modal script will not run.');
        return;
    }

    // Query modal elements (only need form container for logged-in users)
    const addEventButton = eventsBar.querySelector('#add-event-btn');
    const eventSubmissionModal = eventsBar.querySelector('#event-submission-modal');
    const closeModalButton = eventSubmissionModal ? eventSubmissionModal.querySelector('#close-modal') : null;
    const cancelModalButton = eventSubmissionModal ? eventSubmissionModal.querySelector('#cancel-modal') : null;
    const eventSubmissionFormContainer = eventSubmissionModal ? eventSubmissionModal.querySelector('#event-submission-form-container') : null;

    if (!addEventButton || !eventSubmissionModal || !closeModalButton || !cancelModalButton || !eventSubmissionFormContainer) {
        console.error('Modal elements are missing (logged-in version).');
        return;
    }

    // Location autocomplete elements
    const locationInput = eventSubmissionModal.querySelector('#event-location');
    const locationSuggestions = eventSubmissionModal.querySelector('#location-suggestions');

    // Venue autocomplete: ensure suggestions container exists
    const venueInput = eventSubmissionModal.querySelector('#event-venue');
    let venueSuggestions = eventSubmissionModal.querySelector('#venue-suggestions');
    if (!venueSuggestions) {
        venueSuggestions = document.createElement('ul');
        venueSuggestions.id = 'venue-suggestions';
        venueSuggestions.className = 'suggestions';
        venueInput.parentNode.insertBefore(venueSuggestions, venueInput.nextSibling);
    }

    // Extra venue fields container (for new venue creation)
    const newVenueFields = document.getElementById('new-venue-fields');

    /**
     * Update modal content (for logged-in users - just show the form).
     */
    function updateModalContent() {
        eventSubmissionFormContainer.style.display = 'block'; // Show form container
    }

    addEventButton.addEventListener('click', function () {
        console.log('Add event button clicked (logged-in user)');
        eventSubmissionModal.style.display = 'flex';
        document.body.classList.add('no-scroll');
        updateModalContent(); // Show event submission form
    });

    // Close modal (same logic as before)
    function closeModal() {
        eventSubmissionModal.style.display = 'none';
        document.body.classList.remove('no-scroll');
    }
    closeModalButton.addEventListener('click', closeModal);
    cancelModalButton.addEventListener('click', closeModal);
    eventSubmissionModal.addEventListener('click', function (event) {
        if (event.target === eventSubmissionModal) closeModal();
    });

    // --- Location Autocomplete ---
    if (locationInput && locationSuggestions) {
        let currentFocus = -1;
        let suggestionItems = [];
        let lastQuery = '';

        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), delay);
            };
        }

        const fetchLocationSuggestions = debounce(function () {
            const searchTerm = locationInput.value.trim();
            lastQuery = searchTerm;
            locationSuggestions.innerHTML = '';
            currentFocus = -1;
            if (searchTerm.length < 3) {
                locationSuggestions.style.display = 'none';
                return;
            }
            fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_location_suggestions&nonce=' + eventSubmissionModalData.locationFilterNonce + '&term=' + encodeURIComponent(searchTerm)
            })
            .then(response => response.json())
            .then(data => {
                if (locationInput.value.trim() !== lastQuery) return;
                if (data.success && data.data.locations.length > 0) {
                    data.data.locations.forEach(location => {
                        const li = document.createElement('li');
                        li.textContent = location.name;
                        li.setAttribute('data-id', location.id);
                        li.setAttribute('data-slug', location.slug);
                        li.addEventListener('click', function () {
                            locationInput.value = location.name;
                            locationInput.setAttribute('data-location-id', location.id);
                            locationInput.setAttribute('data-location-slug', location.slug);
                            locationSuggestions.innerHTML = '';
                            locationSuggestions.style.display = 'none';
                            currentFocus = -1;
                        });
                        locationSuggestions.appendChild(li);
                    });
                    locationSuggestions.style.display = 'block';
                    suggestionItems = Array.from(locationSuggestions.querySelectorAll('li'));
                } else {
                    locationSuggestions.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error fetching location suggestions:', error);
                locationSuggestions.style.display = 'none';
            });
        }, 300);
        locationInput.addEventListener('input', fetchLocationSuggestions);
        locationInput.addEventListener('keydown', function (e) {
            suggestionItems = Array.from(locationSuggestions.querySelectorAll('li'));
            if (!suggestionItems.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentFocus++;
                addActive(suggestionItems);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentFocus--;
                addActive(suggestionItems);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentFocus > -1 && suggestionItems[currentFocus]) {
                    suggestionItems[currentFocus].click();
                }
            }
        });
        function addActive(items) {
            if (!items) return;
            removeActive(items);
            if (currentFocus >= items.length) currentFocus = 0;
            if (currentFocus < 0) currentFocus = items.length - 1;
            items[currentFocus].classList.add('autocomplete-active');
        }
        function removeActive(items) {
            items.forEach(item => item.classList.remove('autocomplete-active'));
        }
        locationInput.addEventListener('blur', function () {
            setTimeout(() => { locationSuggestions.style.display = 'none'; }, 200);
        });
    }

    // --- Venue Autocomplete ---
    if (venueInput && venueSuggestions) {
        let venueFocus = -1;
        let venueItems = [];
        let lastVenueQuery = '';

        function venueDebounce(func, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), delay);
            };
        }

        const fetchVenueSuggestions = venueDebounce(function () {
            const searchTerm = venueInput.value.trim();
            lastVenueQuery = searchTerm;
            venueSuggestions.innerHTML = '';
            venueFocus = -1;
            if (searchTerm.length < 3) {
                venueSuggestions.style.display = 'none';
                // Reveal extra fields if user types enough but no suggestions are returned
                newVenueFields.style.display = 'block';
                return;
            }
            // Assume you localized a nonce for venue suggestions as "eventSubmissionModalData.venueNonce"
            fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_venue_suggestions&nonce=' + eventSubmissionModalData.venueNonce + '&term=' + encodeURIComponent(searchTerm)
            })
            .then(response => response.json())
            .then(data => {
                if (venueInput.value.trim() !== lastVenueQuery) return;
                if (data.success && data.data.venues.length > 0) {
                    data.data.venues.forEach(venue => {
                        const li = document.createElement('li');
                        li.textContent = venue.name;
                        li.setAttribute('data-id', venue.id);
                        li.addEventListener('click', function () {
                            venueInput.value = venue.name;
                            venueInput.setAttribute('data-venue-id', venue.id);
                            // Also set a hidden field for the venue ID if not already present
                            let hiddenVenueField = eventSubmissionModal.querySelector('#event-venue-id');
                            if (!hiddenVenueField) {
                                hiddenVenueField = document.createElement('input');
                                hiddenVenueField.type = 'hidden';
                                hiddenVenueField.id = 'event-venue-id';
                                hiddenVenueField.name = 'event-venue-id';
                                venueInput.parentNode.insertBefore(hiddenVenueField, venueInput.nextSibling);
                            }
                            hiddenVenueField.value = venue.id;
                            venueSuggestions.innerHTML = '';
                            venueSuggestions.style.display = 'none';
                            venueFocus = -1;
                            // Hide extra venue fields since an existing venue was chosen
                            newVenueFields.style.display = 'none';
                        });
                        venueSuggestions.appendChild(li);
                    });
                    venueSuggestions.style.display = 'block';
                    venueItems = Array.from(venueSuggestions.querySelectorAll('li'));
                    // Hide extra fields if suggestions are found
                    newVenueFields.style.display = 'none';
                } else {
                    // No matching venues: reveal extra fields for new venue creation.
                    newVenueFields.style.display = 'block';
                    venueSuggestions.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error fetching venue suggestions:', error);
                venueSuggestions.style.display = 'none';
            });
        }, 300);
        // New event listener: clear any previous venue ID when user types
        venueInput.addEventListener('input', function () {
            // Clear previously set venue id and hidden field value
            venueInput.removeAttribute('data-venue-id');
            let hiddenVenueField = eventSubmissionModal.querySelector('#event-venue-id');
            if (hiddenVenueField) {
                hiddenVenueField.value = '';
            }
            fetchVenueSuggestions();
        });
        venueInput.addEventListener('keydown', function (e) {
            venueItems = Array.from(venueSuggestions.querySelectorAll('li'));
            if (!venueItems.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                venueFocus++;
                addVenueActive(venueItems);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                venueFocus--;
                addVenueActive(venueItems);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (venueFocus > -1 && venueItems[venueFocus]) {
                    venueItems[venueFocus].click();
                }
            }
        });
        function addVenueActive(items) {
            if (!items) return;
            removeVenueActive(items);
            if (venueFocus >= items.length) venueFocus = 0;
            if (venueFocus < 0) venueFocus = items.length - 1;
            items[venueFocus].classList.add('autocomplete-active');
        }
        function removeVenueActive(items) {
            items.forEach(item => item.classList.remove('autocomplete-active'));
        }
        venueInput.addEventListener('blur', function () {
            setTimeout(() => { venueSuggestions.style.display = 'none'; }, 200);
        });
    }

    // --- Form Submission Handler ---
    const eventSubmissionForm = document.getElementById('event-submission-form');
    if (eventSubmissionForm) {
        eventSubmissionForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission

            const formData = new FormData(this);
            const venueInput = this.querySelector('#event-venue');
            const newVenueFields = document.getElementById('new-venue-fields');
            const errorMessage = eventSubmissionModal.querySelector('#event-submission-error-message');
            const processingMessage = eventSubmissionModal.querySelector('#event-submission-processing-message');
            const submitButton = this.querySelector('.submit-event-btn');

            // Venue validation: Check if venue is selected or new venue name entered
            let isVenueValid = false;
            if (venueInput.hasAttribute('data-venue-id')) {
                isVenueValid = true;
            } else if (newVenueFields.style.display === 'block') {
                if (venueInput.value.trim() !== '') {
                    isVenueValid = true;
                }
            }

            if (!isVenueValid) {
                errorMessage.textContent = 'Please select an existing venue or enter a new venue name.';
                errorMessage.style.display = 'block';
                e.preventDefault(); // Prevent form submission
                return; // Stop further processing
            }

            // Hide previous messages and show processing indicator
            if (errorMessage) errorMessage.style.display = 'none';
            const successMessage = eventSubmissionModal.querySelector('#event-submission-success-message');
            if (successMessage) successMessage.style.display = 'none';
            processingMessage.style.display = 'block';

            // Disable submit button to prevent multiple submissions
            submitButton.disabled = true;

            // --- Submit Event (Handles both new and existing venues in PHP) ---
            const xhr = new XMLHttpRequest();
            xhr.open('POST', eventSubmissionModalData.ajaxUrl, true); // Use eventSubmissionModalData.ajaxUrl - CORRECT
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function () {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        console.log('Event submitted successfully:', response); // CORRECTED: Use response instead of data
                        const successMessage = eventSubmissionModal.querySelector('#event-submission-success-message');
                        successMessage.textContent = 'Event submitted successfully!';
                        successMessage.style.display = 'block';
                    } else {
                        const errorMessage = eventSubmissionModal.querySelector('#event-submission-error-message');
                        errorMessage.textContent = 'Submission error: ' + response.message;
                        errorMessage.style.display = 'block';
                    }
                } else {
                    console.error('AJAX request failed:', xhr.statusText);
                    const errorMessage = eventSubmissionModal.querySelector('#event-submission-error-message');
                    errorMessage.textContent = 'An unexpected error occurred.'; // Generic error message
                    errorMessage.style.display = 'block';
                }
            };

            const params = new URLSearchParams({
                action: 'submit_event', // CORRECT AJAX ACTION: submit_event
                nonce: eventSubmissionModalData.submissionNonce,
                ...Object.fromEntries(formData.entries()) // Convert FormData to object and merge
            });

            xhr.send(params.toString());
            // --- END Submit Event ---


        });
    }
});
