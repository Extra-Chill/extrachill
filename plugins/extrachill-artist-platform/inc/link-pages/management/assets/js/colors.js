(function() {
    'use strict';

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

    function loadInitialColorValues() {
        console.log('[Colors] Self-contained module ready - using form input values directly');
    }

    function initializeColorControls() {
        cacheDOMElements();
        
        loadInitialColorValues();

        // Button Color
        if (buttonColorInput) {
            buttonColorInput.addEventListener('input', function() {
                document.dispatchEvent(new CustomEvent('buttonColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }

        // Text Color
        if (textColorInput) {
            textColorInput.addEventListener('input', function() {
                document.dispatchEvent(new CustomEvent('textColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }

        // Link Text Color
        if (linkTextColorInput) {
            linkTextColorInput.addEventListener('input', function() {
                document.dispatchEvent(new CustomEvent('linkTextColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }

        // Hover Color
        if (hoverColorInput) {
            hoverColorInput.addEventListener('input', function() {
                document.dispatchEvent(new CustomEvent('hoverColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }
        
        // Button Border Color
        if (buttonBorderColorInput) {
            buttonBorderColorInput.addEventListener('input', function() {
                document.dispatchEvent(new CustomEvent('buttonBorderColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }
    }

    if (document.readyState !== 'loading') {
        initializeColorControls();
    } else {
        document.addEventListener('DOMContentLoaded', initializeColorControls);
    }

})(); 