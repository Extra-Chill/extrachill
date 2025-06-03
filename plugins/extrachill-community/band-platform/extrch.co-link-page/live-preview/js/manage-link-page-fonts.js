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
            const linkElement = document.createElement('link');
            linkElement.href = fontUrl;
            linkElement.rel = 'stylesheet';
            linkElement.onload = () => {
                loadedFontUrls.add(fontUrl);
                if (typeof fontFamilyValue === 'string') {
                    document.fonts.load(`1em '${fontFamilyValue}'`).then(() => {
                        if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
                    }).catch(err => {
                         console.error(`[Font Module DEBUG] Error waiting for font "${fontFamilyValue}" to be available after CSS load:`, err);
                         if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
                    });
                } else {
                    if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
                }
            };
            linkElement.onerror = () => {
                console.error('[Font Module DEBUG] Error loading Google Font CSS (onerror event):', fontUrl);
                if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
            };
            document.head.appendChild(linkElement);
        } else {
             if (typeof fontFamilyValue === 'string') {
                document.fonts.load(`1em '${fontFamilyValue}'`).then(() => {
                    if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
                }).catch(err => {
                     console.error(`[Font Module DEBUG] Error waiting for already loaded font "${fontFamilyValue}" (already processed URL):`, err);
                     if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
                });
            } else {
                if (typeof onFontLoaded === 'function') onFontLoaded(effectiveFontStack);
            }
        }
    }

    // Expose functions via the manager
    manager.fonts.loadGoogleFont = loadGoogleFont;
    manager.fonts.getFontStackByValue = getFontStackByValue; // Expose helper if needed elsewhere
    manager.fonts.getGoogleFontParamByValue = getGoogleFontParamByValue; // Expose helper if needed elsewhere

})(window.ExtrchLinkPageManager); // Pass the global manager object