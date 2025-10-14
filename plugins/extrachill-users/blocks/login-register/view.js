document.addEventListener('DOMContentLoaded', function() {
    const signUpLink = document.querySelector('.js-switch-to-register');
    if (signUpLink) {
        signUpLink.addEventListener('click', function(event) {
            event.preventDefault(); // Prevent the default link behavior
            
            // Find the parent shared-tabs-component
            const tabsComponent = signUpLink.closest('.shared-tabs-component');
            
            if (tabsComponent) {
                // Find the register tab button within this component
                const registerTabButton = tabsComponent.querySelector('.shared-tab-button[data-tab="tab-register"]');
                
                if (registerTabButton) {
                    // Trigger a click on the register tab button
                    registerTabButton.click();
                    
                    // Optionally update the URL hash without a reload
                    if (history.pushState) {
                         history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0] + '#tab-register');
                     } else {
                         window.location.hash = '#tab-register';
                     }
                }
            }
        });
    }
});

