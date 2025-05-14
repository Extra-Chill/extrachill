// JavaScript for Advanced Tab - Manage Link Page

(function() { // Wrap in IIFE to avoid polluting global scope

    function populateRedirectDropdown(selectElement, initialTargetUrl) {
        if (!selectElement || !window.ExtrchLinkPageManager || !window.ExtrchLinkPageManager.initialLinkSectionsData) {
            console.warn('Could not populate redirect dropdown: Missing element or link data.');
            return;
        }

        const linkSections = window.ExtrchLinkPageManager.initialLinkSectionsData;
        selectElement.innerHTML = '<option value="">-- Select a Link --</option>'; // Clear existing options and add default

        linkSections.forEach(section => {
            if (section.links && Array.isArray(section.links)) {
                section.links.forEach(link => {
                    if (link.link_url && link.link_text) {
                        const option = document.createElement('option');
                        option.value = link.link_url;
                        option.textContent = link.link_text;
                        if (link.link_url === initialTargetUrl) {
                            option.selected = true;
                        }
                        selectElement.appendChild(option);
                    }
                });
            }
        });
    }

    function initializeAdvancedTab() {
        console.log('Advanced tab JS initializing.');

        const redirectToggle = document.getElementById('bp-enable-temporary-redirect');
        const redirectTargetContainer = document.getElementById('bp-temporary-redirect-target-container');
        const redirectTargetSelect = document.getElementById('bp-temporary-redirect-target');
        const initialRedirectUrl = window.ExtrchLinkPageManager?.ajaxConfig?.initial_redirect_target_url || '';

        if (redirectToggle && redirectTargetContainer && redirectTargetSelect) {
            // Populate dropdown initially
            populateRedirectDropdown(redirectTargetSelect, initialRedirectUrl);

            // Set initial state based on toggle
            const isRedirectEnabled = redirectToggle.checked;
            redirectTargetContainer.style.display = isRedirectEnabled ? 'block' : 'none';
            redirectTargetSelect.disabled = !isRedirectEnabled;

            // Add event listener for toggle changes
            redirectToggle.addEventListener('change', function() {
                const enableRedirect = this.checked;
                redirectTargetContainer.style.display = enableRedirect ? 'block' : 'none';
                redirectTargetSelect.disabled = !enableRedirect;
                // Re-populate or just ensure selection is maintained? For now, just toggle visibility/disabled state.
                // If links could change dynamically while on this page, we might need to repopulate here.
            });

        } else {
            console.warn('Redirect elements not found in Advanced tab.');
        }

        // Add logic for other advanced controls here later (e.g., highlighting toggle interaction)
        const highlightingToggle = document.getElementById('bp-enable-link-highlighting');
        if (highlightingToggle) {
             // Placeholder: Add logic if this toggle needs to interact with other elements immediately
             // For now, its state is just saved by the form handler.
             // Later, it might dispatch an event or directly tell the links module to show/hide checkboxes.
             console.log('Highlighting toggle found.');
        }
    }

    // Wait for DOMContentLoaded to ensure elements exist and global data is set
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAdvancedTab);
    } else {
        initializeAdvancedTab(); // DOM already ready
    }

})(); // End IIFE