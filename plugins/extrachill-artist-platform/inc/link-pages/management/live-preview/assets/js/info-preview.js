// Info Preview Module - Handles live preview updates for profile info (title, bio, image)
(function(manager) {
    if (!manager) return;
    
    manager.infoPreview = manager.infoPreview || {};
    
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

    // Update preview profile image - Direct DOM manipulation
    function updatePreviewProfileImage(imageSrc, previewEl) {
        if (!previewEl) return;
        const imageElement = previewEl.querySelector('.extrch-link-page-profile-img img');
        if (imageElement && imageSrc) {
            imageElement.src = imageSrc;
            imageElement.style.display = 'block';
        }
    }

    // Remove preview profile image - Direct DOM manipulation
    function removePreviewProfileImage(previewEl) {
        if (!previewEl) return;
        const imageElement = previewEl.querySelector('.extrch-link-page-profile-img img');
        if (imageElement) {
            imageElement.src = '';
            imageElement.style.display = 'none';
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

    document.addEventListener('profileImageChanged', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (e.detail && e.detail.imageSrc && previewEl) {
            updatePreviewProfileImage(e.detail.imageSrc, previewEl);
        }
    });

    document.addEventListener('profileImageRemoved', function(e) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (previewEl) {
            removePreviewProfileImage(previewEl);
        }
    });

    // Expose functions on manager
    manager.infoPreview.updateTitle = updatePreviewTitle;
    manager.infoPreview.updateBio = updatePreviewBio;
    manager.infoPreview.updateImage = updatePreviewProfileImage;
    manager.infoPreview.removeImage = removePreviewProfileImage;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});