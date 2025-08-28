(function(manager) {
    if (!manager || !manager.customization) {
        // console.error("ExtrchLinkPageManager or its customization module not found. Colors script cannot run."); // Keep
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
        // Check for the essential functions from the 'brain' (customization module)
        if (!manager.customization || 
            !manager.customization.attachControlListener || 
            !manager.customization.updateSetting) { // updateSetting is the key function we now call
            // console.error('Core customization functions (attachControlListener or updateSetting) not available for Colors module.'); // Comment out
            return;
        }

        cacheDOMElements();

        // All attachControlListener calls now rely on updateSetting to handle state and trigger specific preview updaters.
        // The directPreviewUpdate parameter (previously updatePreviewCssVar) is removed from attachControlListener.

        // Button Color
        if (buttonColorInput) {
            manager.customization.attachControlListener(buttonColorInput, '--link-page-button-bg-color', 'input', function(val) {
                // console.log('[Colors] Button Color Input Event:', val); // Comment out
                return val;
            });
        }

        // Text Color
        if (textColorInput) {
            manager.customization.attachControlListener(textColorInput, '--link-page-text-color', 'input', function(val) {
                // console.log('[Colors] Text Color Input Event:', val); // Comment out
                return val;
            });
        }

        // Link Text Color
        if (linkTextColorInput) {
            manager.customization.attachControlListener(linkTextColorInput, '--link-page-link-text-color', 'input', function(val) {
                // console.log('[Colors] Link Text Color Input Event:', val); // Comment out
                return val;
            });
        }

        // Hover Color
        if (hoverColorInput) {
            manager.customization.attachControlListener(hoverColorInput, '--link-page-button-hover-bg-color', 'input', function(val) {
                // console.log('[Colors] Hover Color Input Event:', val); // Comment out
                return val;
            });
        }
        
        // Button Border Color
        if (buttonBorderColorInput) {
            manager.customization.attachControlListener(buttonBorderColorInput, '--link-page-button-border-color', 'input', function(val) {
                // console.log('[Colors] Button Border Color Input Event:', val); // Comment out
                return val;
            });
        }
        
        // console.log('ExtrchLinkPageManager Colors module initialized and listeners attached.'); // Comment out
    }

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