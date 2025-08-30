/**
 * Link Page Save Manager
 * 
 * Centralized save logic for link page management forms. Handles serialization
 * of CSS variables, links data, socials data, and form submission coordination.
 * 
 * Key features:
 * - CSS variables read directly from CSSOM (not textContent)
 * - Form serialization with hidden input coordination  
 * - Tab state preservation across page reloads
 * - Module-specific serialization hooks
 */
(function(manager) {
    if (!manager) return;
    manager.save = manager.save || {};

    /**
     * Read CSS variables directly from style tag (moved from css-variables.js abstraction)
     */
    function getCustomVarsFromStyleTag() {
        // Parse the style tag for all CSS vars - READ FROM CSSOM, NOT textContent
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (!styleTag) return {};
        
        let vars = {};
        
        // Use CSSOM to read current CSS variables (including dynamic updates)
        let sheet = styleTag.sheet;
        if (sheet && sheet.cssRules) {
            for (let i = 0; i < sheet.cssRules.length; i++) {
                if (sheet.cssRules[i].selectorText === ':root') {
                    const rootRule = sheet.cssRules[i];
                    // Read all CSS custom properties from the :root rule
                    for (let j = 0; j < rootRule.style.length; j++) {
                        const property = rootRule.style[j];
                        if (property.startsWith('--')) {
                            const value = rootRule.style.getPropertyValue(property);
                            if (value) {
                                vars[property] = value.trim();
                            }
                        }
                    }
                    break;
                }
            }
        }
        
        return vars;
    }

    /**
     * CSS variables are now handled directly via form inputs - no serialization needed
     */
    function serializeCssVarsToHiddenInput() {
        // No longer needed - CSS variables saved directly from form inputs
        console.log('[Save] CSS variables now handled directly via form inputs - no JSON serialization needed');
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
     * Overlay settings are now handled directly via form inputs - no serialization needed
     */
    function serializeOtherSettingsToHiddenInput() {
        // No longer needed - overlay toggle saved directly from form input
        console.log('[Save] Overlay settings now handled directly via form inputs');
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

    // Automatically attach save handler (no longer needs to be called manually)
    manager.save.attachSaveHandlerToForm();

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 