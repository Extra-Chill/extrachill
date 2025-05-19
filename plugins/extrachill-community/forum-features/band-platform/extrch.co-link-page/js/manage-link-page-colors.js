(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Colors script cannot run.');
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
            console.error('Core customization functions (attachControlListener or updateSetting) not available for Colors module.');
            return;
        }

        cacheDOMElements();

        // All attachControlListener calls now rely on updateSetting to handle state and trigger specific preview updaters.
        // The directPreviewUpdate parameter (previously updatePreviewCssVar) is removed from attachControlListener.

        // Button Color
        if (buttonColorInput) {
            manager.customization.attachControlListener(buttonColorInput, '--link-page-button-color', 'input');
            // No separate 'change' listener needed if 'input' covers it via updateSetting
        }

        // Text Color
        if (textColorInput) {
            manager.customization.attachControlListener(textColorInput, '--link-page-text-color', 'input');
        }

        // Link Text Color
        if (linkTextColorInput) {
            manager.customization.attachControlListener(linkTextColorInput, '--link-page-link-text-color', 'input');
        }

        // Hover Color
        if (hoverColorInput) {
            manager.customization.attachControlListener(hoverColorInput, '--link-page-hover-color', 'input');
        }
        
        // Button Border Color
        if (buttonBorderColorInput) {
            manager.customization.attachControlListener(buttonBorderColorInput, '--link-page-button-border-color', 'input');
        }
        
        // console.log('ExtrchLinkPageManager Colors module initialized and listeners attached.');
    }

    // Public init function for the colors module
    manager.colors.init = function() {
        // Wait for the main customization module (the 'brain') to be fully initialized
        // before trying to attach listeners or use its functions.
        document.addEventListener('extrchLinkPageManagerInitialized', function initColorsOnceManagerReady() {
            // console.log('Colors module: extrchLinkPageManagerInitialized event received.');
            initializeColorControls();
            document.removeEventListener('extrchLinkPageManagerInitialized', initColorsOnceManagerReady);
        });
    };

    // --- Initial Call to arm the init logic for the colors module ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', manager.colors.init);
    } else {
        manager.colors.init();
    }

})(window.ExtrchLinkPageManager); 