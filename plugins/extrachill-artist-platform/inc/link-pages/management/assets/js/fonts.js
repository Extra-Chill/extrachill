// Link Page Font Management Module
// Self-contained module following server-first pattern - reads DOM values directly

(function() {
    'use strict';

    // DOM event handling for font family selects
    function initFontControls() {
        const titleFontFamilySelect = document.getElementById('link_page_title_font_family');
        const bodyFontFamilySelect = document.getElementById('link_page_body_font_family');

        // DO NOT override dropdown values here - PHP template sets correct selected values
        // JavaScript should only handle change events, not initialization

        // Title font family event handling
        if (titleFontFamilySelect) {
            titleFontFamilySelect.addEventListener('change', function() {
                const fontValue = this.value;
                
                // Update CSS variable directly
                const styleTag = document.getElementById('extrch-link-page-custom-vars');
                if (styleTag?.sheet) {
                    for (let rule of styleTag.sheet.cssRules) {
                        if (rule.selectorText === ':root') {
                            rule.style.setProperty('--link-page-title-font-family', fontValue);
                            break;
                        }
                    }
                }
                
                // Emit simple event with font value - preview system handles metadata conversion
                document.dispatchEvent(new CustomEvent('titleFontFamilyChanged', {
                    detail: { fontFamily: fontValue }
                }));
            });
        }

        // Body font family event handling
        if (bodyFontFamilySelect) {
            bodyFontFamilySelect.addEventListener('change', function() {
                const fontValue = this.value;
                
                // Update CSS variable directly
                const styleTag = document.getElementById('extrch-link-page-custom-vars');
                if (styleTag?.sheet) {
                    for (let rule of styleTag.sheet.cssRules) {
                        if (rule.selectorText === ':root') {
                            rule.style.setProperty('--link-page-body-font-family', fontValue);
                            break;
                        }
                    }
                }
                
                // Emit simple event with font value - preview system handles metadata conversion
                document.dispatchEvent(new CustomEvent('bodyFontFamilyChanged', {
                    detail: { fontFamily: fontValue }
                }));
            });
        }
    }

    // Self-contained module - no global function exposure needed

    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        initFontControls();
    } else {
        document.addEventListener('DOMContentLoaded', initFontControls);
    }

})();