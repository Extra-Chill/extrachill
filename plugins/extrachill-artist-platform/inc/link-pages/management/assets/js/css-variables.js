/**
 * CSS Variables Utility - Essential CSS variable management
 * Provides updateSetting and getCustomVars functions for modules
 */

// CSS Variables utility module
(function(manager) {
    if (!manager) return;
    
    manager.customization = manager.customization || {};

    // --- Function to update a setting and trigger CSS variable update ---
    manager.customization.updateSetting = function(key, value) {
        // Special handling for overlay (not a CSS variable - it's a class toggle)
        if (key === 'overlay') {
            // Emit event for overlay changes
            document.dispatchEvent(new CustomEvent('overlayChanged', {
                detail: { overlay: value }
            }));
            return;
        }
        
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (!styleTag) return;
        
        let sheet = styleTag.sheet;
        if (!sheet) return;
        
        let rootRule = null;
        for (let i = 0; i < sheet.cssRules.length; i++) {
            if (sheet.cssRules[i].selectorText === ':root') {
                rootRule = sheet.cssRules[i];
                break;
            }
        }
        
        if (!rootRule) {
            // If :root rule doesn't exist, create it
            try {
                sheet.insertRule(':root {}', sheet.cssRules.length);
                rootRule = sheet.cssRules[sheet.cssRules.length - 1];
            } catch (e) {
                return;
            }
        }
        rootRule.style.setProperty(key, value);
    };

    // --- Getter for customVars (for serialization before save) ---
    manager.customization.getCustomVars = function() {
        // Parse the style tag for all CSS vars - READ FROM CSSOM, NOT textContent
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        if (!styleTag) return {};
        
        let vars = {};
        
        // Use CSSOM to read current CSS variables (including dynamic updates)
        let sheet = styleTag.sheet;
        if (sheet && sheet.cssRules) {
            for (let i = 0; i < sheet.cssRules.length; i++) {
                if (sheet.cssRules[i].selectorText === ':root') {
                    const rootRule = sheet.cssRules[i];
                    // Read all CSS custom properties from the :root rule
                    for (let j = 0; j < rootRule.style.length; j++) {
                        const property = rootRule.style[j];
                        if (property.startsWith('--')) {
                            const value = rootRule.style.getPropertyValue(property);
                            if (value) {
                                vars[property] = value.trim();
                            }
                        }
                    }
                    break;
                }
            }
        }
        
        return vars;
    };

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});