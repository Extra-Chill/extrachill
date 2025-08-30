// Colors Preview Module - Handles live preview updates for color styling
(function(manager) {
    if (!manager) return;
    
    manager.colorsPreview = manager.colorsPreview || {};
    
    // Main colors preview update function - Update style tag, preview inherits automatically
    function updateColorsPreview(colorData) {
        if (colorData.property && colorData.value) {
            updateMainStyleTag(colorData.property, colorData.value);
        }
    }

    // Update main style tag - preview inherits changes automatically via CSS
    function updateMainStyleTag(cssProperty, value) {
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
            console.error('[Colors Preview] :root CSS rule missing from style block');
            return;
        }
        
        // Update CSS property - preview inherits automatically
        rootRule.style.setProperty(cssProperty, value);
    }

    // Event listeners for individual color changes
    document.addEventListener('buttonColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateColorsPreview({
                property: '--link-page-button-bg-color',
                value: e.detail.color
            });
        }
    });

    document.addEventListener('textColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateColorsPreview({
                property: '--link-page-text-color',
                value: e.detail.color
            });
        }
    });

    document.addEventListener('linkTextColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateColorsPreview({
                property: '--link-page-link-text-color',
                value: e.detail.color
            });
        }
    });

    document.addEventListener('hoverColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateColorsPreview({
                property: '--link-page-button-hover-bg-color',
                value: e.detail.color
            });
        }
    });

    document.addEventListener('buttonBorderColorChanged', function(e) {
        if (e.detail && e.detail.color) {
            updateColorsPreview({
                property: '--link-page-button-border-color',
                value: e.detail.color
            });
        }
    });

    // Generic color change event listener
    document.addEventListener('colorChanged', function(e) {
        if (e.detail && e.detail.property && e.detail.color) {
            updateColorsPreview({
                property: e.detail.property,
                value: e.detail.color
            });
        }
    });

    // Expose functions on manager
    manager.colorsPreview.update = updateColorsPreview;

    // Self-contained initialization - CSS variables are already loaded by PHP
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[Colors Preview] Self-contained module ready');
    });

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});