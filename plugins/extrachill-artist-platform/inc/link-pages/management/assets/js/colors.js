(function(manager) {
    if (!manager || !manager.customization) {
        console.error("ExtrchLinkPageManager or its customization module not found. Colors script cannot run.");
        return;
    }
    manager.colors = manager.colors || {};

    // --- DOM Elements ---
    let buttonColorInput = null;
    let textColorInput = null;
    let linkTextColorInput = null;
    let hoverColorInput = null;
    let buttonBorderColorInput = null;

    function cacheDOMElements() {
        buttonColorInput = document.getElementById('link_page_button_color');
        textColorInput = document.getElementById('link_page_text_color');
        linkTextColorInput = document.getElementById('link_page_link_text_color');
        hoverColorInput = document.getElementById('link_page_hover_color');
        buttonBorderColorInput = document.getElementById('link_page_button_border_color');
    }

    function initializeColorControls() {
        // Check for the essential customization functions
        if (!manager.customization || 
            !manager.customization.attachControlListener || 
            !manager.customization.updateSetting) {
            return;
        }

        cacheDOMElements();

        // All attachControlListener calls now use updateSetting and emit events for direct preview updates.

        // Button Color
        if (buttonColorInput) {
            manager.customization.attachControlListener(buttonColorInput, '--link-page-button-bg-color', 'input', function(val) {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('buttonColorChanged', {
                    detail: { color: val }
                }));
                return val;
            });
        }

        // Text Color
        if (textColorInput) {
            manager.customization.attachControlListener(textColorInput, '--link-page-text-color', 'input', function(val) {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('textColorChanged', {
                    detail: { color: val }
                }));
                return val;
            });
        }

        // Link Text Color
        if (linkTextColorInput) {
            manager.customization.attachControlListener(linkTextColorInput, '--link-page-link-text-color', 'input', function(val) {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('linkTextColorChanged', {
                    detail: { color: val }
                }));
                return val;
            });
        }

        // Hover Color
        if (hoverColorInput) {
            manager.customization.attachControlListener(hoverColorInput, '--link-page-button-hover-bg-color', 'input', function(val) {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('hoverColorChanged', {
                    detail: { color: val }
                }));
                return val;
            });
        }
        
        // Button Border Color
        if (buttonBorderColorInput) {
            manager.customization.attachControlListener(buttonBorderColorInput, '--link-page-button-border-color', 'input', function(val) {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('buttonBorderColorChanged', {
                    detail: { color: val }
                }));
                return val;
            });
        }
        
        // Initialize preview functionality
        initializeColorPreview();
    }

    // --- Preview functionality (consolidating from colors-preview.js) ---
    function updateColorsPreview(colorData) {
        const previewEl = manager.getPreviewEl();
        if (colorData.property && colorData.value) {
            previewEl.style.setProperty(colorData.property, colorData.value);
        }
    }

    function initializeColorPreview() {
        // Event listeners for color changes
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

        document.addEventListener('colorChanged', function(e) {
            if (e.detail && e.detail.property && e.detail.color) {
                updateColorsPreview({
                    property: e.detail.property,
                    value: e.detail.color
                });
            }
        });
    }

    // Expose preview update function
    manager.colors.updatePreview = updateColorsPreview;

    // Public init function for the colors module
    manager.colors.init = function() {
        // Call initializeColorControls directly on DOMContentLoaded.
        // The dependency on customization.js means its core functions (like attachControlListener)
        // are guaranteed to be available by the time this script executes.
            initializeColorControls();

        // Removed the extrchLinkPageManagerInitialized event listener as it's causing timing issues.
    };

    // --- Initial Call to arm the init logic for the colors module ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', manager.colors.init);
    } else {
        manager.colors.init();
    }

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 