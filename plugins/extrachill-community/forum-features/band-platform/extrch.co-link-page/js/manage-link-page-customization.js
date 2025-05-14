// Link Page Customization Module
// Handles ALL customization inputs for the link page management UI
(function(manager){
    // Ensure the manager and its necessary properties exist
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Customization script cannot run.');
        return;
    }
    manager.customization = manager.customization || {};

    // --- Constants for Font Size Percentage Scaling ---
    const FONT_SIZE_MIN_EM = 0.8;
    const FONT_SIZE_MAX_EM = 3.5;
    // --- End Constants ---

    // CSS Vars Elements - Caching DOM elements is good practice
    const cssVarsInput = document.getElementById('link_page_custom_css_vars_json');
    const buttonColorInput = document.getElementById('link_page_button_color');
    const textColorInput = document.getElementById('link_page_text_color');
    const linkTextColorInput = document.getElementById('link_page_link_text_color');
    const hoverColorInput = document.getElementById('link_page_hover_color');
    const profileImgShapeHiddenInput = document.getElementById('link_page_profile_img_shape_hidden');
    const profileImgShapeToggle = document.getElementById('link_page_profile_img_shape_toggle');
    const titleFontFamilySelect = document.getElementById('link_page_title_font_family');
    const titleFontSizeSlider = document.getElementById('link_page_title_font_size'); // Added
    const titleFontSizeOutput = document.getElementById('title_font_size_output'); // Added
    const profileImgSizeSlider = document.getElementById('link_page_profile_img_size');
    const profileImgSizeOutput = document.getElementById('profile_img_size_output');
    const PROFILE_IMG_SIZE_MIN = 1;
    const PROFILE_IMG_SIZE_MAX = 100;
    const PROFILE_IMG_SIZE_DEFAULT = 30;

    // Direct Background Elements - MOVED to manage-link-page-background.js
    // const typeSelectInput = document.getElementById('link_page_background_type');
    // const bgColorInput = document.getElementById('link_page_background_color');
    // const gradStartInput = document.getElementById('link_page_background_gradient_start');
    // const gradEndInput = document.getElementById('link_page_background_gradient_end');
    // const gradDirInput = document.getElementById('link_page_background_gradient_direction');

    // UI Visibility for Background Sections - MOVED to manage-link-page-background.js
    // const colorControls = document.getElementById('background-color-controls');
    // const gradientControls = document.getElementById('background-gradient-controls');
    // const imageControls = document.getElementById('background-image-controls');

    const validCssVarKeys = [ // These are CSS variables NOT related to direct background properties
        '--link-page-button-color',
        '--link-page-text-color',
        '--link-page-link-text-color',
        '--link-page-hover-color',
        '--link-page-title-font-family',
        '--link-page-title-font-size', // Added
        '--link-page-profile-img-size' // Added
    ];

    /**
     * Centralized Customizer Schema for Link Page
     *
     * All customization state is held in this single JS object.
     * Add new settings here and update UI/preview logic accordingly.
     *
     * Schema:
     * {
     *   '--link-page-button-color': string (hex color),
     *   '--link-page-text-color': string (hex color),
     *   '--link-page-link-text-color': string (hex color),
     *   '--link-page-hover-color': string (hex color),
     *   '--link-page-title-font-family': string (font stack),
     *   '--link-page-title-font-size': string (e.g. '2.1em'),
     *   '--link-page-profile-img-size': string (e.g. '30%'),
     *   'overlay': '1' | '0',
     *   // Add new keys here as needed
     * }
     */
    const customVars = {
        '--link-page-button-color': '#0b5394',
        '--link-page-text-color': '#e5e5e5',
        '--link-page-link-text-color': '#ffffff',
        '--link-page-hover-color': '#083b6c',
        '--link-page-title-font-family': 'WilcoLoftSans',
        '--link-page-title-font-size': '2.1em',
        '--link-page-profile-img-size': '30%',
        'overlay': '1',
        // Add new keys and defaults here as needed
    };
    // Font-related constants and functions are now in manage-link-page-fonts.js
    // and available via manager.fonts.
    // Font-related constants and functions are now in manage-link-page-fonts.js
    // and available via manager.fonts.
    // For FONT_OPTIONS data, directly use window.extrchLinkPageFonts when needed.

    // Populate customVars from cssVarsInput.value
    try {
        if (cssVarsInput && cssVarsInput.value) {
            const parsedJson = JSON.parse(cssVarsInput.value);
            if (typeof parsedJson === 'object' && parsedJson !== null) {
                customVars = parsedJson; // Use the full parsed JSON object
            } else {
                customVars = {}; // Parsed but not an object
            }
        } else {
            customVars = {}; // No value in input, start with empty customVars
        }
    } catch (e) {
        customVars = {}; // Reset on error
        console.error('Error parsing initial custom CSS vars JSON:', e);
    }
    // Ensure overlay is always present in customVars (default ON)
    if (typeof customVars.overlay === 'undefined') {
        customVars.overlay = '1';
        if (cssVarsInput) cssVarsInput.value = JSON.stringify(customVars);
    }

    // --- Initial UI Sync and Preview Update ---
    // 1. Synchronize all input fields with customVars (or their defaults if not in customVars)
    setInputFieldsFromCustomVars(); // This will set slider to % based on customVars or to 50% default.

    // 2. Ensure customVars has a value for font size for the initial preview,
    //    based on the slider's state after setInputFieldsFromCustomVars.
    if (titleFontSizeSlider && !customVars['--link-page-title-font-size']) {
        // If font size wasn't in saved JSON (so slider is at its default e.g., 50%),
        // calculate the corresponding em value and add it to customVars for the initial preview.
        const sliderPercentage = parseInt(titleFontSizeSlider.value, 10);
        const calculatedEm = FONT_SIZE_MIN_EM + (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * (sliderPercentage / 100);
        customVars['--link-page-title-font-size'] = calculatedEm.toFixed(2) + 'em';
    }
    // (No need to do this for other vars like colors, as they have direct input fields
    // and their values are read directly in handleCssVarsInputChange or have CSS fallbacks)

    // 3. Update the live preview CSS variables based on the (potentially now augmented) customVars.
    updateLivePreviewCssVars();
 
    // Initial setup for background type - MOVED to manage-link-page-background.js
    // const initialBgType = manager.initialData && manager.initialData.background_type ? manager.initialData.background_type : 'color';
    // if (typeSelectInput) {
    // typeSelectInput.value = initialBgType;
    // }
    // updateBackgroundTypeUI(initialBgType); // Now called by background module's init
    // updateLivePreviewBackgroundStyle(); // Now called by background module's init

    // --- Preview Update Functions ---
    function updateLivePreviewCssVars() {
        // Scope to the new modular preview container
        const previewContainer = document.querySelector('.extrch-link-page-preview-container');
        if (!previewContainer) return;

        // Handle profile image shape class separately
        const profileImageDiv = previewContainer.querySelector('.extrch-link-page-profile-img');
        if (profileImageDiv) {
            profileImageDiv.classList.remove('shape-circle', 'shape-square');
            const currentShape = customVars['_link_page_profile_img_shape'] || 'square'; // Default to square
            if (currentShape === 'circle') {
                profileImageDiv.classList.add('shape-circle');
            } else if (currentShape === 'square') {
                profileImageDiv.classList.add('shape-square');
            } else {
                profileImageDiv.classList.add('shape-square'); // Default fallback
            }
        }

        for (const key of Object.keys(customVars)) {
            // Defer font family application to its specific handler (updatePreviewTitleFontFamily)
            if (key === '--link-page-title-font-family') {
                continue;
            }
            if (customVars[key] !== undefined && customVars[key] !== '') {
                previewContainer.style.setProperty(key, customVars[key]);
            } else {
                previewContainer.style.removeProperty(key);
            }
        }
    }
 
    // updateLivePreviewBackgroundStyle() - MOVED to manage-link-page-background.js
    
    function handleCssVarsInputChange(event) {
        const newValues = {};
        let fontChanged = false;
        const changedElement = event ? event.target : null;

        // 1. Collect all current values from inputs
        if (buttonColorInput) newValues['--link-page-button-color'] = buttonColorInput.value;
        if (textColorInput) newValues['--link-page-text-color'] = textColorInput.value;
        if (linkTextColorInput) newValues['--link-page-link-text-color'] = linkTextColorInput.value;
        if (hoverColorInput) newValues['--link-page-hover-color'] = hoverColorInput.value;
        
        // Profile image shape is handled directly by its own event listener now
        // and updates customVars directly. It's not a CSS var anymore.

        if (titleFontSizeSlider) {
            const sliderPercentage = parseInt(titleFontSizeSlider.value, 10);
            const calculatedEm = FONT_SIZE_MIN_EM + (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * (sliderPercentage / 100);
            newValues['--link-page-title-font-size'] = calculatedEm.toFixed(2) + 'em';
        }
        if (titleFontFamilySelect) {
            const selectedFontValue = titleFontFamilySelect.value;
            if (manager.fonts && typeof manager.fonts.getFontStackByValue === 'function') {
                newValues['--link-page-title-font-family'] = manager.fonts.getFontStackByValue(selectedFontValue);
                if (changedElement === titleFontFamilySelect) {
                    fontChanged = true;
                }
            }
        }

        // 2. Update customVars and the hidden JSON input
        let dataChanged = false;
        for (const key in newValues) {
            if (newValues.hasOwnProperty(key) && customVars[key] !== newValues[key]) {
                customVars[key] = newValues[key];
                dataChanged = true;
            }
        }
        // Ensure all valid keys are present in customVars, even if undefined (to clear them if needed)
        for (const key of validCssVarKeys) {
            if (!newValues.hasOwnProperty(key) && customVars.hasOwnProperty(key)) {
                delete customVars[key]; // Or set to a default/empty if preferred
                dataChanged = true;
            }
        }

        if (dataChanged && cssVarsInput) {
            cssVarsInput.value = JSON.stringify(customVars);
        }

        // 3. Apply non-font CSS variables and profile shape class to the preview immediately
        updateLivePreviewCssVars(); 

        // 4. Handle font family change separately and asynchronously
        if (fontChanged && titleFontFamilySelect) {
            const selectedFontValue = titleFontFamilySelect.value;
            if (manager.fonts && typeof manager.fonts.getGoogleFontParamByValue === 'function' && typeof manager.fonts.loadGoogleFont === 'function') {
                const fontParam = manager.fonts.getGoogleFontParamByValue(selectedFontValue);
                manager.fonts.loadGoogleFont(fontParam, selectedFontValue, function() {
                    if (manager.fonts && typeof manager.fonts.updatePreviewTitleFontFamily === 'function') {
                        manager.fonts.updatePreviewTitleFontFamily(selectedFontValue); // Applies font to preview
                    }
                    // After font is loaded and applied, trigger full AJAX update
                    if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX();
                });
            }
        } else if (dataChanged) { // If other non-font data changed, trigger AJAX update
            if (manager.updatePreviewViaAJAX) {
                 // Debounce for text-like inputs if event is available, otherwise call directly
                if (event && (event.target.type === 'text' || event.target.type === 'textarea')) {
                    if (!manager.debouncedUpdatePreview) { // Create debounced version if not exists
                        manager.debouncedUpdatePreview = debounce(manager.updatePreviewViaAJAX, 350);
                    }
                    manager.debouncedUpdatePreview();
                } else {
                    manager.updatePreviewViaAJAX();
                }
            }
        }
    }

    // const inputsForCssVars = [  // THIS ARRAY IS NO LONGER USED FOR EVENT LISTENERS
    // ];
    // inputsForCssVars.forEach(item => {
    // }); // THIS LOOP IS NO LONGER USED

    // --- Individual Event Listeners ---
    if (buttonColorInput) buttonColorInput.addEventListener('input', handleCssVarsInputChange);
    if (textColorInput) textColorInput.addEventListener('input', handleCssVarsInputChange);
    if (linkTextColorInput) linkTextColorInput.addEventListener('input', handleCssVarsInputChange);
    if (hoverColorInput) hoverColorInput.addEventListener('input', handleCssVarsInputChange);
    if (titleFontFamilySelect) titleFontFamilySelect.addEventListener('change', handleCssVarsInputChange);

    // Dedicated listener for Title Font Size Slider for immediate output update
    if (titleFontSizeSlider && titleFontSizeOutput) {
        titleFontSizeSlider.addEventListener('input', function() {
            const sliderPercentage = parseInt(this.value, 10);
            titleFontSizeOutput.textContent = sliderPercentage + '%'; // Update output display immediately
            
            handleCssVarsInputChange(); // This calculates and sets the CSS var

            // --- Direct DOM manipulation TEST ---
            // After handleCssVarsInputChange, customVars should be updated
            const emFontSizeForDirectTest = customVars['--link-page-title-font-size'];
            const previewTitleElement = document.querySelector('.manage-link-page-preview-live .extrch-link-page-title');
            
            if (previewTitleElement && emFontSizeForDirectTest) {
                previewTitleElement.style.fontSize = emFontSizeForDirectTest;
            } else {
            }
            // --- END Direct DOM manipulation TEST ---
        });
    }

    function updateBackgroundTypeUI(currentType) {
        const typeSel = document.getElementById('link_page_background_type'); // Query here too or use passed arg
        if (!typeSel && !currentType) {
            return;
        }
        const val = currentType || (typeSel ? typeSel.value : null);
        if(colorControls) colorControls.style.display = (val === 'color') ? '' : 'none';
        if(gradientControls) gradientControls.style.display = (val === 'gradient') ? '' : 'none';
        if(imageControls) imageControls.style.display = (val === 'image') ? '' : 'none';
    }
 
    // Listeners for Direct Background Properties - MOVED to manage-link-page-background.js
    // const backgroundInputs = [ ... ];
    // backgroundInputs.forEach(item => { ... });

    // Expose reapplyAllLiveStyles via the manager object
    // This function will now primarily reapply CSS vars and call the background module's reapply if needed.
    manager.customization.reapplyStyles = function reapplyAllLiveStyles() {
        // Always use the canonical customVars object
        updateLivePreviewCssVars(); // Applies all CSS vars and shape classes
        // Call the background module's reapply/update function if it exists
        if (manager.background && typeof manager.background.updatePreview === 'function') {
            manager.background.updatePreview();
        }
    };

    function setInputFieldsFromCustomVars() {
        customVars = typeof customVars === 'object' && customVars !== null ? customVars : {};

        // Helper to set individual input values safely
        const setInput = (inputEl, varKey, explicitDefault) => {
            if (inputEl) {
                const savedValue = customVars[varKey];
                inputEl.value = (savedValue !== undefined && savedValue !== '') ? savedValue : explicitDefault;
            }
        };
        
        // CSS Vars controlled inputs (excluding background-color as it's not a CSS var here)
        setInput(buttonColorInput, '--link-page-button-color', '#0b5394');
        setInput(textColorInput, '--link-page-text-color', '#e5e5e5');
        setInput(linkTextColorInput, '--link-page-link-text-color', '#ffffff');
        setInput(hoverColorInput, '--link-page-hover-color', '#083b6c');
        
        // Set Profile Image Shape Toggle and Hidden Input
        if (profileImgShapeToggle && profileImgShapeHiddenInput) {
            const savedShape = customVars['_link_page_profile_img_shape'] || 'square'; // Default to square
            profileImgShapeHiddenInput.value = savedShape;
            profileImgShapeToggle.checked = (savedShape === 'circle');
            // Update the display text next to the toggle
            const shapeValueDisplay = document.getElementById('profile-img-shape-value');
            if (shapeValueDisplay) {
                shapeValueDisplay.textContent = (savedShape === 'circle') ? 'Circle' : 'Square';
            }
        }
        if (titleFontFamilySelect && customVars.hasOwnProperty('--link-page-title-font-family')) {
            // Find the font value by matching the stack or value
            const storedFontFamily = customVars['--link-page-title-font-family'];
            let fontValueForSelect = 'WilcoLoftSans'; // Default

            const currentFontOptions = (typeof window.extrchLinkPageFonts !== 'undefined' && Array.isArray(window.extrchLinkPageFonts)) ? window.extrchLinkPageFonts : [];
            const foundFontByStack = currentFontOptions.find(f => f.stack === storedFontFamily);
            if (foundFontByStack) {
                fontValueForSelect = foundFontByStack.value;
            } else {
                const foundFontByValue = currentFontOptions.find(f => f.value === storedFontFamily);
                if (foundFontByValue) {
                    fontValueForSelect = foundFontByValue.value;
                }
            }
            titleFontFamilySelect.value = fontValueForSelect;

            // Use the font module
            if (manager.fonts && typeof manager.fonts.getGoogleFontParamByValue === 'function' && typeof manager.fonts.loadGoogleFont === 'function') {
                const fontParam = manager.fonts.getGoogleFontParamByValue(titleFontFamilySelect.value);
                manager.fonts.loadGoogleFont(fontParam, titleFontFamilySelect.value, function() {
                    if (manager.fonts && typeof manager.fonts.updatePreviewTitleFontFamily === 'function') {
                        manager.fonts.updatePreviewTitleFontFamily(titleFontFamilySelect.value);
                    }
                });
            }
        }
        if (titleFontSizeSlider && titleFontSizeOutput) {
            if (customVars.hasOwnProperty('--link-page-title-font-size')) {
                const savedEmString = customVars['--link-page-title-font-size'];
                const savedEmValue = parseFloat(savedEmString);
                if (!isNaN(savedEmValue)) {
                    const percentage = (savedEmValue - FONT_SIZE_MIN_EM) / (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM);
                    let sliderValue = Math.round(percentage * 100);
                    sliderValue = Math.max(1, Math.min(100, sliderValue)); // Clamp
                    titleFontSizeSlider.value = sliderValue;
                    titleFontSizeOutput.textContent = sliderValue + '%'; // CORRECTED: Display percentage
                } else {
                    // Fallback if parsing savedEmString fails
                    const defaultSliderValue = 50;
                    titleFontSizeSlider.value = defaultSliderValue;
                    titleFontSizeOutput.textContent = defaultSliderValue + '%';
                }
            } else {
                // If '--link-page-title-font-size' is not in customVars (e.g., new page)
                const defaultSliderValue = 50;
                titleFontSizeSlider.value = defaultSliderValue;
                titleFontSizeOutput.textContent = defaultSliderValue + '%';
            }
        }
        // On load, initialize the slider from saved value or default
        if (profileImgSizeSlider && profileImgSizeOutput) {
            let savedSize = PROFILE_IMG_SIZE_DEFAULT;
            if (typeof customVars['--link-page-profile-img-size'] !== 'undefined') {
                // Strip % and parse as int
                savedSize = parseInt(customVars['--link-page-profile-img-size'].toString().replace('%',''), 10);
                if (isNaN(savedSize)) savedSize = PROFILE_IMG_SIZE_DEFAULT;
            }
            profileImgSizeSlider.value = savedSize;
            profileImgSizeOutput.textContent = savedSize + '%';
            // Set CSS var for preview
            const previewContainer = document.querySelector('.extrch-link-page-preview-container');
            if (previewContainer) {
                previewContainer.style.setProperty('--link-page-profile-img-size', savedSize + '%');
            }
            // Save to customVars as a string with %
            customVars['--link-page-profile-img-size'] = savedSize + '%';
            if (cssVarsInput) cssVarsInput.value = JSON.stringify(customVars);
        }
        // Listen for slider changes
        if (profileImgSizeSlider && profileImgSizeOutput) {
            profileImgSizeSlider.addEventListener('input', function() {
                const val = parseInt(this.value, 10);
                profileImgSizeOutput.textContent = val + '%';
                // Set CSS var for preview
                const previewContainer = document.querySelector('.extrch-link-page-preview-container');
                if (previewContainer) {
                    previewContainer.style.setProperty('--link-page-profile-img-size', val + '%');
                }
                // Save to customVars as a string with %
                customVars['--link-page-profile-img-size'] = val + '%';
                if (cssVarsInput) cssVarsInput.value = JSON.stringify(customVars);
                if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX();
            });
        }
    }

    // --- Event Listeners ---
    // Add event listeners to relevant inputs
    const listenTo = [
        buttonColorInput, textColorInput, linkTextColorInput, hoverColorInput, 
        titleFontFamilySelect, titleFontSizeSlider
    ];
    listenTo.forEach(input => {
        if (input) {
            input.addEventListener('input', handleCssVarsInputChange);
            if (input.type === 'color') { // Also trigger on change for color pickers (e.g., when dialog closes)
                input.addEventListener('change', handleCssVarsInputChange);
            }
        }
    });

    // Specific listener for Profile Image Shape Toggle
    if (profileImgShapeToggle && profileImgShapeHiddenInput) {
        profileImgShapeToggle.addEventListener('change', function(event) {
            const newShape = event.target.checked ? 'circle' : 'square';
            profileImgShapeHiddenInput.value = newShape;
            customVars['_link_page_profile_img_shape'] = newShape; // Update customVars directly
            
            // Update the display text
            const shapeValueDisplay = document.getElementById('profile-img-shape-value');
            if (shapeValueDisplay) {
                shapeValueDisplay.textContent = (newShape === 'circle') ? 'Circle' : 'Square';
            }

            updateLivePreviewCssVars(); // This will now also update shape classes
            if (cssVarsInput) {
                cssVarsInput.value = JSON.stringify(customVars);
            }
            if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX(); // Trigger full AJAX update
        });
    }

    // Event listener for typeSelectInput - MOVED to manage-link-page-background.js
    // if (typeSelectInput) { ... }

    // Overlay toggle for card background/shadow
    const overlayToggle = document.getElementById('link_page_overlay_toggle');
    if (overlayToggle) {
        // On change, update customVars and the preview
        overlayToggle.addEventListener('change', function() {
            const wrapper = document.querySelector('.extrch-link-page-content-wrapper');
            const overlayVal = this.checked ? '1' : '0';
            customVars.overlay = overlayVal;
            if (cssVarsInput) cssVarsInput.value = JSON.stringify(customVars);
            if (wrapper) {
                if (this.checked) {
                    wrapper.classList.remove('no-overlay');
                } else {
                    wrapper.classList.add('no-overlay');
                }
            }
        });
        // On load, sync checkbox state from customVars if present
        if (typeof customVars.overlay !== 'undefined') {
            overlayToggle.checked = customVars.overlay === '1';
        }
        // Initial state on load
        const wrapper = document.querySelector('.extrch-link-page-content-wrapper');
        if (wrapper) {
            if (overlayToggle.checked) {
                wrapper.classList.remove('no-overlay');
            } else {
                wrapper.classList.add('no-overlay');
            }
        }
    }

    // --- Dynamic Overlay Color Based on Background Color ---
    function hexToRgb(hex) {
        // Remove # if present
        hex = hex.replace(/^#/, '');
        if (hex.length === 3) {
            hex = hex.split('').map(x => x + x).join('');
        }
        const num = parseInt(hex, 16);
        return {
            r: (num >> 16) & 255,
            g: (num >> 8) & 255,
            b: num & 255
        };
    }
    function getOverlayColor(bgColor) {
        // bgColor: hex string, e.g. "#1a1a1a"
        if (!bgColor || typeof bgColor !== 'string' || !bgColor.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)) {
            return 'rgba(0,0,0,0.35)'; // fallback
        }
        const rgb = hexToRgb(bgColor);
        const luminance = (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;
        if (luminance < 0.5) {
            return 'rgba(255,255,255,0.12)'; // light overlay for dark bg
        } else {
            return 'rgba(0,0,0,0.18)'; // dark overlay for light bg
        }
    }
    function updateOverlayColorFromBg() {
        // Try to get the current background color from the input or customVars
        let bgColor = null;
        if (typeof customVars['--link-page-background-color'] === 'string') {
            bgColor = customVars['--link-page-background-color'];
        } else {
            // Try to get from the background color input if present
            const bgColorInput = document.getElementById('link_page_background_color');
            if (bgColorInput && bgColorInput.value) {
                bgColor = bgColorInput.value;
            }
        }
        if (bgColor) {
            const overlayColor = getOverlayColor(bgColor);
            document.documentElement.style.setProperty('--card-background-color', overlayColor);
        }
    }
    // Listen for background color changes
    const bgColorInput = document.getElementById('link_page_background_color');
    if (bgColorInput) {
        bgColorInput.addEventListener('input', updateOverlayColorFromBg);
        // Also update on page load
        updateOverlayColorFromBg();
    }
    // If background color is changed via other means (e.g., JS), call updateOverlayColorFromBg() after updating customVars

    // --- Centralized UI <-> customVars Sync Functions ---

    // Initialize all controls from customVars
    function initializeControlsFromCustomVars() {
        if (buttonColorInput) buttonColorInput.value = customVars['--link-page-button-color'];
        if (textColorInput) textColorInput.value = customVars['--link-page-text-color'];
        if (linkTextColorInput) linkTextColorInput.value = customVars['--link-page-link-text-color'];
        if (hoverColorInput) hoverColorInput.value = customVars['--link-page-hover-color'];
        if (titleFontFamilySelect) titleFontFamilySelect.value = customVars['--link-page-title-font-family'];
        if (titleFontSizeSlider && titleFontSizeOutput) {
            // Convert em to percent for slider
            const em = parseFloat(customVars['--link-page-title-font-size']);
            const percent = Math.round(((em - FONT_SIZE_MIN_EM) / (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM)) * 100);
            titleFontSizeSlider.value = percent;
            titleFontSizeOutput.textContent = percent + '%';
        }
        if (profileImgSizeSlider && profileImgSizeOutput) {
            const percent = parseInt(customVars['--link-page-profile-img-size'].replace('%',''), 10);
            profileImgSizeSlider.value = percent;
            profileImgSizeOutput.textContent = percent + '%';
        }
        if (profileImgShapeToggle && profileImgShapeHiddenInput) {
            const shape = customVars['_link_page_profile_img_shape'] || 'square';
            profileImgShapeHiddenInput.value = shape;
            profileImgShapeToggle.checked = (shape === 'circle');
            const shapeValueDisplay = document.getElementById('profile-img-shape-value');
            if (shapeValueDisplay) {
                shapeValueDisplay.textContent = (shape === 'circle') ? 'Circle' : 'Square';
            }
        }
        // Overlay toggle
        if (overlayToggle) overlayToggle.checked = customVars.overlay === '1';
    }

    // Update customVars and hidden input from controls, then trigger preview
    function updateCustomVarsFromControls(triggerPreview = true) {
        if (buttonColorInput) customVars['--link-page-button-color'] = buttonColorInput.value;
        if (textColorInput) customVars['--link-page-text-color'] = textColorInput.value;
        if (linkTextColorInput) customVars['--link-page-link-text-color'] = linkTextColorInput.value;
        if (hoverColorInput) customVars['--link-page-hover-color'] = hoverColorInput.value;
        if (titleFontFamilySelect) customVars['--link-page-title-font-family'] = titleFontFamilySelect.value;
        if (titleFontSizeSlider) {
            const percent = parseInt(titleFontSizeSlider.value, 10);
            const em = FONT_SIZE_MIN_EM + (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * (percent / 100);
            customVars['--link-page-title-font-size'] = em.toFixed(2) + 'em';
        }
        if (profileImgSizeSlider) {
            const percent = parseInt(profileImgSizeSlider.value, 10);
            customVars['--link-page-profile-img-size'] = percent + '%';
        }
        if (profileImgShapeToggle && profileImgShapeHiddenInput) {
            const shape = profileImgShapeToggle.checked ? 'circle' : 'square';
            customVars['_link_page_profile_img_shape'] = shape;
            profileImgShapeHiddenInput.value = shape;
            const shapeValueDisplay = document.getElementById('profile-img-shape-value');
            if (shapeValueDisplay) {
                shapeValueDisplay.textContent = (shape === 'circle') ? 'Circle' : 'Square';
            }
        }
        // Overlay toggle
        if (overlayToggle) customVars.overlay = overlayToggle.checked ? '1' : '0';
        // Update hidden input
        if (cssVarsInput) cssVarsInput.value = JSON.stringify(customVars);
        // Trigger preview update
        if (triggerPreview && manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX();
    }

    // --- Event Listeners ---
    if (buttonColorInput) buttonColorInput.addEventListener('input', () => updateCustomVarsFromControls());
    if (textColorInput) textColorInput.addEventListener('input', () => updateCustomVarsFromControls());
    if (linkTextColorInput) linkTextColorInput.addEventListener('input', () => updateCustomVarsFromControls());
    if (hoverColorInput) hoverColorInput.addEventListener('input', () => updateCustomVarsFromControls());
    if (titleFontFamilySelect) titleFontFamilySelect.addEventListener('change', () => updateCustomVarsFromControls());
    if (titleFontSizeSlider) titleFontSizeSlider.addEventListener('input', () => updateCustomVarsFromControls());
    if (profileImgSizeSlider) profileImgSizeSlider.addEventListener('input', () => updateCustomVarsFromControls());
    if (profileImgShapeToggle) profileImgShapeToggle.addEventListener('change', () => updateCustomVarsFromControls());
    if (overlayToggle) overlayToggle.addEventListener('change', () => updateCustomVarsFromControls());

    // On page load, initialize controls from customVars
    initializeControlsFromCustomVars();

})(window.ExtrchLinkPageManager); // Pass the global manager object