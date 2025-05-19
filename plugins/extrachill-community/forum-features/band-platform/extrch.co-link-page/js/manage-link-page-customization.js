// Link Page Customization Module
// Handles ALL customization inputs for the link page management UI
(function(manager){
    // Ensure the manager and its necessary properties exist
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Customization script cannot run.');
        return;
    }
    manager.customization = manager.customization || {};
    manager.customization.isInitialized = false; // Flag to prevent multiple initializations

    // --- Constants ---
    // Constants related to sizing have been moved to manage-link-page-sizing.js
    // const FONT_SIZE_MIN_EM = 0.8;
    // const FONT_SIZE_MAX_EM = 3.5;
    // const PROFILE_IMG_SIZE_MIN = 1;
    // const PROFILE_IMG_SIZE_MAX = 100;
    // const PROFILE_IMG_SIZE_DEFAULT = 30;

    // --- Cached DOM Elements (specific to customization controls in this file) ---
    const cssVarsInput = document.getElementById('link_page_custom_css_vars_json');
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

    // --- Canonical customVars object and its initialization ---
    let customVars = {
        '--link-page-bg-color': '#121212',
        '--link-page-card-bg-color': 'rgba(0,0,0,0.4)',
        '--link-page-text-color': '#e5e5e5',
        '--link-page-link-text-color': '#ffffff', // For general link/button text
        '--link-page-button-bg-color': '#0b5394', // UPDATED KEY
        '--link-page-button-hover-bg-color': '#53940b', // UPDATED KEY
        '--link-page-button-hover-text-color': '#ffffff',
        '--link-page-muted-text-color': '#aaa',
        '--link-page-title-font-family': 'WilcoLoftSans',
        '--link-page-title-font-size': '2.1em',
        '--link-page-body-font-family': "'Helvetica', Arial, sans-serif",
        '--link-page-body-font-size': '1em',
        '--link-page-profile-img-size': '30%',
        '--link-page-profile-img-border-radius': '50%',
        '--link-page-profile-img-aspect-ratio': '1/1',
        '_link_page_profile_img_shape': 'circle',
        '--link-page-background-gradient-start': '#0b5394',
        '--link-page-background-gradient-end': '#53940b',
        '--link-page-background-gradient-direction': 'to right',
        '--link-page-background-type': 'color',
        '--link-page-background-image-url': '',
        '--link-page-profile-img-url': '',
        'overlay': '1',
        '--link-page-button-radius': '8px',
        '--link-page-button-border-color': '#0b5394', // Default to button bg color or specific default
        '--link-page-button-border-width': '0px',
        '--link-page-overlay-color': 'rgba(0,0,0,0.5)'
    };

    try {
        if (cssVarsInput && cssVarsInput.value) {
            const parsedJson = JSON.parse(cssVarsInput.value);
            if (typeof parsedJson === 'object' && parsedJson !== null) {
                // Merge parsed JSON into customVars, ensuring all default keys are present
                customVars = { ...customVars, ...parsedJson };
                
                // Ensure border color defaults to button bg color if not set in parsedJson or is the old name
                if (!parsedJson.hasOwnProperty('--link-page-button-border-color') || parsedJson['--link-page-button-border-color'] === undefined) {
                    customVars['--link-page-button-border-color'] = customVars['--link-page-button-bg-color'] || '#0b5394';
                }

                // Handle potential old keys from previously saved JSON for button colors - migrate them
                if (parsedJson.hasOwnProperty('--link-page-button-color') && !parsedJson.hasOwnProperty('--link-page-button-bg-color')) {
                    customVars['--link-page-button-bg-color'] = parsedJson['--link-page-button-color'];
                    delete customVars['--link-page-button-color']; // Remove old key after migration
                }
                if (parsedJson.hasOwnProperty('--link-page-hover-color') && !parsedJson.hasOwnProperty('--link-page-button-hover-bg-color')) {
                    customVars['--link-page-button-hover-bg-color'] = parsedJson['--link-page-hover-color'];
                    delete customVars['--link-page-hover-color']; // Remove old key
                }
            }
        }
    } catch (e) {
        console.error('Error parsing initial custom CSS vars JSON, using defaults:', e);
    }
    // Ensure essential keys always exist with valid defaults after attempting to parse/migrate
    customVars.overlay = (customVars.overlay === '0' || customVars.overlay === '1') ? customVars.overlay : '1';
    customVars._link_page_profile_img_shape = ['circle', 'square', 'rectangle'].includes(customVars._link_page_profile_img_shape) ? customVars._link_page_profile_img_shape : 'circle';
    customVars['--link-page-background-type'] = ['color', 'gradient', 'image'].includes(customVars['--link-page-background-type']) ? customVars['--link-page-background-type'] : 'color';
    
    // Harden gradient values
    customVars['--link-page-background-gradient-start'] = (typeof customVars['--link-page-background-gradient-start'] === 'string' && customVars['--link-page-background-gradient-start'].startsWith('#')) ? customVars['--link-page-background-gradient-start'] : '#0b5394';
    customVars['--link-page-background-gradient-end'] = (typeof customVars['--link-page-background-gradient-end'] === 'string' && customVars['--link-page-background-gradient-end'].startsWith('#')) ? customVars['--link-page-background-gradient-end'] : '#53940b';
    const validDirections = ['to right', 'to left', 'to top', 'to bottom', 'to top right', 'to top left', 'to bottom right', 'to bottom left'];
    if (typeof customVars['--link-page-background-gradient-direction'] === 'string') {
        if (customVars['--link-page-background-gradient-direction'].endsWith('deg')) {
            // Allow 'deg' values, basic check
            if (!/^\d+(\.\d+)?deg$/.test(customVars['--link-page-background-gradient-direction'])) {
                 customVars['--link-page-background-gradient-direction'] = 'to right'; // Fallback for invalid deg
            }
        } else if (!validDirections.includes(customVars['--link-page-background-gradient-direction'])) {
            customVars['--link-page-background-gradient-direction'] = 'to right'; // Fallback for invalid keyword
        }
    } else {
        customVars['--link-page-background-gradient-direction'] = 'to right'; // Fallback if not a string
    }

    // Harden title font family
    if (typeof customVars['--link-page-title-font-family'] !== 'string' || customVars['--link-page-title-font-family'].trim() === '') {
        // Attempt to get a default from the font options if available, otherwise a hardcoded default
        let defaultFontStack = "'WilcoLoftSans', Helvetica, Arial, sans-serif"; // Hardcoded default
        if (typeof manager.fonts !== 'undefined' && typeof manager.fonts.getFontStackByValue === 'function') {
            // Assuming 'WilcoLoftSans' is a valid value in your font config that getFontStackByValue can process
            const configuredDefaultStack = manager.fonts.getFontStackByValue('WilcoLoftSans');
            if (configuredDefaultStack) {
                defaultFontStack = configuredDefaultStack;
            }
        }
        customVars['--link-page-title-font-family'] = defaultFontStack;
    }

    // Harden body font family
    if (typeof customVars['--link-page-body-font-family'] !== 'string' || customVars['--link-page-body-font-family'].trim() === '') {
        let defaultBodyFontStack = "'Helvetica', Arial, sans-serif"; // Default to Helvetica stack
        if (manager.fonts && typeof manager.fonts.getFontStackByValue === 'function') {
            const configuredDefaultStack = manager.fonts.getFontStackByValue('Helvetica'); 
            if (configuredDefaultStack) {
                defaultBodyFontStack = configuredDefaultStack;
            }
        }
        customVars['--link-page-body-font-family'] = defaultBodyFontStack;
    }
    // Add any other critical default enforcements here

    manager.customization.customVars = customVars; // Expose the populated customVars

    // --- Function to update hidden input field ---
    function updateCustomVarsAndHiddenInput() {
        if (cssVarsInput) {
            console.log('[Customization] Updating hidden input. Current customVars object:', JSON.parse(JSON.stringify(manager.customization.customVars))); // Deep copy for logging
            cssVarsInput.value = JSON.stringify(manager.customization.customVars);
        }
    }
    manager.customization.updateCustomVarsAndHiddenInput = updateCustomVarsAndHiddenInput;

    // --- Central function to update a setting and trigger preview update via PreviewUpdater service ---
    manager.customization.updateSetting = function(key, value) {
        console.log(`[Customization] updateSetting called for key: ${key}, value:`, value);
        if (!manager.customization.customVars) {
            console.error('[Customization] customVars not available for updateSetting.');
            return;
        }
        manager.customization.customVars[key] = value;
        updateCustomVarsAndHiddenInput();

        console.log(`[Customization] About to call previewUpdater.update for key: ${key} with value:`, manager.customization.customVars[key]);

        if (manager.previewUpdater && typeof manager.previewUpdater.update === 'function') {
            console.log('[Customization] PreviewUpdater found. Calling update...');
            manager.previewUpdater.update(key, value, manager.customization.customVars);
        } else {
            console.warn(`[Customization] PreviewUpdater service NOT available or update function missing. Cannot update preview for ${key}.`, 
                         'manager.previewUpdater:', manager.previewUpdater);
        }
    };

    // --- Generic Event Listener Attachment Function (for controls managed by this file) ---
    function attachControlListener(element, customVarKey, eventType = 'change', valueTransform = null, isCheckbox = false) {
        if (!element) return;
        element.addEventListener(eventType, function(event) {
            let val = isCheckbox ? event.target.checked : event.target.value;
            if (valueTransform) {
                val = valueTransform(val, event.target);
            }
            manager.customization.updateSetting(customVarKey, val);
        });
    }
    manager.customization.attachControlListener = attachControlListener; 
    
    // --- Getter for customVars (if needed by other modules) ---
    manager.customization.getCustomVars = function() {
        return manager.customization.customVars;
    };

    // --- Function to sync UI controls from customVars (for controls managed by this file) ---
    function syncControlsFromCustomVars() {
        const currentCV = manager.customization.customVars;
        if (!currentCV) {
            console.error('syncControlsFromCustomVars: customVars not found.');
            return;
        }

        if (titleFontFamilySelect) {
            const storedFontFamily = currentCV['--link-page-title-font-family'] || 'WilcoLoftSans';
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

        if (bodyFontFamilySelect) { // Sync for new body font select
            const storedBodyFontFamily = currentCV['--link-page-body-font-family'] || "'Helvetica', Arial, sans-serif"; // Default to Helvetica stack
            let bodyFontValueForSelect = storedBodyFontFamily;
            const currentFontOptions = (typeof window.extrchLinkPageFonts !== 'undefined' && Array.isArray(window.extrchLinkPageFonts)) ? window.extrchLinkPageFonts : [];
            
            // Attempt to find the base value (e.g., 'Helvetica') from the stack for dropdown selection
            const stackParts = storedBodyFontFamily.split(',');
            const firstFontInStack = stackParts[0].trim().replace(/['"]/g, '');
            const foundBodyFontByValueInList = currentFontOptions.find(f => f.value === firstFontInStack);

            if (foundBodyFontByValueInList) {
                bodyFontValueForSelect = foundBodyFontByValueInList.value;
            } else {
                 // Fallback if direct value from stack not in list, try matching full stack, then default to 'Helvetica' value
                const foundBodyFontByStack = currentFontOptions.find(f => f.stack === storedBodyFontFamily);
                if (foundBodyFontByStack) {
                    bodyFontValueForSelect = foundBodyFontByStack.value;
                } else {
                    bodyFontValueForSelect = 'Helvetica'; // Default to Helvetica value for the dropdown
                }
            }
            bodyFontFamilySelect.value = bodyFontValueForSelect;
        }
        // Sizing/Shape related sync logic moved to manage-link-page-sizing.js
        // if (titleFontSizeSlider && titleFontSizeOutput) { ... }
        // if (profileImgSizeSlider && profileImgSizeOutput) { ... }
        // if (profileImgShapeHiddenInput && profileImgShapeCircleRadio && profileImgShapeSquareRadio && profileImgShapeRectangleRadio) { ... } 
        // if (buttonRadiusSlider && buttonRadiusOutput) { ... }

        if (overlayToggle) {
            overlayToggle.checked = currentCV.overlay === '1';
        }

        // Sync Color Pickers
        if (buttonBgColorPicker) {
            buttonBgColorPicker.value = currentCV['--link-page-button-bg-color'] || '#0b5394'; // UPDATED KEY
        }
        if (textColorPicker) {
            textColorPicker.value = currentCV['--link-page-text-color'] || '#e5e5e5';
        }
        if (linkTextColorPicker) {
            linkTextColorPicker.value = currentCV['--link-page-link-text-color'] || '#ffffff';
        }
        if (buttonHoverBgColorPicker) {
            buttonHoverBgColorPicker.value = currentCV['--link-page-button-hover-bg-color'] || '#53940b'; // UPDATED KEY
        }
        if (buttonBorderColorPicker) {
            buttonBorderColorPicker.value = currentCV['--link-page-button-border-color'] || currentCV['--link-page-button-bg-color'] || '#0b5394';
        }

        // Call sync for other modules if they expose such functions
        if (manager.background && typeof manager.background.syncBackgroundInputValues === 'function') {
             manager.background.syncBackgroundInputValues();
        }
        if (manager.colors && typeof manager.colors.syncColorInputValues === 'function') {
             manager.colors.syncColorInputValues();
        }
        if (manager.sizing && typeof manager.sizing.syncSizingInputValues === 'function') { // New call
            manager.sizing.syncSizingInputValues();
        }
    }

    // --- Initialization logic for this customization module ("The Brain") ---
    function initializeCustomizeTab() {
        if (manager.customization.isInitialized) {
            // console.log('Customize tab (Brain) already initialized.');
            return;
        }
        // console.log('Initializing Customize Tab JavaScript (Brain)... ');

        // --- Ensure customVars has a proper font stack for the initial font ---
        if (manager.customization.customVars && typeof manager.customization.customVars['--link-page-title-font-family'] === 'string') {
            const currentFontSetting = manager.customization.customVars['--link-page-title-font-family'];
            // Heuristic: check if it's a simple name (no comma, no single/double quotes which are common in stacks)
            const isSimpleName = !currentFontSetting.includes(',') && !currentFontSetting.includes("'") && !currentFontSetting.includes('"');

            if (isSimpleName) {
                console.log(`[Customization-Init] Initial font setting '${currentFontSetting}' appears to be a simple name. Attempting to convert to stack.`);
                if (manager.fonts && typeof manager.fonts.getFontStackByValue === 'function') {
                    const stack = manager.fonts.getFontStackByValue(currentFontSetting);
                    if (stack && stack !== currentFontSetting) {
                        console.log(`[Customization-Init] Converted initial font '${currentFontSetting}' to stack: '${stack}'`);
                        manager.customization.customVars['--link-page-title-font-family'] = stack;
                        updateCustomVarsAndHiddenInput(); // Ensure hidden input is also updated
                    } else {
                        console.warn(`[Customization-Init] Could not get stack for initial font '${currentFontSetting}', or stack is same as value. Original value kept:`, currentFontSetting);
                    }
                } else {
                    console.warn('[Customization-Init] manager.fonts.getFontStackByValue not available to process initial font. Original value kept:', currentFontSetting);
                }
            } else {
                // console.log(`[Customization-Init] Initial font setting '${currentFontSetting}' already looks like a stack. No conversion needed.`);
            }
        }
        // --- End of initial font stack ensure ---

        // --- Ensure customVars has a proper font stack for the initial body font ---
        if (manager.customization.customVars && typeof manager.customization.customVars['--link-page-body-font-family'] === 'string') {
            const currentBodyFontSetting = manager.customization.customVars['--link-page-body-font-family'];
            const isSimpleNameBody = !currentBodyFontSetting.includes(',') && !currentBodyFontSetting.includes("'") && !currentBodyFontSetting.includes('"');

            if (isSimpleNameBody) {
                console.log(`[Customization-Init] Initial body font setting '${currentBodyFontSetting}' appears to be a simple name. Attempting to convert to stack.`);
                if (manager.fonts && typeof manager.fonts.getFontStackByValue === 'function') {
                    const stack = manager.fonts.getFontStackByValue(currentBodyFontSetting);
                    if (stack && stack !== currentBodyFontSetting) {
                        console.log(`[Customization-Init] Converted initial body font '${currentBodyFontSetting}' to stack: '${stack}'`);
                        manager.customization.customVars['--link-page-body-font-family'] = stack;
                        updateCustomVarsAndHiddenInput();
                    }
                } else {
                    console.warn(`[Customization-Init] manager.fonts.getFontStackByValue not available to process initial body font: ${currentBodyFontSetting}`);
                }
            }
        }

        // --- Proactively load initial fonts ---
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

        // Attach Event Listeners for controls managed directly by this file
        if (titleFontFamilySelect) {
            titleFontFamilySelect.addEventListener('change', function() {
                const selectedFontValue = this.value;
                const googleFontParam = this.options[this.selectedIndex].dataset.googlefontparam;
                // console.log(`[Customization] Title Font Changed. Value: ${selectedFontValue}, GoogleParam: ${googleFontParam}`);

                if (manager.fonts && typeof manager.fonts.loadGoogleFont === 'function') {
                    manager.fonts.loadGoogleFont(googleFontParam, selectedFontValue, function(fontStack) {
                        // console.log(`[Customization] Title Font Loaded Callback. Stack: ${fontStack}`);
                        manager.customization.updateSetting('--link-page-title-font-family', fontStack || selectedFontValue);
                    });
                } else {
                    console.warn('[Customization] manager.fonts.loadGoogleFont not available. Updating setting directly.');
                    const fallbackStack = (manager.fonts && manager.fonts.getFontStackByValue) ? manager.fonts.getFontStackByValue(selectedFontValue) : selectedFontValue;
                    manager.customization.updateSetting('--link-page-title-font-family', fallbackStack);
                }
            });
        }

        if (bodyFontFamilySelect) { // Attach listener for new body font select
            bodyFontFamilySelect.addEventListener('change', function() {
                const selectedFontValue = this.value;
                const googleFontParam = this.options[this.selectedIndex].dataset.googlefontparam;
                // console.log(`[Customization] Body Font Changed. Value: ${selectedFontValue}, GoogleParam: ${googleFontParam}`);

                if (manager.fonts && typeof manager.fonts.loadGoogleFont === 'function') {
                    manager.fonts.loadGoogleFont(googleFontParam, selectedFontValue, function(fontStack) {
                        // console.log(`[Customization] Body Font Loaded Callback. Stack: ${fontStack}`);
                        manager.customization.updateSetting('--link-page-body-font-family', fontStack || selectedFontValue);
                    });
                } else {
                    console.warn('[Customization] manager.fonts.loadGoogleFont not available. Updating body font setting directly.');
                    const fallbackStack = (manager.fonts && manager.fonts.getFontStackByValue) ? manager.fonts.getFontStackByValue(selectedFontValue) : selectedFontValue;
                    manager.customization.updateSetting('--link-page-body-font-family', fallbackStack);
                }
            });
        }
        // Sizing/Shape related event listeners (profile image shape radios) moved to manage-link-page-sizing.js
        // function handleProfileShapeChange(event) { ... }
        // if (profileImgShapeCircleRadio) profileImgShapeCircleRadio.addEventListener('change', handleProfileShapeChange);
        // if (profileImgShapeSquareRadio) profileImgShapeSquareRadio.addEventListener('change', handleProfileShapeChange);
        // if (profileImgShapeRectangleRadio) profileImgShapeRectangleRadio.addEventListener('change', handleProfileShapeChange);
        
        if (overlayToggle) {
            attachControlListener(overlayToggle, 'overlay', 'change', (checked) => checked ? '1' : '0', true);
        }

        // Attach Event Listeners for Color Pickers
        if (buttonBgColorPicker) {
            attachControlListener(buttonBgColorPicker, '--link-page-button-bg-color', 'input');
        }
        if (textColorPicker) {
            attachControlListener(textColorPicker, '--link-page-text-color', 'input');
        }
        if (linkTextColorPicker) {
            attachControlListener(linkTextColorPicker, '--link-page-link-text-color', 'input');
        }
        if (buttonHoverBgColorPicker) {
            attachControlListener(buttonHoverBgColorPicker, '--link-page-button-hover-bg-color', 'input');
        }
        if (buttonBorderColorPicker) {
            attachControlListener(buttonBorderColorPicker, '--link-page-button-border-color', 'input');
        }

        // Sync all UI controls based on the populated customVars
        if (!controlsInitialized) {
            syncControlsFromCustomVars();
            controlsInitialized = true;
        }

        // Trigger a full preview refresh using the PreviewUpdater service
        // Ensure PreviewUpdater is ready
        if (manager.previewUpdater && typeof manager.previewUpdater.refreshFullPreview === 'function') {
            console.log(`[Customization] Font family in customVars BEFORE calling previewUpdater.refreshFullPreview (in init):`, manager.customization.customVars['--link-page-title-font-family']);
            manager.previewUpdater.refreshFullPreview(manager.customization.customVars);
        } else {
            // Fallback or wait if PreviewUpdater isn't ready (e.g. listen for its own init event)
            console.warn('PreviewUpdater service not available at init of customization.js. Full preview refresh might be delayed.');
            // Potentially set up a listener for an event like 'extrchLinkPagePreviewUpdaterInitialized'
            document.addEventListener('extrchLinkPagePreviewUpdaterInitialized', function onPreviewUpdaterReady(){
                if (manager.previewUpdater && typeof manager.previewUpdater.refreshFullPreview === 'function') {
                    manager.previewUpdater.refreshFullPreview(manager.customization.customVars);
                }
                document.removeEventListener('extrchLinkPagePreviewUpdaterInitialized', onPreviewUpdaterReady);
            }, { once: true });
        }
        
        // Sync background UI type select visibility (if background module is separate and ready)
        if (window.ExtrchLinkPageManager.background && 
            typeof window.ExtrchLinkPageManager.background.updateBackgroundTypeUI === 'function' && 
            manager.customization.customVars && 
            manager.customization.customVars['--link-page-background-type']) {
            // This relies on background.js having already synced its own inputs if needed
            window.ExtrchLinkPageManager.background.updateBackgroundTypeUI(manager.customization.customVars['--link-page-background-type']);
        }
        
        manager.customization.isInitialized = true;
        const event = new Event('extrchLinkPageCustomizeTabInitialized');
        document.dispatchEvent(event);
        // console.log('Customize Tab JavaScript (Brain) Initialized.');
    }
    manager.customization.init = initializeCustomizeTab;

    // --- DOMContentLoaded listener to kick things off ---
    document.addEventListener('DOMContentLoaded', function() {
        // customVars is populated at the top of this IIFE when the script loads.
        // Manager and customization module core functions (updateSetting, etc.) are also set up.
        
        // Dispatch event so other modules know the 'brain' (customization.js) 
        // and its core API (like updateSetting, getCustomVars) are ready.
        const managerReadyEvent = new Event('extrchLinkPageManagerInitialized');
        document.dispatchEvent(managerReadyEvent);

        // Call initializeCustomizeTab directly on DOMContentLoaded.
        // This function handles its own isInitialized check to prevent multiple runs.
            initializeCustomizeTab();

        // Removed active tab check and delayed initialization logic:
        // const customizeTabContentPanel = document.getElementById('manage-link-page-tab-customize');
        // let isCustomizeTabActive = false;
        // if (customizeTabContentPanel) {
        //     const style = window.getComputedStyle(customizeTabContentPanel);
        //     if (style.display !== 'none' && style.visibility !== 'hidden' || 
        //         customizeTabContentPanel.classList.contains('active-content') || 
        //         customizeTabContentPanel.classList.contains('active')) {
        //          isCustomizeTabActive = true;
        //     }
        // }
        // const customizeTabButton = document.querySelector('.manage-link-page-tab[data-tab="customize"].active');
        // if(customizeTabButton) isCustomizeTabActive = true;
        // 
        // if (isCustomizeTabActive) {
        //     initializeCustomizeTab();
        // } else {
        //     // Optional: If tab is not active, one might set up a listener for when it *becomes* active 
        //     // to call initializeCustomizeTab(). This depends on the tab switching mechanism.
        //     // Example: (pseudo-code, actual tab library would have its own events)
        //     // document.body.addEventListener('tabSwitchedToCustomize', initializeCustomizeTab, { once: true });
        // }
    });

    function reapplyStyles() {
        const currentCustomVars = getCustomVars(); 

        if (manager.previewUpdater && typeof manager.previewUpdater.refreshFullPreview === 'function') {
            manager.previewUpdater.refreshFullPreview(currentCustomVars);
        } else {
            // Fallback or error
        }
    }

})(window.ExtrchLinkPageManager);