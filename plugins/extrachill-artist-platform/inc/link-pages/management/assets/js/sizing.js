// Link Page Sizing and Shape Customization Module - Self-Contained
(function() {
    'use strict';
    
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
        // Load initial values from form fields and CSS (self-contained)
        console.log('[Sizing] Loading initial values from form fields');
    }

    // Self-contained - no external sync needed
    function syncSizingInputValues() {
        console.log('[Sizing] Using form field values directly');
    }

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
                
                // Update CSS variable directly
                updateCSSVariable('--link-page-title-font-size', emValue);
                
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
                
                // Update CSS variable directly
                updateCSSVariable('--link-page-profile-img-size', percentValue);
                
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
                
                // Update CSS variable directly
                updateCSSVariable('--link-page-button-radius', pxValue);
                
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
    // Helper function to update CSS variables directly
    function updateCSSVariable(property, value) {
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (styleTag && styleTag.sheet) {
            // Find the :root rule and update the property
            for (let i = 0; i < styleTag.sheet.cssRules.length; i++) {
                if (styleTag.sheet.cssRules[i].selectorText === ':root') {
                    styleTag.sheet.cssRules[i].style.setProperty(property, value);
                    break;
                }
            }
        }
    }

    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        initializeSizingControls();
    } else {
        document.addEventListener('DOMContentLoaded', initializeSizingControls);
    }

    // Event listener for profile image shape radio buttons (attached after initialization)
    function attachShapeEventListeners() {
        const radios = [profileImgShapeCircleRadio, profileImgShapeSquareRadio, profileImgShapeRectangleRadio];
        radios.forEach(radio => {
            if (radio) {
                radio.addEventListener('change', function(event) {
                    // Update CSS custom property for immediate visual feedback
                    updateCSSVariable('_link_page_profile_img_shape', event.target.value);
                    
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

    // No serialization needed - form fields handle all data persistence

    // Module is now self-initializing

})(); 