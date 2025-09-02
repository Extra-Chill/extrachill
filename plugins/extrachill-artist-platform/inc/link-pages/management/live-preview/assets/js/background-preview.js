// Background Preview Module - Handles live preview updates for background styling
(function() {
    'use strict';
    
    // Main background preview update function - CSS variable updates
    function updateBackgroundPreview(backgroundData) {
        // Apply background based on type using CSS variables
        switch (backgroundData.type) {
            case 'color':
                applyColorBackground(backgroundData.color);
                break;
            case 'gradient':
                applyGradientBackground(backgroundData);
                break;
            case 'image':
                applyImageBackground(backgroundData.imageUrl);
                break;
            default:
                // Clear all backgrounds
                clearBackground();
        }
    }

    // Apply solid color background via CSS variables
    function applyColorBackground(color) {
        updateBackgroundType('color');
        if (color) {
            updateCSSVariable('--link-page-background-color', color);
        }
        // Clear other background properties
        updateCSSVariable('--link-page-background-image-url', '');
    }

    // Apply gradient background via CSS variables
    function applyGradientBackground(gradientData) {
        updateBackgroundType('gradient');
        
        const startColor = gradientData.startColor || '#0b5394';
        const endColor = gradientData.endColor || '#53940b';
        const direction = gradientData.direction || 'to right';
        
        updateCSSVariable('--link-page-background-gradient-start', startColor);
        updateCSSVariable('--link-page-background-gradient-end', endColor);
        updateCSSVariable('--link-page-background-gradient-direction', direction);
        
        // Clear other background properties
        updateCSSVariable('--link-page-background-image-url', '');
    }

    // Apply image background via CSS variables
    function applyImageBackground(imageUrl) {
        if (imageUrl && imageUrl.trim() !== '') {
            updateBackgroundType('image');
            updateCSSVariable('--link-page-background-image-url', imageUrl);
        } else {
            clearBackground();
        }
    }

    // Clear all background styling via CSS variables
    function clearBackground() {
        updateBackgroundType('color');
        updateCSSVariable('--link-page-background-color', '#1a1a1a');
        updateCSSVariable('--link-page-background-image-url', '');
    }

    // Event listeners for background updates from management forms
    document.addEventListener('backgroundChanged', function(e) {
        if (e.detail && e.detail.backgroundData) {
            updateBackgroundPreview(e.detail.backgroundData);
        }
    });

    // Event listeners for individual background property changes
    document.addEventListener('backgroundTypeChanged', function(e) {
        if (e.detail && e.detail.type) {
            // Use helper function to update both CSS variable and HTML attribute
            updateBackgroundType(e.detail.type);
        }
    });

    document.addEventListener('backgroundColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateCSSVariable('--link-page-background-color', e.detail.color);
        }
    });

    document.addEventListener('backgroundGradientChanged', function(e) {
        if (e.detail && e.detail.gradientData) {
            const gradient = e.detail.gradientData;
            if (gradient.startColor) {
                updateCSSVariable('--link-page-background-gradient-start', gradient.startColor);
            }
            if (gradient.endColor) {
                updateCSSVariable('--link-page-background-gradient-end', gradient.endColor);
            }
            if (gradient.direction) {
                updateCSSVariable('--link-page-background-gradient-direction', gradient.direction);
            }
        }
    });

    document.addEventListener('backgroundImageChanged', function(e) {
        if (e.detail && e.detail.imageUrl !== undefined) {
            updateCSSVariable('--link-page-background-image-url', e.detail.imageUrl);
            if (e.detail.imageUrl && e.detail.imageUrl.trim() !== '') {
                updateBackgroundType('image');
            }
        }
    });

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

    // Helper function to update background type in both CSS variable and HTML attribute
    function updateBackgroundType(type) {
        // Update CSS variable for form state
        updateCSSVariable('--link-page-background-type', type);
        
        // Update HTML data attribute for CSS styling (CRITICAL)
        const previewContainer = document.querySelector('.extrch-link-page-preview-container');
        if (previewContainer) {
            previewContainer.setAttribute('data-bg-type', type);
        }
    }


    // Self-contained module - no global exposure needed

})();