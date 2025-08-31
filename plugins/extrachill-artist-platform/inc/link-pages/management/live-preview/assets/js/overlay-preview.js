// Overlay Preview Module - Handles live preview updates for overlay toggle
(function() {
    'use strict';
    
    // Main overlay preview update function - Direct DOM manipulation
    function updateOverlayPreview(overlayEnabled) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Find the content wrapper that receives overlay styling
        const wrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        if (!wrapper) return;

        // Handle both boolean and string values
        const isOverlayEnabled = overlayEnabled === true || 
                                overlayEnabled === '1' || 
                                overlayEnabled === 1 || 
                                overlayEnabled === 'true';
        
        if (isOverlayEnabled) {
            wrapper.classList.remove('no-overlay');
        } else {
            wrapper.classList.add('no-overlay');
        }
    }

    // Event listener for overlay changes
    document.addEventListener('overlayChanged', function(e) {
        if (e.detail && e.detail.overlay !== undefined) {
            updateOverlayPreview(e.detail.overlay);
        }
    });

    // Self-contained module - no global exposure needed

})();