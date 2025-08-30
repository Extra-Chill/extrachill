// Link Page Sizing and Shape Customization Module
(function(manager) {
    if (!manager || !manager.customization) {
        console.error('ExtrchLinkPageManager or its customization module is not defined. Sizing script cannot run.');
        return;
    }
    manager.sizing = manager.sizing || {};
    let isSizingInitialized = false;

    // --- Constants from customization.js (relevant to sizing) ---
    const FONT_SIZE_MIN_EM = 0.8;
    const FONT_SIZE_MAX_EM = 3.5;
    const PROFILE_IMG_SIZE_MIN = 1;
    const PROFILE_IMG_SIZE_MAX = 100;
    const PROFILE_IMG_SIZE_DEFAULT = 30;

    // --- Cached DOM Elements (specific to sizing controls) ---
    let titleFontSizeSlider, titleFontSizeOutput;
    let profileImgSizeSlider, profileImgSizeOutput;
    let profileImgShapeHiddenInput, profileImgShapeCircleRadio, profileImgShapeSquareRadio, profileImgShapeRectangleRadio;
    let buttonRadiusSlider, buttonRadiusOutput;

    function cacheSizingDomElements() {
        titleFontSizeSlider = document.getElementById('link_page_title_font_size');
        titleFontSizeOutput = document.getElementById('title_font_size_output');
        profileImgSizeSlider = document.getElementById('link_page_profile_img_size');
        profileImgSizeOutput = document.getElementById('profile_img_size_output');
        profileImgShapeHiddenInput = document.getElementById('link_page_profile_img_shape_hidden');
        profileImgShapeCircleRadio = document.getElementById('profile-img-shape-circle');
        profileImgShapeSquareRadio = document.getElementById('profile-img-shape-square');
        profileImgShapeRectangleRadio = document.getElementById('profile-img-shape-rectangle');
        buttonRadiusSlider = document.getElementById('link_page_button_radius');
        buttonRadiusOutput = document.getElementById('button_radius_output');
    }

    function loadInitialSizingValues() {
        // Load initial sizing values from centralized data source
        if (manager.customization && typeof manager.customization.getCustomVars === 'function') {
            const cssVars = manager.customization.getCustomVars();
            if (cssVars && typeof cssVars === 'object') {
                // Set font size sliders
                if (titleFontSizeSlider && cssVars['--link-page-title-font-size']) {
                    const emValue = cssVars['--link-page-title-font-size'];
                    const percentage = Math.round((parseFloat(emValue) - FONT_SIZE_MIN_EM) / (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * 100);
                    titleFontSizeSlider.value = percentage;
                    if (titleFontSizeOutput) titleFontSizeOutput.textContent = percentage + '%';
                }
                
                // Set button radius slider  
                if (buttonRadiusSlider && cssVars['--link-page-button-radius']) {
                    const pxValue = cssVars['--link-page-button-radius'];
                    const percentage = Math.round((parseFloat(pxValue) / 30) * 100);
                    buttonRadiusSlider.value = percentage;
                    if (buttonRadiusOutput) buttonRadiusOutput.textContent = percentage + '%';
                }
                
                console.log('[Sizing] Loaded initial values from centralized data');
            }
        }
        
        // Load settings from centralized data
        if (manager.getSettings && typeof manager.getSettings === 'function') {
            const settings = manager.getSettings();
            if (settings && settings.profile_image_shape) {
                const shape = settings.profile_image_shape;
                if (shape === 'circle' && profileImgShapeCircleRadio) {
                    profileImgShapeCircleRadio.checked = true;
                } else if (shape === 'square' && profileImgShapeSquareRadio) {
                    profileImgShapeSquareRadio.checked = true;
                } else if (shape === 'rectangle' && profileImgShapeRectangleRadio) {
                    profileImgShapeRectangleRadio.checked = true;
                }
            }
        }
    }

    // --- Function to sync UI controls from customVars (for sizing controls) ---
    function syncSizingInputValues() {
        if (!manager.customization || typeof manager.customization.getCustomVars !== 'function') {
            console.warn('syncSizingInputValues: manager.customization.getCustomVars is not available.');
            return;
        }
        const currentCV = manager.customization.getCustomVars();
        if (!currentCV) {
            console.error('syncSizingInputValues: customVars not found.');
            return;
        }

        // ONLY sync UI controls to existing CSS variables - never apply defaults to CSS variables
        if (titleFontSizeSlider && titleFontSizeOutput && currentCV.hasOwnProperty('--link-page-title-font-size')) {
            const savedEmString = currentCV['--link-page-title-font-size'];
            const savedEmValue = parseFloat(savedEmString);
            if (!isNaN(savedEmValue)) {
                const percentage = (savedEmValue - FONT_SIZE_MIN_EM) / (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM);
                const sliderValue = Math.max(1, Math.min(100, Math.round(percentage * 100))); 
                titleFontSizeSlider.value = sliderValue;
                titleFontSizeOutput.textContent = sliderValue + '%';
            }
        }

        if (profileImgSizeSlider && profileImgSizeOutput && currentCV['--link-page-profile-img-size']) {
            const savedSizePercent = parseInt(currentCV['--link-page-profile-img-size'].toString().replace('%',''), 10);
            if (!isNaN(savedSizePercent)) {
                profileImgSizeSlider.value = savedSizePercent;
                profileImgSizeOutput.textContent = savedSizePercent + '%';
            }
        }

        if (profileImgShapeHiddenInput && profileImgShapeCircleRadio && profileImgShapeSquareRadio && profileImgShapeRectangleRadio) { 
            // Do NOT set .checked here; let PHP handle initial checked state.
            // Hidden input will be updated at save time, not during initialization
        }

        // --- Button Radius Slider - ONLY sync UI to existing values ---
        if (buttonRadiusSlider && buttonRadiusOutput && currentCV['--link-page-button-radius']) {
            const savedRadiusPx = parseInt(currentCV['--link-page-button-radius'].toString().replace('px',''), 10);
            if (!isNaN(savedRadiusPx)) {
                buttonRadiusSlider.value = savedRadiusPx;
                buttonRadiusOutput.textContent = savedRadiusPx + 'px';
            }
        }
    }
    manager.sizing.syncSizingInputValues = syncSizingInputValues; // Expose for customization.js if needed

    // --- Initialization logic for this sizing module ---
    function initializeSizingControls() {
        if (isSizingInitialized) return;

        cacheSizingDomElements();
        
        // Load initial sizing values from centralized data
        loadInitialSizingValues();

        // Attach Event Listeners - Convert to direct event handling
        if (titleFontSizeSlider && titleFontSizeOutput) {
            titleFontSizeSlider.addEventListener('input', function() {
                const sliderPercentage = parseInt(this.value, 10);
                const emValue = (FONT_SIZE_MIN_EM + (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * (sliderPercentage / 100)).toFixed(2) + 'em';
                
                // Update output display
                titleFontSizeOutput.textContent = sliderPercentage + '%';
                
                // Update CSS variable via customization system
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-title-font-size', emValue);
                }
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('titleFontSizeChanged', {
                    detail: { size: emValue }
                }));
            });
        }

        if (profileImgSizeSlider && profileImgSizeOutput) {
            profileImgSizeSlider.addEventListener('input', function() {
                const percentValue = this.value + '%';
                
                // Update output display
                profileImgSizeOutput.textContent = percentValue;
                
                // Update CSS variable via customization system
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-profile-img-size', percentValue);
                }
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('profileImageSizeChanged', {
                    detail: { size: percentValue }
                }));
            });
        }
        
        // --- Button Radius Slider Listener ---
        if (buttonRadiusSlider && buttonRadiusOutput) {
            buttonRadiusSlider.addEventListener('input', function() {
                const pxValue = this.value + 'px';
                
                // Update output display  
                buttonRadiusOutput.textContent = pxValue;
                
                // Update CSS variable via customization system
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-button-radius', pxValue);
                }
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('buttonRadiusChanged', {
                    detail: { radius: pxValue }
                }));
            });
        }

        // --- Profile Image Shape Radios ---
        // Event listeners are attached separately to avoid immediate hidden input updates
        attachShapeEventListeners();
        
        syncSizingInputValues(); // Sync UI on init
        isSizingInitialized = true;
    }
    manager.sizing.init = initializeSizingControls;

    // --- Auto-initialize if customization module is ready, or wait for its event ---
    if (manager.customization && manager.customization.isInitialized) {
        initializeSizingControls();
    } else {
        document.addEventListener('extrchLinkPageCustomizeTabInitialized', initializeSizingControls, { once: true });
    }

    // Event listener for profile image shape radio buttons (attached after initialization)
    function attachShapeEventListeners() {
        const radios = [profileImgShapeCircleRadio, profileImgShapeSquareRadio, profileImgShapeRectangleRadio];
        radios.forEach(radio => {
            if (radio) {
                radio.addEventListener('change', function(event) {
                    // Update CSS custom property for immediate visual feedback
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                        manager.customization.updateSetting('_link_page_profile_img_shape', event.target.value);
                    }
                    
                    // Emit event for preview module
                    document.dispatchEvent(new CustomEvent('profileImageShapeChanged', {
                        detail: { shape: event.target.value }
                    }));
                    
                    // NO hidden input update during user interaction - wait for save time
                    // This prevents scattered save logic and race conditions
                });
            }
        });
    }

    /**
     * Serializes current sizing settings into hidden inputs for form submission.
     * This method should ONLY be called by the save handler, not during user interactions.
     */
    function serializeSizingForSave() {
        let success = true;
        
        // Serialize profile image shape
        const profileImgShapeHiddenInput = document.getElementById('link_page_profile_img_shape_hidden');
        if (profileImgShapeHiddenInput) {
            const checkedRadio = document.querySelector('input[name="link_page_profile_img_shape"]:checked');
            if (checkedRadio) {
                profileImgShapeHiddenInput.value = checkedRadio.value;
                console.log('[SizingManager] Serialized profile image shape:', checkedRadio.value);
            } else {
                console.warn('[SizingManager] No profile image shape radio button checked');
                success = false;
            }
        } else {
            console.warn('[SizingManager] Profile image shape hidden input not found');
            success = false;
        }
        
        return success;
    }
    
    // Expose the serialize method for the save handler
    manager.sizing.serializeForSave = serializeSizingForSave;

    // Self-initialize on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof manager.sizing.init === 'function') {
            manager.sizing.init();
        }
    });

})(window.ExtrchLinkPageManager); 