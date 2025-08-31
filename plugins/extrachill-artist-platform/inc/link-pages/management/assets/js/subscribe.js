// Subscription Settings Management Module ("Subscribe Brain")
// Handles real-time preview updates for the subscribe card in the Advanced tab
(function() {
    'use strict';
    
    // Helper function to escape HTML
    function escapeHTML(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/[&<"'`]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                case '`': return '&#96;';
                default: return match;
            }
        });
    }





    // Utility for escaping HTML (shared utility)  
    function escapeHTMLFallback(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/[&<"'`]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                case '`': return '&#96;';
                default: return match;
            }
        });
    }

    // Main init function
    function init() {
        // Get DOM elements
        const radioInputs = document.querySelectorAll('input[name="link_page_subscribe_display_mode"]');
        const descTextarea = document.getElementById('link_page_subscribe_description');
        if (!radioInputs.length || !descTextarea) return;
        // Helper to get current values
        function getCurrentMode() {
            const checked = Array.from(radioInputs).find(r => r.checked);
            return checked ? checked.value : 'icon_modal';
        }
        function getCurrentDesc() {
            return descTextarea.value;
        }
        // Dispatch events on changes for preview module to handle
        radioInputs.forEach(radio => {
            radio.addEventListener('change', function() {
                document.dispatchEvent(new CustomEvent('subscriptionModeChanged', {
                    detail: { 
                        mode: getCurrentMode(), 
                        description: getCurrentDesc() 
                    }
                }));
            });
        });
        // Dispatch event on description input
        descTextarea.addEventListener('input', function() {
            document.dispatchEvent(new CustomEvent('subscriptionDescriptionChanged', {
                detail: { 
                    mode: getCurrentMode(), 
                    description: getCurrentDesc() 
                }
            }));
        });
        // Initial sync
        // updateSubscribePreview(getCurrentMode(), getCurrentDesc());
    };

    // Optionally, auto-init if not using main manager
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(); 