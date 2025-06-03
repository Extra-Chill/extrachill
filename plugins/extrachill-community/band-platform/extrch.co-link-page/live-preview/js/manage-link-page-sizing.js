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

        if (titleFontSizeSlider && titleFontSizeOutput) {
            const defaultSliderValue = 50; 
            let sliderValue = defaultSliderValue;
            if (currentCV.hasOwnProperty('--link-page-title-font-size')) {
                const savedEmString = currentCV['--link-page-title-font-size'];
                const savedEmValue = parseFloat(savedEmString);
                if (!isNaN(savedEmValue)) {
                    const percentage = (savedEmValue - FONT_SIZE_MIN_EM) / (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM);
                    sliderValue = Math.max(1, Math.min(100, Math.round(percentage * 100))); 
                } else {
                     sliderValue = defaultSliderValue;
                }
            } else {
                 sliderValue = defaultSliderValue;
            }
            titleFontSizeSlider.value = sliderValue;
            titleFontSizeOutput.textContent = sliderValue + '%';
        }

        if (profileImgSizeSlider && profileImgSizeOutput) {
            let savedSizePercent = PROFILE_IMG_SIZE_DEFAULT;
            if (typeof currentCV['--link-page-profile-img-size'] !== 'undefined') {
                savedSizePercent = parseInt(currentCV['--link-page-profile-img-size'].toString().replace('%',''), 10);
                if (isNaN(savedSizePercent) || savedSizePercent < PROFILE_IMG_SIZE_MIN || savedSizePercent > PROFILE_IMG_SIZE_MAX) {
                     savedSizePercent = PROFILE_IMG_SIZE_DEFAULT;
                }
            }
            profileImgSizeSlider.value = savedSizePercent;
            profileImgSizeOutput.textContent = savedSizePercent + '%';
        }

        if (profileImgShapeHiddenInput && profileImgShapeCircleRadio && profileImgShapeSquareRadio && profileImgShapeRectangleRadio) { 
            // Do NOT set .checked here; let PHP handle initial checked state.
            // Only set the hidden input value to match the checked radio.
            if (profileImgShapeCircleRadio.checked) profileImgShapeHiddenInput.value = 'circle';
            else if (profileImgShapeSquareRadio.checked) profileImgShapeHiddenInput.value = 'square';
            else if (profileImgShapeRectangleRadio.checked) profileImgShapeHiddenInput.value = 'rectangle';
        }

        // --- Button Radius Slider ---
        if (buttonRadiusSlider && buttonRadiusOutput) {
            let savedRadiusPx = 8; // Default radius
            if (typeof currentCV['--link-page-button-radius'] !== 'undefined') {
                savedRadiusPx = parseInt(currentCV['--link-page-button-radius'].toString().replace('px',''), 10);
                if (isNaN(savedRadiusPx) || savedRadiusPx < 0 || savedRadiusPx > 50) {
                    savedRadiusPx = 8;
                }
            }
            buttonRadiusSlider.value = savedRadiusPx;
            buttonRadiusOutput.textContent = savedRadiusPx + 'px';
        }
    }
    manager.sizing.syncSizingInputValues = syncSizingInputValues; // Expose for customization.js if needed

    // --- Initialization logic for this sizing module ---
    function initializeSizingControls() {
        if (isSizingInitialized) return;

        cacheSizingDomElements();

        // Attach Event Listeners
        if (titleFontSizeSlider && titleFontSizeOutput && manager.customization.attachControlListener) {
            manager.customization.attachControlListener(titleFontSizeSlider, '--link-page-title-font-size', 'input',
                (value) => {
                    const sliderPercentage = parseInt(value, 10);
                    titleFontSizeOutput.textContent = sliderPercentage + '%';
                    return (FONT_SIZE_MIN_EM + (FONT_SIZE_MAX_EM - FONT_SIZE_MIN_EM) * (sliderPercentage / 100)).toFixed(2) + 'em';
                }
            );
        }

        if (profileImgSizeSlider && profileImgSizeOutput && manager.customization.attachControlListener) {
            manager.customization.attachControlListener(profileImgSizeSlider, '--link-page-profile-img-size', 'input',
                (value) => { profileImgSizeOutput.textContent = value + '%'; return value + '%'; }
            );
        }
        
        // --- Button Radius Slider Listener ---
        if (buttonRadiusSlider && buttonRadiusOutput && manager.customization.attachControlListener) {
            manager.customization.attachControlListener(buttonRadiusSlider, '--link-page-button-radius', 'input',
                (value) => {
                    // Always show px for clarity
                    buttonRadiusOutput.textContent = value + 'px';
                    return value + 'px';
                }
            );
        }

        // --- Profile Image Shape Radios ---
        function handleProfileShapeChange(event) {
            if (event.target.checked && profileImgShapeHiddenInput) {
                 profileImgShapeHiddenInput.value = event.target.value; // Update hidden input for form
                 manager.customization.updateSetting('_link_page_profile_img_shape', event.target.value);
            }
        }
        if (profileImgShapeCircleRadio) profileImgShapeCircleRadio.addEventListener('change', handleProfileShapeChange);
        if (profileImgShapeSquareRadio) profileImgShapeSquareRadio.addEventListener('change', handleProfileShapeChange);
        if (profileImgShapeRectangleRadio) profileImgShapeRectangleRadio.addEventListener('change', handleProfileShapeChange);
        
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

})(window.ExtrchLinkPageManager); 