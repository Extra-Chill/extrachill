/**
 * Manage Link Page - Info Tab (Title, Bio, Profile Image) - Self-Contained
 * Handles UI and dispatches events for live preview updates.
 */
(function() {
    'use strict';
    
    const InfoManager = {
    fields: {
        titleInput: null,
        bioTextarea: null,
        profileImageUpload: null,
        removeProfileImageBtn: null,
        removeProfileImageHidden: null,
    },
    originalImageSrc: null, // To store the initial image src for restoration

    init: function() {

        this.fields.titleInput = document.getElementById('artist_profile_title');
        this.fields.bioTextarea = document.getElementById('link_page_bio_text');
        this.fields.profileImageUpload = document.getElementById('link_page_profile_image_upload');
        this.fields.removeProfileImageBtn = document.getElementById('bp-remove-profile-image-btn');
        this.fields.removeProfileImageHidden = document.getElementById('remove_link_page_profile_image_hidden');

        // Get the preview image element for live updates
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        const imgEl = previewEl?.querySelector('.link-page-profile-image');
        if (imgEl) {
            this.originalImageSrc = imgEl.src;
            this.fields.profileImagePreview = imgEl;
        }
        
        this._attachEventListeners();
        this.updateRemoveButtonVisibility();
        this._loadInitialValues();
    },

    _loadInitialValues: function() {
        // Self-contained - no external dependencies needed
        console.log('[Info] Using form field values directly');
    },

    _attachEventListeners: function() {
        this.fields.titleInput.addEventListener('input', this._handleTitleChange.bind(this));
        this.fields.bioTextarea.addEventListener('input', this._handleBioChange.bind(this));
        this.fields.profileImageUpload.addEventListener('change', this._handleProfileImageChange.bind(this));
        this.fields.removeProfileImageBtn.addEventListener('click', this._handleRemoveProfileImage.bind(this));
    },

    _handleTitleChange: function(event) {
        const newTitle = event.target.value;
        document.dispatchEvent(new CustomEvent('titleChanged', {
            detail: { title: newTitle }
        }));
        // The actual artist_profile title update (and sync to artist_link_page post_title) happens server-side on form save.
        // No need to update hidden input for this, as 'artist_profile_title' is part of the form.
    },

    _handleBioChange: function(event) {
        const newBio = event.target.value;
        document.dispatchEvent(new CustomEvent('bioChanged', {
            detail: { bio: newBio }
        }));
        // 'link_page_bio_text' is part of the form, server handles saving.
    },

    _handleProfileImageChange: function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                document.dispatchEvent(new CustomEvent('profileImageChanged', {
                    detail: { imageSrc: e.target.result }
                }));
                this.fields.removeProfileImageHidden.value = '0'; // Image selected, so not removing
                this.updateRemoveButtonVisibility(true); // an image is present
            };
            reader.readAsDataURL(file);
        }
    },

    _handleRemoveProfileImage: function(event) {
        event.preventDefault();
        
        document.dispatchEvent(new CustomEvent('profileImageRemoved'));
        this.fields.profileImageUpload.value = ''; // Clear the file input
        this.fields.removeProfileImageHidden.value = '1'; // Set hidden input to indicate removal
        this.updateRemoveButtonVisibility(false);
    },
    
    updateRemoveButtonVisibility: function(isImagePresent = null) {
        let imageActuallyPresent = false;
        
        if (isImagePresent !== null) {
            imageActuallyPresent = isImagePresent;
        } else {
            const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
            const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
            const imgElement = previewEl?.querySelector('.link-page-profile-image');
            if (imgElement) {
                const imgSrc = imgElement.getAttribute('src');
                imageActuallyPresent = imgSrc && imgSrc.trim() !== '' && !imgSrc.includes('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            }
            
            if (this.fields.removeProfileImageHidden.value === '1') {
                imageActuallyPresent = false;
            }
        }
        
        this.fields.removeProfileImageBtn.style.display = imageActuallyPresent ? 'inline-block' : 'none';
    },

    // Called by main manager if AJAX (old way) updated the profile image
    // or if initial data loads an image.
    syncExternalImageUpdate: function(newImageUrl) {
        const imageUrlToUse = newImageUrl || '';
        if (imageUrlToUse.trim() !== '') {
            this.originalImageSrc = imageUrlToUse;
            document.dispatchEvent(new CustomEvent('profileImageChanged', {
                detail: { imageSrc: imageUrlToUse }
            }));
            this.updateRemoveButtonVisibility(true);
        } else {
            this.originalImageSrc = null;
            document.dispatchEvent(new CustomEvent('profileImageRemoved'));
            this.updateRemoveButtonVisibility(false);
        }
    }
};

// No serialization needed - form fields handle all data persistence

    // Listen for info tab activation
    document.addEventListener('infoTabActivated', function(event) {
        InfoManager.init();
    });

    // Auto-initialize when DOM is ready (for default tab)
    if (document.readyState !== 'loading') {
        InfoManager.init();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            InfoManager.init();
        });
    }

})(); 