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

        if (!hiddenInput) {
            console.warn('[Save] CSS variables hidden input not found');
            return;
        }
        
        if (!styleTag) {
            console.warn('[Save] CSS variables style tag not found');
            return;
        }

        let vars = {};
        let successMethod = null;
        
        // Method 1: Try CSSOM first (most reliable)
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
                        if (Object.keys(vars).length > 0) {
                            successMethod = 'CSSOM';
                            break;
                        }
                    }
                }
            }
        } catch (e) {
            console.warn('[Save] CSSOM method failed:', e.message);
        }
        
        // Method 2: Parse textContent (universal fallback)
        if (Object.keys(vars).length === 0) {
            try {
                const cssText = styleTag.textContent || styleTag.innerText || '';
                if (cssText.trim()) {
                    const patterns = [
                        /:root\s*\{([^}]*)\}/s,
                        /:root\s*\{([^}]*)\}/,
                        /\{([^}]*)\}/s
                    ];
                    
                    let varsBlock = '';
                    for (const pattern of patterns) {
                        const match = cssText.match(pattern);
                        if (match && match[1]) {
                            varsBlock = match[1];
                            break;
                        }
                    }
                    
                    if (varsBlock) {
                        const declarations = varsBlock.split(';');
                        for (const declaration of declarations) {
                            const colonIndex = declaration.indexOf(':');
                            if (colonIndex > 0) {
                                const property = declaration.substring(0, colonIndex).trim();
                                const value = declaration.substring(colonIndex + 1).trim();
                                if (property.startsWith('--') && value) {
                                    vars[property] = value;
                                }
                            }
                        }
                        
                        if (Object.keys(vars).length > 0) {
                            successMethod = 'textContent';
                        }
                    }
                }
            } catch (e) {
                console.warn('[Save] Text parsing method failed:', e.message);
            }
        }
        
        // Method 3: Form control fallback (universal)
        if (Object.keys(vars).length === 0) {
            console.warn('[Save] Style tag parsing failed, using form control fallback');
            try {
                // Start with current saved values as the base
                const currentSavedVars = getCurrentSavedCssVariables();
                vars = {...currentSavedVars};
                
                // Override with any form control values that have been changed
                const inputMappings = {
                    'link_page_background_type': '--link-page-background-type',
                    'link_page_background_color': '--link-page-background-color',
                    'link_page_background_gradient_start': '--link-page-background-gradient-start',
                    'link_page_background_gradient_end': '--link-page-background-gradient-end',
                    'link_page_background_gradient_direction': '--link-page-background-gradient-direction',
                    'link_page_title_font_family': '--link-page-title-font-family',
                    'link_page_body_font_family': '--link-page-body-font-family',
                    'link_page_text_color': '--link-page-text-color',
                    'link_page_button_color': '--link-page-button-bg-color',
                    'link_page_hover_color': '--link-page-button-hover-bg-color',
                    'link_page_link_text_color': '--link-page-link-text-color',
                    'link_page_button_radius': '--link-page-button-radius',
                    'link_page_button_border_color': '--link-page-button-border-color',
                    'link_page_title_font_size': '--link-page-title-font-size',
                    'link_page_body_font_size': '--link-page-body-font-size'
                };
                
                let formOverrides = 0;
                for (const [inputId, cssVar] of Object.entries(inputMappings)) {
                    const input = document.getElementById(inputId);
                    if (input && input.value && input.value !== input.defaultValue) {
                        vars[cssVar] = input.value;
                        formOverrides++;
                    }
                }
                
                if (Object.keys(vars).length > 0) {
                    successMethod = 'formFallback';
                    console.log('[Save] Using form fallback with', formOverrides, 'overrides and', Object.keys(vars).length, 'total variables');
                }
            } catch (e) {
                console.error('[Save] Form fallback failed:', e.message);
            }
        }
        
        // Ensure all expected CSS variables are present
        const expectedVars = getExpectedCssVariables();
        const currentSavedVars = getCurrentSavedCssVariables();
        
        for (const [key, hardcodedDefault] of Object.entries(expectedVars)) {
            if (!(key in vars)) {
                vars[key] = currentSavedVars[key] || hardcodedDefault;
            }
        }
        
        const finalJsonString = JSON.stringify(vars);
        hiddenInput.value = finalJsonString;
        
        if (successMethod) {
            console.log('[Save] CSS vars serialized using:', successMethod, 'with', Object.keys(vars).length, 'variables');
        } else {
            console.warn('[Save] No CSS vars found - potential save failure');
        }
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
     * Returns the expected CSS variables with their default values.
     * This ensures PHP always finds the keys it's looking for.
     */
    function getExpectedCssVariables() {
        return {
            // Background & Page Styles
            '--link-page-background-color': '#121212',
            '--link-page-background-type': 'color',
            '--link-page-background-gradient-start': '#0b5394',
            '--link-page-background-gradient-end': '#53940b',
            '--link-page-background-gradient-direction': 'to right',
            '--link-page-card-bg-color': 'rgba(0, 0, 0, 0.4)',
            '--link-page-background-image': '',
            '--link-page-background-image-url': '',
            
            // Text & Overlay Styles
            '--link-page-text-color': '#e5e5e5',
            '--link-page-muted-text-color': '#aaa',
            '--link-page-overlay-color': 'rgba(0, 0, 0, 0.5)',
            
            // Button & Link Styles
            '--link-page-button-bg-color': '#0b5394',
            '--link-page-link-text-color': '#ffffff',
            '--link-page-button-hover-bg-color': '#53940b',
            '--link-page-button-hover-text-color': '#ffffff',
            '--link-page-button-radius': '8px',
            '--link-page-button-border-width': '0px',
            '--link-page-button-border-color': '#0b5394',
            
            // Font Styles
            '--link-page-title-font-family': 'WilcoLoftSans',
            '--link-page-title-font-size': '2.1em',
            '--link-page-body-font-family': 'Helvetica',
            '--link-page-body-font-size': '1em',
            
            // Profile Image Styles
            '--link-page-profile-img-size': '30%',
            '--link-page-profile-img-border-radius': '50%',
            '--link-page-profile-img-aspect-ratio': '1/1',
            
            // Non-CSS special keys that PHP expects
            '_link_page_profile_img_shape': 'circle',
            'overlay': '1'
        };
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
                console.warn('[Save] Social links hidden input not found for saving.');
            }
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

        if (form.checkValidity()) {
            // Step 1: Serialize CSS variables
            serializeCssVarsToHiddenInput();

            // Step 2: Serialize other settings (like font family)
            serializeOtherLinkPageSettingsToHiddenInputs();

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

            // Form should now submit normally to PHP handler

        } else {
            event.preventDefault(); // Explicitly prevent submission on validation failure
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