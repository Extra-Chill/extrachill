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
        console.log(`[Customization] Attaching listener to ${element.id || element.name} for key ${customVarKey}`);
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
                manager.customization.updateSetting(customVarKey, val);
            });
        }
    }
    manager.customization.attachControlListener = attachControlListener; 
    
    // --- Getter for customVars (for serialization before save) ---
    manager.customization.getCustomVars = function() {
        // Parse the style tag for all CSS vars
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (!styleTag) return {};
        let cssText = styleTag.textContent;
        let match = cssText.match(/:root\s*{([^}]*)}/);
        let varsBlock = match ? match[1] : '';
        let vars = {};
        varsBlock.split(';').forEach(pair => {
            const [k, v] = pair.split(':').map(s => s && s.trim());
            if (k && v && k.startsWith('--')) {
                vars[k] = v;
            }
        });
        // Add overlay if present
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
        console.log('[Customization] Syncing controls from customVars:', currentCV);

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

        if (overlayToggle) {
            overlayToggle.checked = (typeof currentCV.overlay === 'undefined') ? true : currentCV.overlay === '1';
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
    // This function will be called by the main manager on DOMContentLoaded
    manager.customization.init = function() {
        if (manager.customization.isInitialized) {
            return;
        }
        console.log('[Customization] Initializing Customization Module...');
        customVarsInput = document.getElementById('link_page_custom_css_vars_json');

        let phpHydratedVars = {};
        if (customVarsInput && customVarsInput.value) {
            try {
                phpHydratedVars = JSON.parse(customVarsInput.value);
                 // Migrate old keys if needed (but do not overwrite any present value)
                if (phpHydratedVars.hasOwnProperty('--link-page-button-color') && !phpHydratedVars.hasOwnProperty('--link-page-button-bg-color')) {
                    phpHydratedVars['--link-page-button-bg-color'] = phpHydratedVars['--link-page-button-color'];
                    delete phpHydratedVars['--link-page-button-color'];
                }
                if (phpHydratedVars.hasOwnProperty('--link-page-hover-color') && !phpHydratedVars.hasOwnProperty('--link-page-button-hover-bg-color')) {
                    phpHydratedVars['--link-page-button-hover-bg-color'] = phpHydratedVars['--link-page-hover-color'];
                    delete phpHydratedVars['--link-page-hover-color'];
                }
            } catch (e) {
                console.error('Error parsing custom CSS vars JSON from hidden input, using defaults:', e, customVarsInput.value);
                phpHydratedVars = {};
            }
        } else {
            console.warn('Custom CSS vars hidden input not found or empty, using defaults.');
            phpHydratedVars = {};
        }
        
        // Merge defaults < phpHydratedVars
        customVars = { ...phpHydratedVars };
        manager.customization.customVars = customVars; // Expose the populated customVars

        // Now that customVars is properly hydrated, sync controls.
        // DO NOT call refreshFullPreview here. Initial styling is handled by PHP.
        if (typeof syncControlsFromCustomVars === 'function') {
            syncControlsFromCustomVars();
        }

        // Initialize event listeners for customization controls
        initializeCustomizeTabEventListeners(); // Separate function for attaching listeners

        // Proactively load initial Google Fonts
        loadInitialFonts();

        manager.customization.isInitialized = true;
        console.log('[Customization] Customization Module Initialized with vars:', customVars);
    };

    // --- Function to attach event listeners to controls ---
    function initializeCustomizeTabEventListeners() {
        console.log('[Customization] Initializing Customize Tab event listeners.');
        console.log('Attempting to find titleFontFamilySelect:', document.getElementById('link_page_title_font_family'));
        console.log('Attempting to find bodyFontFamilySelect:', document.getElementById('link_page_body_font_family'));

        // Attach listeners for controls managed by this file
        // Ensure elements are defined before attaching listeners
        if (titleFontFamilySelect) attachControlListener(titleFontFamilySelect, '--link-page-title-font-family');
        if (bodyFontFamilySelect) attachControlListener(bodyFontFamilySelect, '--link-page-body-font-family');
        if (overlayToggle) attachControlListener(overlayToggle, 'overlay', 'change', val => val ? '1' : '0', true);

        // Color Pickers
        if (buttonBgColorPicker) attachControlListener(buttonBgColorPicker, '--link-page-button-bg-color', 'input');
        if (textColorPicker) attachControlListener(textColorPicker, '--link-page-text-color', 'input');
        if (linkTextColorPicker) attachControlListener(linkTextColorPicker, '--link-page-link-text-color', 'input');
        if (buttonHoverBgColorPicker) attachControlListener(buttonHoverBgColorPicker, '--link-page-button-hover-bg-color', 'input');
        if (buttonBorderColorPicker) attachControlListener(buttonBorderColorPicker, '--link-page-button-border-color', 'input');
        
        // Note: Listeners for background, colors, sizing, etc. should be in their respective modules.
        // This function only attaches listeners for controls handled *within* this customization module file.
    }


    // --- Function to proactively load initial fonts ---
     function loadInitialFonts() {
        if (manager.fonts && typeof manager.fonts.loadGoogleFont === 'function' && typeof manager.fonts.getGoogleFontParamByValue === 'function') {
            const initialTitleFontValue = titleFontFamilySelect ? titleFontFamilySelect.value : manager.customization.customVars['--link-page-title-font-family'];
            const initialTitleFontParam = manager.fonts.getGoogleFontParamByValue(initialTitleFontValue);
            if (initialTitleFontParam) {
                manager.fonts.loadGoogleFont(initialTitleFontParam, initialTitleFontValue, function(){ /* console.log('Initial title font loaded or load attempted.'); */ });
            }

            const initialBodyFontValue = bodyFontFamilySelect ? bodyFontFamilySelect.value : (manager.customization.customVars['--link-page-body-font-family'] || 'Helvetica');
            // For getGoogleFontParamByValue, we need the simple value (e.g., 'Helvetica') not the stack
            let simpleInitialBodyFontValue = initialBodyFontValue;
            if (initialBodyFontValue.includes(',')) {
                simpleInitialBodyFontValue = initialBodyFontValue.split(',')[0].trim().replace(/['"]/g, '');
            }
            const initialBodyFontParam = manager.fonts.getGoogleFontParamByValue(simpleInitialBodyFontValue);
            if (initialBodyFontParam && initialBodyFontParam !== 'local_default') {
                manager.fonts.loadGoogleFont(initialBodyFontParam, simpleInitialBodyFontValue, function(){ /* console.log('Initial body font loaded or load attempted.'); */ });
            }
        }
    }
    

    // Removed: This module will be initialized by the main ExtrchLinkPageManager on its DOMContentLoaded.
    // document.addEventListener('DOMContentLoaded', function() { ... });

    function reapplyStyles() {
        const currentCustomVars = manager.customization.getCustomVars(); 

        if (manager.previewUpdater && typeof manager.previewUpdater.refreshFullPreview === 'function') {
            manager.previewUpdater.refreshFullPreview(currentCustomVars);
        } else {
            // Fallback or error
        }
    }

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});