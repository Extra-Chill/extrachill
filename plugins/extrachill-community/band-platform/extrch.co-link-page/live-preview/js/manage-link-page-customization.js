/**
 * [2025-05 Refactor] This module now aligns with @refactor-link-page-preview.mdc:
 * - On page load, PHP outputs all CSS vars and hydrates controls. JS does NOT re-apply or re-initialize styles.
 * - JS only attaches event listeners to controls.
 * - On user change, JS updates only the relevant CSS variable, the hidden input, and the affected preview element.
 * - No full preview refresh or rehydration on load.
 */

// Link Page Customization Module
// Handles ALL customization inputs for the link page management UI
(function(manager){
    // Ensure the manager and its necessary properties exist
    if (!manager) {
        return;
    }
    manager.customization = manager.customization || {};
    manager.customization.isInitialized = false; // Flag to prevent multiple initializations

    // --- Debounce function ---
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    // --- Constants ---
    // Constants related to sizing have been moved to manage-link-page-sizing.js
    // const FONT_SIZE_MIN_EM = 0.8;
    // const FONT_SIZE_MAX_EM = 3.5;
    // const PROFILE_IMG_SIZE_MIN = 1;
    // const PROFILE_IMG_SIZE_MAX = 100;
    // const PROFILE_IMG_SIZE_DEFAULT = 30;

    // --- Cached DOM Elements (specific to customization controls in this file) ---
    // const cssVarsInput = document.getElementById('link_page_custom_css_vars_json');
    // Sizing/Shape related DOM elements moved to manage-link-page-sizing.js
    // const profileImgShapeHiddenInput = document.getElementById('link_page_profile_img_shape_hidden');
    // const profileImgShapeCircleRadio = document.getElementById('profile-img-shape-circle');
    // const profileImgShapeSquareRadio = document.getElementById('profile-img-shape-square');
    // const profileImgShapeRectangleRadio = document.getElementById('profile-img-shape-rectangle');
    const titleFontFamilySelect = document.getElementById('link_page_title_font_family');
    // const titleFontSizeSlider = document.getElementById('link_page_title_font_size');
    // const titleFontSizeOutput = document.getElementById('title_font_size_output');
    // const profileImgSizeSlider = document.getElementById('link_page_profile_img_size');
    // const profileImgSizeOutput = document.getElementById('profile_img_size_output');
    // const buttonRadiusSlider = document.getElementById('link_page_button_radius');
    // const buttonRadiusOutput = document.getElementById('button_radius_output');
    const overlayToggle = document.getElementById('link_page_overlay_toggle');
    const bodyFontFamilySelect = document.getElementById('link_page_body_font_family'); // New Body Font Select
    
    // Color Picker Elements
    const buttonBgColorPicker = document.getElementById('link_page_button_color'); // ID remains link_page_button_color, but maps to --link-page-button-bg-color
    const textColorPicker = document.getElementById('link_page_text_color');
    const linkTextColorPicker = document.getElementById('link_page_link_text_color');
    const buttonHoverBgColorPicker = document.getElementById('link_page_hover_color'); // ID remains link_page_hover_color, but maps to --link-page-button-hover-bg-color
    const buttonBorderColorPicker = document.getElementById('link_page_button_border_color');
    
    let controlsInitialized = false; // Flag to track if UI controls have been synced
    let customVarsInput = null; // Reference to the hidden input

    // --- Canonical customVars object and its initialization ---
    // Remove defaultVars and any JS-side defaults; rely only on PHP-rendered values.
    let customVars = {}; // No JS-side defaults; all hydration is from PHP or the style tag.

    // --- Function to update a setting and trigger preview update via PreviewUpdater service ---
    // Canonical: Only update the relevant CSS variable in the <style id="extrch-link-page-custom-vars"> tag in <head>.
    // This ensures a single source of truth for both live preview and save.
    manager.customization.updateSetting = function(key, value) {
        // Special handling for overlay (not a CSS variable - it's a class toggle)
        if (key === 'overlay') {
            if (manager.previewUpdater && typeof manager.previewUpdater.update === 'function') {
                manager.previewUpdater.update(key, value, manager.customization.getCustomVars());
            }
            return;
        }
        
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (!styleTag) {
            return;
        }
        // Validate value
        if (typeof value === 'undefined' || value === null || value === '') {
            return;
        }
        // Use CSSOM to update only the specific variable in the :root rule
        let sheet = styleTag.sheet;
        if (!sheet) {
            return;
        }
        let rootRule = null;
        for (let i = 0; i < sheet.cssRules.length; i++) {
            if (sheet.cssRules[i].selectorText === ':root') {
                rootRule = sheet.cssRules[i];
                break;
            }
        }
        if (!rootRule) {
            // If :root rule doesn't exist, create it
            try {
                sheet.insertRule(':root {}', sheet.cssRules.length);
                rootRule = sheet.cssRules[sheet.cssRules.length - 1];
            } catch (e) {
                return;
            }
        }
        rootRule.style.setProperty(key, value);
        
        // Call previewUpdater for any additional JS-driven preview logic
        if (manager.previewUpdater && typeof manager.previewUpdater.update === 'function') {
            manager.previewUpdater.update(key, value, manager.customization.getCustomVars());
        }
    };

    // --- Generic Event Listener Attachment Function (for controls managed by this file) ---
    function attachControlListener(element, customVarKey, eventType = 'change', valueTransform = null, isCheckbox = false) {
        if (!element) {
            return;
        }
        
        // Special handling for font pickers to ensure Google Font is loaded before updating CSS var
        if (customVarKey === '--link-page-title-font-family' || customVarKey === '--link-page-body-font-family') {
            element.addEventListener(eventType, function(event) {
                let val = isCheckbox ? event.target.checked : event.target.value;
                if (valueTransform) {
                    val = valueTransform(val, event.target);
                }
                // Add loading class and disable the select
                element.classList.add('font-loading');
                element.disabled = true;
                // Load the Google Font before updating the CSS var
                if (manager.fonts && typeof manager.fonts.loadGoogleFont === 'function' && typeof manager.fonts.getGoogleFontParamByValue === 'function') {
                    const fontParam = manager.fonts.getGoogleFontParamByValue(val);
                    manager.fonts.loadGoogleFont(fontParam, val, function() {
                        // Remove loading class and re-enable
                        element.classList.remove('font-loading');
                        element.disabled = false;
                        manager.customization.updateSetting(customVarKey, val);
                    });
                } else {
                    element.classList.remove('font-loading');
                    element.disabled = false;
                    manager.customization.updateSetting(customVarKey, val);
                }
            });
        } else {
            element.addEventListener(eventType, function(event) {
                let val = isCheckbox ? event.target.checked : event.target.value;
                if (valueTransform) {
                    val = valueTransform(val, event.target);
                }
                
                // Convert boolean to string for overlay consistency
                if (isCheckbox && customVarKey === 'overlay') {
                    val = val ? '1' : '0';
                }
                
                manager.customization.updateSetting(customVarKey, val);
            });
        }
    }
    manager.customization.attachControlListener = attachControlListener; 
    
    // --- Getter for customVars (for serialization before save) ---
    manager.customization.getCustomVars = function() {
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
        
        // If no CSSOM data found, fall back to textContent parsing (for initial load)
        if (Object.keys(vars).length === 0) {
            let cssText = styleTag.textContent;
            let match = cssText.match(/:root\s*{([^}]*)}/);
            let varsBlock = match ? match[1] : '';
            varsBlock.split(';').forEach(pair => {
                const [k, v] = pair.split(':').map(s => s && s.trim());
                if (k && v && k.startsWith('--')) {
                    vars[k] = v;
                }
            });
        }
        
        // Handle overlay: always read from current checkbox state (overlay is not a CSS variable)
        const overlayToggle = document.getElementById('link_page_overlay_toggle');
        if (overlayToggle) {
            vars.overlay = overlayToggle.checked ? '1' : '0';
        }
        
        return vars;
    };

    // --- Function to sync UI controls from customVars (for controls managed by this file) ---
    function syncControlsFromCustomVars() {
        // This function should only read from the style tag (via getCustomVars)
        const currentCV = manager.customization.getCustomVars();
        if (!currentCV) {
            return;
        }

        if (titleFontFamilySelect) {
            const storedFontFamily = currentCV['--link-page-title-font-family'] || '';
            let fontValueForSelect = storedFontFamily;
            const currentFontOptions = (typeof window.extrchLinkPageFonts !== 'undefined' && Array.isArray(window.extrchLinkPageFonts)) ? window.extrchLinkPageFonts : [];
            const foundFontByStack = currentFontOptions.find(f => f.stack === storedFontFamily);
            if (foundFontByStack) {
                fontValueForSelect = foundFontByStack.value;
            } else {
                const foundFontByValue = currentFontOptions.find(f => f.value === storedFontFamily);
                if (foundFontByValue) fontValueForSelect = foundFontByValue.value;
            }
            titleFontFamilySelect.value = fontValueForSelect;
        }

        if (bodyFontFamilySelect) {
            const storedBodyFontFamily = currentCV['--link-page-body-font-family'] || '';
            let bodyFontValueForSelect = storedBodyFontFamily;
            const currentFontOptions = (typeof window.extrchLinkPageFonts !== 'undefined' && Array.isArray(window.extrchLinkPageFonts)) ? window.extrchLinkPageFonts : [];
            const stackParts = storedBodyFontFamily.split(',');
            const firstFontInStack = stackParts[0] ? stackParts[0].trim().replace(/['"]/g, '') : '';
            const foundBodyFontByValueInList = currentFontOptions.find(f => f.value === firstFontInStack);
            if (foundBodyFontByValueInList) {
                bodyFontValueForSelect = foundBodyFontByValueInList.value;
            } else {
                const foundBodyFontByStack = currentFontOptions.find(f => f.stack === storedBodyFontFamily);
                if (foundBodyFontByStack) {
                    bodyFontValueForSelect = foundBodyFontByStack.value;
                } else {
                    bodyFontValueForSelect = '';
                }
            }
            bodyFontFamilySelect.value = bodyFontValueForSelect;
        }

        // Handle overlay toggle sync - simple conflict prevention
        if (overlayToggle) {
            // Only sync if the user is not currently interacting with the toggle
            const isUserActivelyInteracting = document.activeElement === overlayToggle;
            
            if (!isUserActivelyInteracting) {
                let targetState;
                if (typeof currentCV.overlay !== 'undefined') {
                    targetState = currentCV.overlay === '1';
                } else {
                    // Default to enabled if no state found
                    targetState = true;
                }
                
                // Only update if there's actually a difference
                if (overlayToggle.checked !== targetState) {
                    overlayToggle.checked = targetState;
                }
            }
        }

        // Sync Color Pickers (use a safe fallback if not present)
        if (buttonBgColorPicker) {
            buttonBgColorPicker.value = currentCV['--link-page-button-bg-color'] || '#000000';
        }
        if (textColorPicker) {
            textColorPicker.value = currentCV['--link-page-text-color'] || '#000000';
        }
        if (linkTextColorPicker) {
            linkTextColorPicker.value = currentCV['--link-page-link-text-color'] || '#000000';
        }
        if (buttonHoverBgColorPicker) {
            buttonHoverBgColorPicker.value = currentCV['--link-page-button-hover-bg-color'] || '#000000';
        }
        if (buttonBorderColorPicker) {
            buttonBorderColorPicker.value = currentCV['--link-page-button-border-color'] || '#000000';
        }

        // Call sync for other modules if they expose such functions
        if (manager.background && typeof manager.background.syncBackgroundInputValues === 'function') {
             manager.background.syncBackgroundInputValues();
        }
        if (manager.colors && typeof manager.colors.syncColorInputValues === 'function') {
             manager.colors.syncColorInputValues();
        }
        if (manager.sizing && typeof manager.sizing.syncSizingInputValues === 'function') {
            manager.sizing.syncSizingInputValues();
        }
    }

    // --- Initialization logic for this customization module ("The Brain") ---
    manager.customization.init = function() {
        if (manager.customization.isInitialized) {
            return;
        }

        customVarsInput = document.getElementById('link_page_custom_css_vars_json');

        // Event Listeners for Controls:
        // Background color - Handled in manage-link-page-background.js
        // Background image - Handled in manage-link-page-background.js

        // Sizing controls - Handled in manage-link-page-sizing.js

        // Profile Image Shape - Handled in manage-link-page-sizing.js

        // Text Color
        attachControlListener(textColorPicker, '--link-page-text-color');
        // Link Text Color
        attachControlListener(linkTextColorPicker, '--link-page-link-text-color');

        // Font Family - Title
        attachControlListener(titleFontFamilySelect, '--link-page-title-font-family');
        // Font Size - Title - Handled in manage-link-page-sizing.js

        // Font Family - Body
        attachControlListener(bodyFontFamilySelect, '--link-page-body-font-family');

        // Button Color (maps to background color)
        attachControlListener(buttonBgColorPicker, '--link-page-button-bg-color');
        // Button Hover Color (maps to hover background color)
        attachControlListener(buttonHoverBgColorPicker, '--link-page-button-hover-bg-color');
        // Button Border Color
        attachControlListener(buttonBorderColorPicker, '--link-page-button-border-color');
        // Button Radius - Handled in manage-link-page-sizing.js
        // Button Border Width - Handled in manage-link-page-sizing.js

        // Overlay Toggle
        attachControlListener(overlayToggle, 'overlay', 'change', null, true); // isCheckbox = true

        // Sync UI controls with initial PHP-rendered values (read from style tag)
        // Use a small timeout to ensure the style tag is populated by PHP first
        setTimeout(() => {
            syncControlsFromCustomVars();
            controlsInitialized = true; // Mark controls as synced
        }, 50); // Small delay


        // Listen for the sharedTabActivated event and re-sync if this tab becomes active
        document.addEventListener('sharedTabActivated', function(event) {
            if (event.detail.tabId === 'manage-link-page-tab-customize') {
                syncControlsFromCustomVars(); // Re-sync controls when tab is activated
            }
        });

        manager.customization.isInitialized = true;
    };

    // --- Initial call to init() handled by the main manager (manage-link-page.js) ---


    // --- Function to load Google Fonts (can be moved to a dedicated fonts module) ---
    // MOVED to manage-link-page-fonts.js
    // function loadGoogleFont(fontFamily) {
    //     if (!fontFamily || fontFamily.trim() === '') return;
    //     // Remove quotes from font family name for URL
    //     const fontNameForUrl = fontFamily.replace(/['"]/g, '').replace(/\s/g, '+');
    //     const link = document.createElement('link');
    //     link.href = `https://fonts.googleapis.com/css2?family=${fontNameForUrl}:wght@400;700&display=swap`;
    //     link.rel = 'stylesheet';
    //     document.head.appendChild(link);
    // }

    // --- Function to reapply styles after a potential preview refresh (e.g., image upload) ---
    // This function is less necessary with the new architecture where styles are in the main document's head
    // but keeping a placeholder in case complex preview re-rendering logic is added later.
    // manager.customization.reapplyStyles = function() {
    //     syncControlsFromCustomVars(); // Re-sync controls from the style tag
    // };

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});