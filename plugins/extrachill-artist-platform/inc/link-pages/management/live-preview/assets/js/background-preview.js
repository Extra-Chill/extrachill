// Background Preview Module - Handles live preview updates for background styling
(function(manager) {
    if (!manager) return;
    
    manager.backgroundPreview = manager.backgroundPreview || {};
    
    // Main background preview update function - Direct DOM manipulation
    function updateBackgroundPreview(backgroundData) {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
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
            // Get current background data from customization system if available
            let backgroundData = { type: e.detail.type };
            
            if (manager.customization && typeof manager.customization.getCustomVars === 'function') {
                const customVars = manager.customization.getCustomVars();
                backgroundData.color = customVars['--link-page-background-color'];
                backgroundData.startColor = customVars['--link-page-background-gradient-start'];
                backgroundData.endColor = customVars['--link-page-background-gradient-end'];
                backgroundData.direction = customVars['--link-page-background-gradient-direction'];
                backgroundData.imageUrl = customVars['--link-page-background-image-url'];
            }
            
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

    // Expose functions on manager
    manager.backgroundPreview.update = updateBackgroundPreview;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});