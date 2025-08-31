// Fonts Preview Module - Handles live preview updates for font styling
(function() {
    // Get font options from global config  
    function getFontOptions() {
        return (extraChillArtistPlatform && extraChillArtistPlatform.fonts && Array.isArray(extraChillArtistPlatform.fonts)) ? extraChillArtistPlatform.fonts : [];
    }
    
    // Get font stack by value using filter data
    function getFontStackByValue(fontValue) {
        const options = getFontOptions();
        const found = options.find(f => f.value === fontValue);
        return found ? found.stack : "'Helvetica', Arial, sans-serif";
    }
    
    // Get Google Font parameter by value
    function getGoogleFontParam(fontValue) {
        const options = getFontOptions();
        const found = options.find(f => f.value === fontValue);
        return (found && found.google_font_param !== 'local_default') ? found.google_font_param : null;
    }
    
    // Track loaded Google Fonts to prevent duplicates
    const loadedGoogleFonts = new Set();
    
    // Dynamic Google Font loading helper
    function loadGoogleFont(fontValue) {
        const googleFontParam = getGoogleFontParam(fontValue);
        
        // Skip if not a Google Font or already loaded
        if (!googleFontParam || loadedGoogleFonts.has(googleFontParam)) {
            return;
        }
        
        // Mark as loading to prevent duplicates
        loadedGoogleFonts.add(googleFontParam);
        
        // Create and inject Google Fonts link tag
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = `https://fonts.googleapis.com/css2?family=${googleFontParam}&display=swap`;
        link.setAttribute('data-font-param', googleFontParam);
        
        // Add to document head
        document.head.appendChild(link);
    }
    
    // Main fonts preview update function - Direct DOM manipulation
    function updateFontFamilyPreview(fontData) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        if (!previewContainerParent) return;
        
        const previewEl = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Apply font family changes to CSS custom properties on the preview element
        if (fontData.property && fontData.fontFamily) {
            // Load Google Font dynamically if needed
            loadGoogleFont(fontData.fontFamily);
            
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
                    // Load Google Font dynamically if needed
                    loadGoogleFont(e.detail.value);
                    
                    const fontStack = getFontStackByValue(e.detail.value);
                    previewEl.style.setProperty(e.detail.property, fontStack);
                } else {
                    previewEl.style.setProperty(e.detail.property, e.detail.value);
                }
            }
        }
    });

    // Self-contained module - no global exposure needed

})();