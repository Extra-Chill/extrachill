/**
 * Join Flow UI Management
 *
 * Handles modal display and tab activation for the join flow.
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

    function handleExistingAccountClick() {
        hideJoinFlowModal();
        document.dispatchEvent(new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-login' }
        }));
    }

    function handleNewAccountClick() {
        hideJoinFlowModal();
        document.dispatchEvent(new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-register' }
        }));
    }
}); 