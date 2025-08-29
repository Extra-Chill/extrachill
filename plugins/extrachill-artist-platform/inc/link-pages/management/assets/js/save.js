// manage-link-page-save.js
// Centralized save logic for the link page manager (custom vars, links, socials, advanced, etc.)
(function(manager) {
    if (!manager) return;
    manager.save = manager.save || {};


    /**
     * Serializes the current CSS variables from the preview style tag into the hidden input.
     */
    function serializeCssVarsToHiddenInput() {
        const hiddenInput = document.getElementById('link_page_custom_css_vars_json');
        const styleTag = document.getElementById('extrch-link-page-custom-vars');

        if (!hiddenInput || !styleTag) {
            console.warn('[Save] CSS variables elements not found');
            return;
        }

        let vars = {};
        
        try {
            const sheet = styleTag.sheet;
            if (sheet && sheet.cssRules && sheet.cssRules.length > 0) {
                for (let i = 0; i < sheet.cssRules.length; i++) {
                    const rule = sheet.cssRules[i];
                    if (rule && rule.selectorText === ':root' && rule.style) {
                        for (let j = 0; j < rule.style.length; j++) {
                            const property = rule.style[j];
                            if (property && property.startsWith('--')) {
                                const value = rule.style.getPropertyValue(property);
                                if (value && value.trim()) {
                                    vars[property] = value.trim();
                                }
                            }
                        }
                    }
                }
            }
        } catch (e) {
            console.warn('[Save] CSS parsing failed:', e.message);
        }
        
        // Ensure all expected CSS variables are present
        const expectedVars = getExpectedCssVariables();
        const currentSavedVars = getCurrentSavedCssVariables();
        
        for (const [key, hardcodedDefault] of Object.entries(expectedVars)) {
            if (!(key in vars)) {
                vars[key] = currentSavedVars[key] || hardcodedDefault;
            }
        }
        
        hiddenInput.value = JSON.stringify(vars);
    }

    /**
     * Returns the current saved CSS variables from the hidden input.
     * This represents the state that was loaded from the database on page load.
     */
    function getCurrentSavedCssVariables() {
        const hiddenInput = document.getElementById('link_page_custom_css_vars_json');
        if (!hiddenInput) {
            console.warn('[Save] Hidden input not found for current saved vars');
            return {};
        }
        
        // Try multiple sources for the initial value
        let sources = [
            { name: 'data-initial-value', value: hiddenInput.getAttribute('data-initial-value') },
            { name: 'defaultValue', value: hiddenInput.defaultValue },
            { name: 'current value', value: hiddenInput.value }
        ];
        
        for (let source of sources) {
            if (source.value) {
                try {
                    const parsed = JSON.parse(source.value);
                    if (parsed && typeof parsed === 'object' && Object.keys(parsed).length > 0) {
                        return parsed;
                    }
                } catch (e) {
                    console.warn(`[Save] Error parsing saved vars from ${source.name}:`, e.message);
                }
            }
        }
        
        console.warn('[Save] No valid saved CSS variables found');
        return {};
    }

    /**
     * Returns the expected CSS variables from the PHP-provided initial data.
     * This ensures consistent defaults between PHP and JavaScript.
     */
    function getExpectedCssVariables() {
        // Use the initial CSS variables provided by PHP (which come from the centralized filter)
        if (window.linkPageInitialData && window.linkPageInitialData.css_vars) {
            return window.linkPageInitialData.css_vars;
        }
        
        // Fallback to minimal set if no PHP data available
        return {
            '--link-page-background-color': '#121212',
            '--link-page-background-type': 'color',
            '_link_page_profile_img_shape': 'circle',
            'overlay': '1'
        };
    }

    /**
     * Serializes other settings like overlay toggle into the CSS vars JSON.
     */
    function serializeOtherSettingsToHiddenInput() {
        const hiddenInput = document.getElementById('link_page_custom_css_vars_json');
        if (!hiddenInput) return;
        
        let vars = {};
        try {
            vars = JSON.parse(hiddenInput.value || '{}'); 
        } catch (e) {
            vars = {};
        }
        
        const overlayToggle = document.getElementById('link_page_overlay_toggle');
        if (overlayToggle) {
            vars.overlay = overlayToggle.checked ? '1' : '0';
        }
        
        hiddenInput.value = JSON.stringify(vars);
    }

    /**
     * Serializes the links data from the DOM (via manager.linkSections.getLinksDataFromDOM)
     * into the #link_page_links_json hidden input.
     */
    function serializeLinksDataToHiddenInput() {
        const hiddenLinksInput = document.getElementById('link_page_links_json');
        if (!hiddenLinksInput) {
            return;
        }

        // The value is already expected to be set by manage-link-page-links.js
        // No action needed here beyond confirming the input exists (already done above)
        // The form submission will automatically include the current value of hiddenLinksInput.
    }
    /**
     * Serializes the socials data from the DOM (via manager.socialIcons.getSocialsDataFromDOM)
     * into the #artist_profile_social_links_json hidden input.
     */
    function serializeSocialsDataToHiddenInput() {
        const hiddenSocialsInput = document.getElementById('artist_profile_social_links_json');
        if (!hiddenSocialsInput) {
            console.warn('[Save] Social links hidden input not found for saving.');
            return;
        }
        
        // Explicitly get current socials data from DOM and update hidden input
        // This ensures we capture the latest state even if the module didn't update it
        if (manager.socialIcons && typeof manager.socialIcons.getSocialsDataFromDOM === 'function') {
            const currentSocialsData = manager.socialIcons.getSocialsDataFromDOM();
            const jsonValue = JSON.stringify(currentSocialsData);
            
            hiddenSocialsInput.value = jsonValue;
        } else {
            console.warn('[Save] Social icons manager or getSocialsDataFromDOM function not available');
        }
    }

    function handleFormSubmitWithSaveUI(event) {
        const form = event.target;
        const saveButton = document.querySelector('.bp-link-page-save-btn[name="bp_save_link_page"]');
        const loadingMessageElement = document.getElementById('link-page-loading-message');

        // Step 1: Serialize CSS variables
        serializeCssVarsToHiddenInput();

        // Step 2: Serialize other settings (like overlay toggle)
        serializeOtherSettingsToHiddenInput();

        // Step 3: Serialize socials data
        serializeSocialsDataToHiddenInput();

        // Step 4: Serialize info data (profile image state)
        if (typeof serializeInfoForSave === 'function') {
            serializeInfoForSave();
        }

        // Step 5: Call module serialize methods (use manager instances)
        if (typeof manager !== 'undefined') {
            if (manager.links && typeof manager.links.serializeForSave === 'function') {
                manager.links.serializeForSave();
            }

            if (manager.socialIcons && typeof manager.socialIcons.serializeForSave === 'function') {
                manager.socialIcons.serializeForSave();
            }

            if (manager.sizing && typeof manager.sizing.serializeForSave === 'function') {
                manager.sizing.serializeForSave();
            }
        } else {
            // Fallback to global functions if manager isn't available
            if (typeof serializeSizingForSave === 'function') {
                serializeSizingForSave();
            }
        }

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

        // Form will now submit normally to PHP handler
    }

    function attachSaveHandlerToForm() {
        const attach = () => {
            const form = document.getElementById('bp-manage-link-page-form');
            if (form && !form.dataset.saveHandlerAttached) {
                form.addEventListener('submit', handleFormSubmitWithSaveUI);
                form.dataset.saveHandlerAttached = '1';
            }
        };

        if (document.readyState !== 'loading') {
            attach();
        } else {
            window.addEventListener('DOMContentLoaded', attach);
        }
    }

    manager.save.attachSaveHandlerToForm = attachSaveHandlerToForm;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 