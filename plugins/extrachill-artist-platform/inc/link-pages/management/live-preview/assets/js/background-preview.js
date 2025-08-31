// Background Preview Module - Handles live preview updates for background styling
(function() {
    'use strict';
    
    // Main background preview update function - Direct DOM manipulation
    function updateBackgroundPreview(backgroundData) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        if (!previewContainerParent) return;
        
        const previewEl = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Find the main container that should receive background styling
        const linkPageContainer = previewEl.querySelector('.extrch-link-page-container');
        if (!linkPageContainer) return;

        // Apply background based on type
        switch (backgroundData.type) {
            case 'color':
                applyColorBackground(linkPageContainer, backgroundData.color);
                break;
            case 'gradient':
                applyGradientBackground(linkPageContainer, backgroundData);
                break;
            case 'image':
                applyImageBackground(linkPageContainer, backgroundData.imageUrl);
                break;
            default:
                // Clear all backgrounds
                clearBackground(linkPageContainer);
        }
    }

    // Apply solid color background
    function applyColorBackground(container, color) {
        container.style.background = '';
        container.style.backgroundImage = '';
        container.style.backgroundColor = color || '#1a1a1a';
    }

    // Apply gradient background
    function applyGradientBackground(container, gradientData) {
        const startColor = gradientData.startColor || '#0b5394';
        const endColor = gradientData.endColor || '#53940b';
        const direction = gradientData.direction || 'to right';
        
        container.style.backgroundColor = '';
        container.style.backgroundImage = `linear-gradient(${direction}, ${startColor}, ${endColor})`;
    }

    // Apply image background
    function applyImageBackground(container, imageUrl) {
        if (imageUrl && imageUrl.trim() !== '') {
            container.style.backgroundColor = '';
            // Handle both data URLs and regular URLs
            const backgroundValue = imageUrl.startsWith('url(') ? imageUrl : `url(${imageUrl})`;
            container.style.backgroundImage = backgroundValue;
            container.style.backgroundSize = 'cover';
            container.style.backgroundPosition = 'center';
            container.style.backgroundRepeat = 'no-repeat';
        } else {
            clearBackground(container);
        }
    }

    // Clear all background styling
    function clearBackground(container) {
        container.style.background = '';
        container.style.backgroundImage = '';
        container.style.backgroundColor = '#1a1a1a'; // Default fallback
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
            // Read current background data from form inputs directly
            let backgroundData = { type: e.detail.type };
            
            // Read from form inputs instead of CSS variables
            const bgColorInput = document.getElementById('link_page_background_color');
            const gradStartInput = document.getElementById('link_page_background_gradient_start');
            const gradEndInput = document.getElementById('link_page_background_gradient_end');
            const gradDirInput = document.getElementById('link_page_background_gradient_direction');
            
            if (bgColorInput) backgroundData.color = bgColorInput.value;
            if (gradStartInput) backgroundData.startColor = gradStartInput.value;
            if (gradEndInput) backgroundData.endColor = gradEndInput.value;
            if (gradDirInput) backgroundData.direction = gradDirInput.value;
            
            // Get image URL from CSS variable (since it's not in a form input)
            const rootStyle = getComputedStyle(document.documentElement);
            backgroundData.imageUrl = rootStyle.getPropertyValue('--link-page-background-image-url').trim();
            
            updateBackgroundPreview(backgroundData);
        }
    });

    document.addEventListener('backgroundColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateBackgroundPreview({ type: 'color', color: e.detail.color });
        }
    });

    document.addEventListener('backgroundGradientChanged', function(e) {
        if (e.detail && e.detail.gradientData) {
            const backgroundData = { type: 'gradient', ...e.detail.gradientData };
            updateBackgroundPreview(backgroundData);
        }
    });

    document.addEventListener('backgroundImageChanged', function(e) {
        if (e.detail && e.detail.imageUrl !== undefined) {
            updateBackgroundPreview({ type: 'image', imageUrl: e.detail.imageUrl });
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

    // Event listeners to update CSS variables based on management events

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
                updateCSSVariable('--link-page-background-type', 'image');
            }
        }
    });

    // Self-contained module - no global exposure needed

})();