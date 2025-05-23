// JavaScript for Advanced Tab - Manage Link Page

(function() { // Wrap in IIFE to avoid polluting global scope

    function initializeAdvancedTab() {
        console.log('Advanced tab JS initializing.');

        const redirectToggle = document.getElementById('bp-enable-temporary-redirect');
        const redirectTargetContainer = document.getElementById('bp-temporary-redirect-target-container');
        const redirectTargetSelect = document.getElementById('bp-temporary-redirect-target');

        if (redirectToggle && redirectTargetContainer && redirectTargetSelect) {
            // Set initial state based on toggle
            const isRedirectEnabled = redirectToggle.checked;
            redirectTargetContainer.style.display = isRedirectEnabled ? 'block' : 'none';
            redirectTargetSelect.disabled = !isRedirectEnabled;

            // Add event listener for toggle changes
            redirectToggle.addEventListener('change', function() {
                const enableRedirect = this.checked;
                redirectTargetContainer.style.display = enableRedirect ? 'block' : 'none';
                redirectTargetSelect.disabled = !enableRedirect;
                if (enableRedirect) {
                    // If enabling, ensure the dropdown is populated (or re-populated if links might have changed)
                    populateRedirectTargetDropdownIfNeeded();
                }
            });

        } else {
            console.warn('Redirect toggle/container/select elements not found in Advanced tab during init.');
        }

        const highlightingToggle = document.getElementById('bp-enable-link-highlighting');
        if (highlightingToggle) {
             console.log('Highlighting toggle found.');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAdvancedTab);
    } else {
        initializeAdvancedTab();
    }

})(); // End IIFE

// Consolidated redirect dropdown logic
const redirectEnabledCheckbox = document.getElementById('bp-enable-temporary-redirect');
const redirectTargetContainer = document.getElementById('bp-temporary-redirect-target-container');
const redirectTargetSelect = document.getElementById('bp-temporary-redirect-target');

function populateRedirectTargetDropdownIfNeeded() {
    if (!redirectTargetSelect) {
        console.debug('[populateRedirectTargetDropdownIfNeeded] Redirect select element not found.');
        return;
    }

    // Only populate if the checkbox is checked OR if we are explicitly told to refresh (e.g. from links update)
    // For initial load, the DOMContentLoaded listener will call this, and it should respect the checkbox state.
    // The event listener on checkbox change will also call this.

    if (!redirectEnabledCheckbox || !redirectEnabledCheckbox.checked) {
        // console.debug('[populateRedirectTargetDropdownIfNeeded] Redirect checkbox not checked. Not populating.');
        // We might still want to clear it if it was previously populated and now checkbox is unchecked.
        // However, the main use case is to populate when checked.
        // If checkbox is unchecked, the select is disabled and hidden, so content doesn't strictly matter until re-enabled.
        return; 
    }

    if (!window.bpLinkPageLinks || !Array.isArray(window.bpLinkPageLinks) || window.bpLinkPageLinks.length === 0) {
        console.warn('[populateRedirectTargetDropdownIfNeeded] bpLinkPageLinks data not available or empty.');
        // Clear existing options except the first placeholder, then add a disabled message
        selectElement.innerHTML = '<option value="">-- No Links Available --</option>';
        return;
    }
    
    console.debug('[populateRedirectTargetDropdownIfNeeded] Populating redirect dropdown.');

    // Clear existing options except the first placeholder
    const firstOption = redirectTargetSelect.options[0];
    redirectTargetSelect.innerHTML = ''; // Clear all
    if (firstOption && firstOption.value === '') {
        redirectTargetSelect.appendChild(firstOption); // Re-add placeholder if it was standard
    } else {
        redirectTargetSelect.innerHTML = '<option value="">-- Select a Link --</option>'; // Default placeholder
    }

    window.bpLinkPageLinks.forEach(section => {
        if (section.links && Array.isArray(section.links)) {
            section.links.forEach(link => {
                if (link.link_url && link.link_text && (link.link_is_active === undefined || link.link_is_active === true || link.link_is_active === '1')) {
                    const option = document.createElement('option');
                    option.value = link.link_url;
                    option.textContent = link.link_text + ' (' + link.link_url + ')';
                    redirectTargetSelect.appendChild(option);
                }
            });
        }
    });

    // Set the selected option based on initial data (if available)
    const initialUrl = window.ExtrchLinkPageManager?.ajaxConfig?.initial_redirect_target_url || window.extrchLinkPagePreviewAJAX?.initial_redirect_target_url;
    if (initialUrl) {
        redirectTargetSelect.value = initialUrl;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initial setup for redirect toggle visibility
    if (redirectEnabledCheckbox && redirectTargetContainer) {
        const updateDisplay = () => {
            const isChecked = redirectEnabledCheckbox.checked;
            redirectTargetContainer.style.display = isChecked ? 'block' : 'none';
            if (redirectTargetSelect) redirectTargetSelect.disabled = !isChecked;
            if (isChecked) {
                populateRedirectTargetDropdownIfNeeded(); // Populate if checked
            }
        };

        redirectEnabledCheckbox.addEventListener('change', updateDisplay);
        updateDisplay(); // Call once on load
    } else {
        console.warn('Redirect checkbox or container not found on DOMContentLoaded for initial setup.');
    }

    // Listen for custom event that indicates links have been updated by manage-link-page-links.js
    document.addEventListener('ExtrchLinkPageLinksUpdated', function() {
        console.debug('ExtrchLinkPageLinksUpdated event received, repopulating redirect dropdown.');
        populateRedirectTargetDropdownIfNeeded(); // This will respect the checkbox state
    });
});