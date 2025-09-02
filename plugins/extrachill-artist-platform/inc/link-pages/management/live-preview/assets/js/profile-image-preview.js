// Profile Image Preview Module - Handles live preview updates for profile image
(function() {
    'use strict';
    
    // Update preview profile image - Direct DOM manipulation for content
    function updatePreviewProfileImage(imageSrc, previewEl) {
        if (!previewEl) return;
        const imageElement = previewEl.querySelector('.extrch-link-page-profile-img img');
        if (imageElement && imageSrc) {
            imageElement.src = imageSrc;
            imageElement.style.display = 'block';
        }
    }

    // Remove preview profile image - Direct DOM manipulation for content
    function removePreviewProfileImage(previewEl) {
        if (!previewEl) return;
        const imageElement = previewEl.querySelector('.extrch-link-page-profile-img img');
        if (imageElement) {
            imageElement.src = '';
            imageElement.style.display = 'none';
        }
    }


    // Event listeners for profile image updates from management forms
    document.addEventListener('profileImageChanged', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); 
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (e.detail && e.detail.imageSrc && previewEl) {
            updatePreviewProfileImage(e.detail.imageSrc, previewEl);
        }
    });

    document.addEventListener('profileImageRemoved', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); 
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (previewEl) {
            removePreviewProfileImage(previewEl);
        }
    });


    // Self-contained module - no global exposure needed

})();