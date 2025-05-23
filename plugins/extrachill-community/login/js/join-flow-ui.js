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

    // Function to display the relevant custom notice (No longer needed)
    // function displayJoinFlowNotices(accountType) {
    //     // Hide both notices first
    //     if (noticeLogin) noticeLogin.style.display = 'none';
    //     if (noticeRegister) noticeRegister.style.display = 'none';

    //     // Display the appropriate notice
    //     if (accountType === 'existing' && noticeLogin) {
    //         noticeLogin.style.display = 'block';
    //     } else if (accountType === 'new' && noticeRegister) {
    //         noticeRegister.style.display = 'block';
    //     }
    // }

    // Handler for "Yes, I have an account" button
    function handleExistingAccountClick() {
        console.log('Existing account clicked. Dispatching activateJoinFlowTab event for login.');
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
        console.log('New account clicked. Dispatching activateJoinFlowTab event for register.');
        hideJoinFlowModal();
        // displayJoinFlowNotices('new'); // No longer needed

        // Dispatch custom event to activate the register tab
        const activateEvent = new CustomEvent('activateJoinFlowTab', {
            detail: { targetTab: 'tab-register' }
        });
        document.dispatchEvent(activateEvent);
    }
}); 