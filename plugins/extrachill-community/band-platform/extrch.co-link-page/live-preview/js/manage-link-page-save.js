// manage-link-page-save.js
// Centralized save logic for the link page manager (custom vars, links, socials, advanced, etc.)
(function(manager) {
    if (!manager) return;
    manager.save = manager.save || {};

    // Track if we've already logged missing element errors (to avoid spam)
    let loggedMissingCssVars = false;
    let loggedMissingLinksInput = false;
    let loggedMissingSocialsInput = false;

    /**
     * Serializes the current CSS variables from the preview style tag into the hidden input.
     */
    function serializeCssVarsToHiddenInput() {
        const hiddenInput = document.getElementById('link_page_custom_css_vars_json');
        const styleTag = document.getElementById('extrch-link-page-custom-vars');

        if (!hiddenInput || !styleTag) {
            if (!loggedMissingCssVars) {
                loggedMissingCssVars = true;
            }
            return;
        }

        let sheet = styleTag.sheet;
        if (!sheet) {
            return;
        }
        let rootRule = null;
        try {
            for (let i = 0; i < sheet.cssRules.length; i++) {
                if (sheet.cssRules[i].selectorText === ':root') {
                    rootRule = sheet.cssRules[i];
                    break;
                }
            }
        } catch (e) {
            return;
        }
        
        if (!rootRule) {
            loggedMissingCssVars = true;
            hiddenInput.value = JSON.stringify({});
            return;
        }
        const vars = {};
        for (let i = 0; i < rootRule.style.length; i++) {
            const prop = rootRule.style[i];
            if (prop.startsWith('--')) {
                vars[prop] = rootRule.style.getPropertyValue(prop).trim();
            }
        }
        hiddenInput.value = JSON.stringify(vars);
    }

    /**
     * Serializes other (non-CSS-var) settings, such as overlay toggle, into the hidden input JSON.
     * This function merges with the existing CSS vars object in the hidden input.
     */
    function serializeOtherLinkPageSettingsToHiddenInputs() {
        const hiddenInput = document.getElementById('link_page_custom_css_vars_json');
        if (!hiddenInput) return; // Already logged by serializeCssVarsToHiddenInput if missing
        
        let vars = {};
        try {
            // Initialize with existing values if any (e.g., from CSS vars serialization)
            vars = JSON.parse(hiddenInput.value || '{}'); 
        } catch (e) {
            vars = {};
        }
        // Overlay toggle
        const overlayToggle = document.getElementById('link_page_overlay_toggle');
        if (overlayToggle) {
            vars.overlay = overlayToggle.checked ? '1' : '0';
        }
        // Add other non-CSS var settings here if needed in the future
        hiddenInput.value = JSON.stringify(vars);
    }

    /**
     * Serializes the links data from the DOM (via manager.linkSections.getLinksDataFromDOM)
     * into the #link_page_links_json hidden input.
     */
    function serializeLinksDataToHiddenInput() {
        const hiddenLinksInput = document.getElementById('link_page_links_json');
        if (!hiddenLinksInput) {
            if (!loggedMissingLinksInput) {
                loggedMissingLinksInput = true;
            }
            return;
        }

        // The value is already expected to be set by manage-link-page-links.js
        // No action needed here beyond confirming the input exists (already done above)
        // The form submission will automatically include the current value of hiddenLinksInput.
    }
    /**
     * Serializes the socials data from the DOM (via manager.socialIcons.getSocialsDataFromDOM)
     * into the #band_profile_social_links_json hidden input.
     */
    function serializeSocialsDataToHiddenInput() {
        const hiddenSocialsInput = document.getElementById('band_profile_social_links_json');
        if (!hiddenSocialsInput) {
            if (!loggedMissingSocialsInput) {
                loggedMissingSocialsInput = true;
            }
            return;
        }

        // The value is already expected to be set by manage-link-page-socials.js
        // No action needed here beyond confirming the input exists (already done above)
        // The form submission will automatically include the current value of hiddenSocialsInput.
    }

    function handleFormSubmitWithSaveUI(event) {
        const form = event.target;
        const saveButton = document.querySelector('.bp-link-page-save-btn[name="bp_save_link_page"]');
        const loadingMessageElement = document.getElementById('link-page-loading-message');

        if (form.checkValidity()) {
            // Serialize all JS-managed data to their respective hidden inputs
            // NOTE: CSS Vars and Other Settings are still serialized here as they might not have dedicated update logic elsewhere.
            // Links and Socials modules are now responsible for keeping their hidden inputs updated.
            serializeCssVarsToHiddenInput();
            serializeOtherLinkPageSettingsToHiddenInputs();
            // Removed calls to serializeLinksDataToHiddenInput and serializeSocialsDataToHiddenInput
            // as their respective modules are now responsible for updating their hidden inputs directly.
            // The form submission will pick up the values already present in the hidden inputs.

            if (loadingMessageElement) loadingMessageElement.style.display = 'flex';
            if (saveButton) saveButton.style.display = 'none';

            // Always get the id of the active pane for tab restoration
            let activeTab = null;
            const activePane = document.querySelector('.shared-tab-pane.is-active-pane');
            if (activePane && activePane.id) {
                activeTab = activePane.id;
            }
            let tabInput = form.querySelector('input[name="tab"]');
            if (!tabInput) {
                tabInput = document.createElement('input');
                tabInput.type = 'hidden';
                tabInput.name = 'tab';
                form.appendChild(tabInput);
            }
            tabInput.value = activeTab || '';

        } else {
            // Prevent default submission if checkValidity returns false, as we want to rely on native UI
            // event.preventDefault(); // This might be redundant as browser might stop anyway
        }
    }

    function attachSaveHandlerToForm() {
        // Use a simple function to avoid code duplication
        const attach = () => {
            const form = document.getElementById('bp-manage-link-page-form');
            // Prevent attaching multiple times
            if (form && !form.dataset.saveHandlerAttached) {
                form.addEventListener('submit', handleFormSubmitWithSaveUI);
                form.dataset.saveHandlerAttached = '1'; // Mark as attached
                // Ensure the main manager also calls the init for linkSections
                // Note: This init call here feels slightly misplaced in the save handler module.
                // It should ideally be in the main manager's init function.
                // But keeping it for now if it's required for the links module to work.
                if (manager.linkSections && typeof manager.linkSections.init === 'function') {
                    manager.linkSections.init();
                } else {
                }
            } else if (form && form.dataset.saveHandlerAttached) {
            } else {
            }
        };

        // Attempt to attach immediately if the DOM is already interactive or complete
        if (document.readyState !== 'loading') {
            attach();
        } else {
            // Attach on DOMContentLoaded
            window.addEventListener('DOMContentLoaded', attach);
        }
    }

    manager.save.attachSaveHandlerToForm = attachSaveHandlerToForm;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 