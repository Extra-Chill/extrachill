// JavaScript for Advanced Tab - Manage Link Page

(function() { // Wrap in IIFE to avoid polluting global scope

    function initializeAdvancedTab() {
        // console.log('Advanced tab JS initializing.');

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
            // console.warn('Redirect toggle/container/select elements not found in Advanced tab during init.');
        }

        const highlightingToggle = document.getElementById('bp-enable-link-highlighting');
        if (highlightingToggle) {
             // console.log('Highlighting toggle found.');
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

function getCurrentLinksData() {
    if (window.ExtrchLinkPageManager &&
        window.ExtrchLinkPageManager.linkSections &&
        typeof window.ExtrchLinkPageManager.linkSections.getLinksDataFromDOM === 'function') {
        return window.ExtrchLinkPageManager.linkSections.getLinksDataFromDOM();
    }
    // Fallback: read from DOM directly
    const sectionsListEl = document.getElementById('bp-link-sections-list');
    if (!sectionsListEl) return [];
    const sectionsData = [];
    sectionsListEl.querySelectorAll('.bp-link-section').forEach(sectionEl => {
        const sectionTitle = sectionEl.querySelector('.bp-link-section-title')?.value || '';
        const linksData = [];
        sectionEl.querySelectorAll('.bp-link-item').forEach(linkEl => {
            linksData.push({
                link_text: linkEl.querySelector('.bp-link-text-input')?.value || '',
                link_url: linkEl.querySelector('.bp-link-url-input')?.value || '',
                expires_at: linkEl.dataset.expiresAt || '',
            });
        });
        sectionsData.push({ section_title: sectionTitle, links: linksData });
    });
    return sectionsData;
}

function populateRedirectTargetDropdownIfNeeded() {
    if (!redirectTargetSelect) {
        // console.debug('[populateRedirectTargetDropdownIfNeeded] Redirect select element not found.');
        return;
    }
    if (!redirectEnabledCheckbox || !redirectEnabledCheckbox.checked) {
        return;
    }
    const linksSections = getCurrentLinksData();
    if (!Array.isArray(linksSections) || linksSections.length === 0) {
        redirectTargetSelect.innerHTML = '<option value="">-- No Links Available --</option>';
        return;
    }
    // Clear existing options except the first placeholder
    const firstOption = redirectTargetSelect.options[0];
    redirectTargetSelect.innerHTML = '';
    if (firstOption && firstOption.value === '') {
        redirectTargetSelect.appendChild(firstOption);
    } else {
        redirectTargetSelect.innerHTML = '<option value="">-- Select a Link --</option>';
    }
    linksSections.forEach(section => {
        if (section.links && Array.isArray(section.links)) {
            section.links.forEach(link => {
                if (link.link_url && link.link_text) {
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
        // console.warn('Redirect checkbox or container not found on DOMContentLoaded for initial setup.');
    }

    // Listen for custom event that indicates links have been updated by manage-link-page-links.js
    document.addEventListener('ExtrchLinkPageLinksUpdated', function() {
        // console.debug('ExtrchLinkPageLinksUpdated event received, repopulating redirect dropdown.');
        populateRedirectTargetDropdownIfNeeded(); // This will respect the checkbox state
    });
});