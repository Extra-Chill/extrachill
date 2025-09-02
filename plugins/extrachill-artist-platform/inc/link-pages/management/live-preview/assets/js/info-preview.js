// Info Preview Module - Handles live preview updates for text content (title, bio)
(function() {
    'use strict';
    
    // Update preview title - Direct DOM manipulation
    function updatePreviewTitle(newTitle, previewEl) {
        if (!previewEl) return;
        const titleElement = previewEl.querySelector('.extrch-link-page-title');
        if (titleElement) {
            titleElement.textContent = newTitle || '';
        }
    }

    // Update preview bio - Direct DOM manipulation  
    function updatePreviewBio(newBio, previewEl) {
        if (!previewEl) return;
        const bioElement = previewEl.querySelector('.extrch-link-page-bio');
        if (bioElement) {
            bioElement.textContent = newBio || '';
        }
    }


    // Event listeners for info updates from management forms
    document.addEventListener('titleChanged', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (e.detail && e.detail.title && previewEl) {
            updatePreviewTitle(e.detail.title, previewEl);
        }
    });

    document.addEventListener('bioChanged', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (e.detail && e.detail.bio && previewEl) {
            updatePreviewBio(e.detail.bio, previewEl);
        }
    });


    // Self-contained module - no global exposure needed

})();