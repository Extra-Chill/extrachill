/**
 * Join Flow UI Management
 *
 * Handles modal display, tab activation, and registration validation for the join flow.
 * Integrates with the community plugin's login/register interface via CustomEvents.
 *
 * @package ExtraChillArtistPlatform
 */
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const fromJoinFlow = urlParams.get('from_join');

    const modalOverlay = document.getElementById('join-flow-modal-overlay');
    const modalContent = document.getElementById('join-flow-modal-content');
    const existingAccountButton = document.getElementById('join-flow-existing-account');
    const newAccountButton = document.getElementById('join-flow-new-account');

    if (fromJoinFlow === 'true') {
        showJoinFlowModal();
        setupJoinFlowValidation();
    }

    if (existingAccountButton) {
        existingAccountButton.addEventListener('click', handleExistingAccountClick);
    }
    if (newAccountButton) {
        newAccountButton.addEventListener('click', handleNewAccountClick);
    }

    function showJoinFlowModal() {
        if (modalOverlay && modalContent) {
            modalOverlay.style.display = 'block';
            modalContent.style.display = 'block';
        }
    }

    function hideJoinFlowModal() {
        if (modalOverlay && modalContent) {
            modalOverlay.style.display = 'none';
            modalContent.style.display = 'none';
        }
    }

    /**
     * Activates login tab via CustomEvent for existing users
     */
    function handleExistingAccountClick() {
        hideJoinFlowModal();
        document.dispatchEvent(new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-login' }
        }));
    }

    /**
     * Activates registration tab via CustomEvent and enables validation
     */
    function handleNewAccountClick() {
        hideJoinFlowModal();
        document.dispatchEvent(new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-register' }
        }));
        setupJoinFlowValidation();
    }

    /**
     * Validates join flow registrations require artist or professional checkbox selection
     */
    function setupJoinFlowValidation() {
        const registrationForm = document.querySelector('form[action*="register"]');
        if (!registrationForm) return;

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

    function showJoinFlowValidationError() {
        const existingError = document.querySelector('.join-flow-validation-error');
        if (existingError) {
            existingError.remove();
        }

        const errorDiv = document.createElement('div');
        errorDiv.className = 'login-register-errors join-flow-validation-error';
        errorDiv.innerHTML = '<p class="error">To create your extrachill.link page, please select either "I am a musician" or "I work in the music industry".</p>';

        const form = document.querySelector('form[action*="register"]');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(errorDiv, form);
        }

        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}); 