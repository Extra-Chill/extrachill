// Link Page Font Management Module
// Handles font loading and application logic for the live preview.

(function(manager) {
    if (!manager) {
        // console.error('ExtrchLinkPageManager is not defined. Font script cannot run.'); // Keep this for critical failure
        return;
    }
    manager.fonts = manager.fonts || {};

    // console.log('[Font Module DEBUG] manage-link-page-fonts.js executing. Initial window.extrchLinkPageFonts:', JSON.parse(JSON.stringify(window.extrchLinkPageFonts || null))); // Removed

    const loadedFontUrls = new Set(); // Keep track of loaded Google Font URLs

    // Access FONT_OPTIONS directly from window when functions are called
    function getFontOptions() {
        return (typeof window.extrchLinkPageFonts !== 'undefined' && Array.isArray(window.extrchLinkPageFonts)) ? window.extrchLinkPageFonts : [];
    }

    function getFontStackByValue(fontValue) {
        const options = getFontOptions();
        const found = options.find(f => f.value === fontValue);
        return found ? found.stack : "'WilcoLoftSans', Helvetica, Arial, sans-serif";
    }

    function getGoogleFontParamByValue(fontValue) {
        const options = getFontOptions();
        // console.log('[Font Module DEBUG] getGoogleFontParamByValue called with fontValue:', fontValue); // Removed
        // console.log('[Font Module DEBUG] FONT_OPTIONS from getFontOptions():', options); // Removed
        const found = options.find(f => f.value === fontValue);
        if (found) {
            // console.log('[Font Module DEBUG] Found font in FONT_OPTIONS:', found); // Removed
            return found.google_font_param;
        } else {
            // console.warn('[Font Module DEBUG] Font not found in FONT_OPTIONS for value:', fontValue); // Removed
            return null;
        }
    }

    /**
     * Loads a Google Font if not already loaded and executes a callback.
     * @param {string} fontParam - The Google Font parameter (e.g., 'Roboto').
     * @param {string} fontFamilyValue - The CSS font-family value (e.g., 'Roboto').
     * @param {function} onFontLoaded - Callback function to execute after font is loaded.
     */
    function loadGoogleFont(fontParam, fontFamilyValue, onFontLoaded) {
        const effectiveFontStack = getFontStackByValue(fontFamilyValue) || fontFamilyValue; // Ensure we have a value to pass

        if (!fontParam || fontParam === 'inherit' || fontParam === 'local_default' || fontParam === '' || !fontFamilyValue) {
            if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass the stack/value
            return;
        }

        // Construct the font URL carefully to avoid duplicating weight parameters
        let fontSpecForUrl = fontParam.replace(/ /g, '+');
        if (!fontSpecForUrl.includes(':wght@')) {
            // Only append default weights if :wght@ is not already present in fontParam.
            // Note: PHP config now provides weights, so this is more of a fallback.
            fontSpecForUrl += ':wght@400;600;700'; // Consistent with PHP desired weights
        }
        const fontUrl = `https://fonts.googleapis.com/css2?family=${fontSpecForUrl}&display=swap`;

        if (!loadedFontUrls.has(fontUrl)) {
            // console.log('[Font Module DEBUG] Font URL not in loadedFontUrls. Creating link element.'); // Removed
            const linkElement = document.createElement('link');
            linkElement.href = fontUrl;
            linkElement.rel = 'stylesheet';
            linkElement.onload = () => {
                // console.log('[Font Module DEBUG] Google Font CSS loaded (onload event):', fontUrl); // Removed
                loadedFontUrls.add(fontUrl);
                if (typeof fontFamilyValue === 'string') {
                    // console.log(`[Font Module DEBUG] Waiting for document.fonts.load('700 2em "${fontFamilyValue}"')`); // Removed
                    document.fonts.load(`1em '${fontFamilyValue}'`).then(() => {
                        // console.log(`[Font Module DEBUG] Font "${fontFamilyValue}" is ready (document.fonts.load resolved). Calling onFontLoaded.`); // Removed
                        if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass the stack/value
                    }).catch(err => {
                         console.error(`[Font Module DEBUG] Error waiting for font "${fontFamilyValue}" to be available after CSS load:`, err);
                         if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass stack/value even on error
                    });
                } else {
                    // console.log('[Font Module DEBUG] fontFamilyValue is not a string after CSS load. Calling onFontLoaded.'); // Removed
                    if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass the stack/value
                }
            };
            linkElement.onerror = () => {
                console.error('[Font Module DEBUG] Error loading Google Font CSS (onerror event):', fontUrl);
                if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass stack/value even on error
            };
            document.head.appendChild(linkElement);
        } else {
            // console.log('[Font Module DEBUG] Font URL already in loadedFontUrls. Assuming font CSS is loaded/loading, proceed to check font readiness.'); // Removed
             if (typeof fontFamilyValue === 'string') {
                // console.log(`[Font Module DEBUG] Waiting for document.fonts.load('1em "${fontFamilyValue}"') for already processed URL.`); // Removed
                document.fonts.load(`1em '${fontFamilyValue}'`).then(() => {
                    // console.log(`[Font Module DEBUG] Font "${fontFamilyValue}" (already processed URL) is ready. Calling onFontLoaded.`); // Removed
                    if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass the stack/value
                }).catch(err => {
                     console.error(`[Font Module DEBUG] Error waiting for already loaded font "${fontFamilyValue}" (already processed URL):`, err);
                     if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass stack/value even on error
                });
            } else {
                // console.log('[Font Module DEBUG] fontFamilyValue is not a string (already processed URL). Calling onFontLoaded.'); // Removed
                if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack); // Pass the stack/value
            }
        }
    }

    /**
     * Updates the font family for the preview title element.
     * This function should be called AFTER the font is loaded.
     * @param {string} fontFamilyValue - The selected font value (e.g., 'Roboto').
     */
    function updatePreviewTitleFontFamily(fontFamilyValue) {
        const fontStack = getFontStackByValue(fontFamilyValue);
        // console.log('[Font Module DEBUG] updatePreviewTitleFontFamily called. Value:', fontFamilyValue, 'Stack:', fontStack); // Removed
        const previewContainer = document.querySelector('.extrch-link-page-preview-container');

        if (previewContainer) {
            // console.log('[Font Module DEBUG] Preview container found. Setting CSS var --link-page-title-font-family to:', fontStack); // Removed
            previewContainer.style.setProperty('--link-page-title-font-family', fontStack);
            
            const titleElement = previewContainer.querySelector('.extrch-link-page-title');
            if (titleElement) {
                // console.log('[Font Module DEBUG] Title element found. Attempting to force reflow.'); // Removed
                // The reflow logic is a workaround; let's remove it for now to rely on standard CSS application.
                // If issues persist, this could be revisited, but it's better to ensure the core logic is sound first.
                // titleElement.style.fontFamily = fontStack; // Direct application also removed for now.
            } else {
                // console.warn('[Font Module DEBUG] Preview title element (.extrch-link-page-title) not found.'); // Keep warn
            }
        } else {
            // console.warn('[Font Module DEBUG] Preview container (.extrch-link-page-preview-container) not found.'); // Keep warn
        }
    }

    // Expose functions via the manager
    manager.fonts.loadGoogleFont = loadGoogleFont;
    manager.fonts.updatePreviewTitleFontFamily = updatePreviewTitleFontFamily;
    manager.fonts.getFontStackByValue = getFontStackByValue; // Expose helper if needed elsewhere
    manager.fonts.getGoogleFontParamByValue = getGoogleFontParamByValue; // Expose helper if needed elsewhere

})(window.ExtrchLinkPageManager); // Pass the global manager object