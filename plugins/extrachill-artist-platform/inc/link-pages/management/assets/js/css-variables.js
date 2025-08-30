/**
 * CSS Variables utility module - DEPRECATED
 * 
 * This abstraction layer is being phased out in favor of self-contained preview modules.
 * Only getCustomVars() remains for compatibility with legacy modules.
 * 
 * Current pattern: Preview modules update CSS variables directly via CSSOM,
 * management modules dispatch events only.
 */
(function(manager) {
    if (!manager) return;
    
    manager.customization = manager.customization || {};

    // DEPRECATED: Use self-contained preview modules instead
    // manager.customization.updateSetting = function(key, value) { /* REMOVED */ };

    // --- Getter for customVars (temporary compatibility for other modules) ---
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