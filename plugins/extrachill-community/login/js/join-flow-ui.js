document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const fromJoinFlow = urlParams.get('from_join');

    // Get references to modal elements
    const modalOverlay = document.getElementById('join-flow-modal-overlay');
    const modalContent = document.getElementById('join-flow-modal-content');
    const existingAccountButton = document.getElementById('join-flow-existing-account');
    const newAccountButton = document.getElementById('join-flow-new-account');

    // Notices are now handled by PHP based on the 'from_join' flag.
    // Remove references to notice elements from JS.
    // const noticeLogin = document.getElementById('join-flow-notice-login');
    // const noticeRegister = document.getElementById('join-flow-notice-register');

    if (fromJoinFlow === 'true') {
        // If arriving from the join flow, show the modal
        showJoinFlowModal();
        
        // Also set up validation in case user bypasses modal (direct tab access)
        setupJoinFlowValidation();
    }

    // Add event listeners to the modal buttons
    if (existingAccountButton) {
        existingAccountButton.addEventListener('click', handleExistingAccountClick);
    }
    if (newAccountButton) {
        newAccountButton.addEventListener('click', handleNewAccountClick);
    }

    // Function to show the join flow modal
    function showJoinFlowModal() {
        if (modalOverlay && modalContent) {
            modalOverlay.style.display = 'block';
            modalContent.style.display = 'block';
        }
    }

    // Function to hide the join flow modal
    function hideJoinFlowModal() {
        if (modalOverlay && modalContent) {
            modalOverlay.style.display = 'none';
            modalContent.style.display = 'none';
        }
    }


    // Handler for "Yes, I have an account" button
    function handleExistingAccountClick() {
        // console.log('Existing account clicked. Dispatching activateJoinFlowTab event for login.');
        hideJoinFlowModal();
        // displayJoinFlowNotices('existing'); // No longer needed

        // Dispatch custom event to activate the login tab
        const activateEvent = new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-login' }
        });
        document.dispatchEvent(activateEvent);
    }

    // Handler for "No, I need to create an account" button
    function handleNewAccountClick() {
        // console.log('New account clicked. Dispatching activateJoinFlowTab event for register.');
        hideJoinFlowModal();
        // displayJoinFlowNotices('new'); // No longer needed

        // Dispatch custom event to activate the register tab
        const activateEvent = new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-register' }
        });
        document.dispatchEvent(activateEvent);

        // Add join flow validation for registration form
        setupJoinFlowValidation();
    }

    // Function to add validation for join flow registration
    function setupJoinFlowValidation() {
        const registrationForm = document.querySelector('form[action*="register"]');
        if (!registrationForm) return;

        // Add validation on form submit
        registrationForm.addEventListener('submit', function(e) {
            if (fromJoinFlow === 'true') {
                const artistCheckbox = document.getElementById('user_is_artist');
                const professionalCheckbox = document.getElementById('user_is_professional');
                
                if (!artistCheckbox.checked && !professionalCheckbox.checked) {
                    e.preventDefault();
                    showJoinFlowValidationError();
                    return false;
                }
            }
        });
    }

    // Function to show validation error for join flow
    function showJoinFlowValidationError() {
        // Remove any existing error messages
        const existingError = document.querySelector('.join-flow-validation-error');
        if (existingError) {
            existingError.remove();
        }

        // Create and insert error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'login-register-errors join-flow-validation-error';
        errorDiv.innerHTML = '<p class="error">To create your extrachill.link page, please select either "I am a musician" or "I work in the music industry".</p>';
        
        // Insert error before the form
        const form = document.querySelector('form[action*="register"]');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(errorDiv, form);
        }

        // Scroll to error message
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}); 