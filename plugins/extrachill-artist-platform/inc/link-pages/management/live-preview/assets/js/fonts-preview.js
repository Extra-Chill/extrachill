// Fonts Preview Module - Handles live preview updates for font styling
(function(manager, config) {
    if (!manager) return;
    
    manager.fontsPreview = manager.fontsPreview || {};
    
    // Get font options from the fonts filter system (passed via config)
    function getFontOptions() {
        return (config && config.fonts && Array.isArray(config.fonts)) ? config.fonts : [];
    }
    
    // Get font stack by value using filter data
    function getFontStackByValue(fontValue) {
        const options = getFontOptions();
        const found = options.find(f => f.value === fontValue);
        return found ? found.stack : "'Helvetica', Arial, sans-serif";
    }
    
    // Main fonts preview update function - Direct DOM manipulation
    function updateFontFamilyPreview(fontData) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        if (!previewContainerParent) return;
        
        const previewEl = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Apply font family changes to CSS custom properties on the preview element
        if (fontData.property && fontData.fontFamily) {
            const fontStack = getFontStackByValue(fontData.fontFamily);
            previewEl.style.setProperty(fontData.property, fontStack);
        }
    }
    
    // Update font size preview
    function updateFontSizePreview(sizeData) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        if (!previewContainerParent) return;
        
        const previewEl = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        if (sizeData.property && sizeData.size) {
            previewEl.style.setProperty(sizeData.property, sizeData.size);
        }
    }

    // Event listeners for font family changes
    document.addEventListener('titleFontFamilyChanged', function(e) {
        if (e.detail && e.detail.fontFamily) {
            updateFontFamilyPreview({
                property: '--link-page-title-font-family',
                fontFamily: e.detail.fontFamily
            });
        }
    });

    document.addEventListener('bodyFontFamilyChanged', function(e) {
        if (e.detail && e.detail.fontFamily) {
            updateFontFamilyPreview({
                property: '--link-page-body-font-family',
                fontFamily: e.detail.fontFamily
            });
        }
    });

    // Event listeners for font size changes
    document.addEventListener('titleFontSizeChanged', function(e) {
        if (e.detail && e.detail.size) {
            updateFontSizePreview({
                property: '--link-page-title-font-size',
                size: e.detail.size
            });
        }
    });

    document.addEventListener('bodyFontSizeChanged', function(e) {
        if (e.detail && e.detail.size) {
            updateFontSizePreview({
                property: '--link-page-body-font-size',
                size: e.detail.size
            });
        }
    });

    // Generic font change event listener
    document.addEventListener('fontChanged', function(e) {
        if (e.detail && e.detail.property && e.detail.value) {
            const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
            if (!previewContainerParent) return;
            
            const previewEl = previewContainerParent.querySelector('.extrch-link-page-preview-container');
            if (previewEl) {
                if (e.detail.type === 'family') {
                    const fontStack = getFontStackByValue(e.detail.value);
                    previewEl.style.setProperty(e.detail.property, fontStack);
                } else {
                    previewEl.style.setProperty(e.detail.property, e.detail.value);
                }
            }
        }
    });

    // Expose functions on manager
    manager.fontsPreview.updateFontFamily = updateFontFamilyPreview;
    manager.fontsPreview.updateFontSize = updateFontSizePreview;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}, window.extrchLinkPageConfig);