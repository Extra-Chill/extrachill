/**
 * Manage Link Page - Info Tab (Title, Bio, Profile Image)
 * Handles UI and calls the content engine for live preview updates.
 */
const ExtrchLinkPageInfoManager = {
    manager: null,
    fields: {
        titleInput: null,
        bioTextarea: null,
        profileImageUpload: null,
        removeProfileImageBtn: null,
        removeProfileImageHidden: null,
    },
    originalImageSrc: null, // To store the initial image src for restoration

    init: function(manager) {
        this.manager = manager;

        this.fields.titleInput = document.getElementById('band_profile_title');
        this.fields.bioTextarea = document.getElementById('link_page_bio_text');
        this.fields.profileImageUpload = document.getElementById('link_page_profile_image_upload');
        this.fields.removeProfileImageBtn = document.getElementById('bp-remove-profile-image-btn');
        this.fields.removeProfileImageHidden = document.getElementById('remove_link_page_profile_image_hidden');

        if (this.manager && this.manager.getPreviewEl) {
            const previewEl = this.manager.getPreviewEl();
            if (previewEl) {
                const imgEl = previewEl.querySelector('.link-page-profile-image');
                if (imgEl) {
                    this.originalImageSrc = imgEl.src;
                }
            }
        }
        
        this._attachEventListeners();
        this.updateRemoveButtonVisibility();
    },

    _attachEventListeners: function() {
        if (this.fields.titleInput) {
            this.fields.titleInput.addEventListener('input', this._handleTitleChange.bind(this));
        }
        if (this.fields.bioTextarea) {
            this.fields.bioTextarea.addEventListener('input', this._handleBioChange.bind(this));
        }
        if (this.fields.profileImageUpload) {
            this.fields.profileImageUpload.addEventListener('change', this._handleProfileImageChange.bind(this));
        }
        if (this.fields.removeProfileImageBtn) {
            this.fields.removeProfileImageBtn.addEventListener('click', this._handleRemoveProfileImage.bind(this));
        }
    },

    _handleTitleChange: function(event) {
        const newTitle = event.target.value;
        if (this.manager && this.manager.contentPreview && this.manager.contentPreview.updatePreviewTitle) {
            this.manager.contentPreview.updatePreviewTitle(newTitle, this.manager.getPreviewEl());
        }
        // The actual band_profile title update (and sync to band_link_page post_title) happens server-side on form save.
        // No need to update hidden input for this, as 'band_profile_title' is part of the form.
    },

    _handleBioChange: function(event) {
        const newBio = event.target.value;
        if (this.manager && this.manager.contentPreview && this.manager.contentPreview.updatePreviewBio) {
            this.manager.contentPreview.updatePreviewBio(newBio, this.manager.getPreviewEl());
        }
        // 'link_page_bio_text' is part of the form, server handles saving.
    },

    _handleProfileImageChange: function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                if (this.manager && this.manager.contentPreview && this.manager.contentPreview.updatePreviewProfileImage) {
                    this.manager.contentPreview.updatePreviewProfileImage(e.target.result, this.manager.getPreviewEl());
                    this.fields.removeProfileImageHidden.value = '0'; // Image selected, so not removing
                    this.updateRemoveButtonVisibility(true); // an image is present

                    // Notify Styles Brain (Customization Manager) to update customVars
                    if (this.manager.customization && typeof this.manager.customization.updateSetting === 'function') {
                        this.manager.customization.updateSetting('--link-page-profile-img-url', e.target.result);
                    }
                }
            };
            reader.readAsDataURL(file);
        }
    },

    _handleRemoveProfileImage: function() {
        if (this.manager && this.manager.contentPreview && this.manager.contentPreview.removePreviewProfileImage) {
            this.manager.contentPreview.removePreviewProfileImage(this.manager.getPreviewEl());
            this.fields.profileImageUpload.value = ''; // Clear the file input
            this.fields.removeProfileImageHidden.value = '1'; // Set hidden input to indicate removal
            this.updateRemoveButtonVisibility(false); // no image is present

            // Notify Styles Brain (Customization Manager) to update customVars
            if (this.manager.customization && typeof this.manager.customization.updateSetting === 'function') {
                this.manager.customization.updateSetting('--link-page-profile-img-url', '');
            }
        }
    },
    
    updateRemoveButtonVisibility: function(isImagePresentOverride = null) {
        if (!this.fields.removeProfileImageBtn || !this.manager || !this.manager.getPreviewEl) return;

        let imageActuallyPresent = false;
        if (isImagePresentOverride !== null) {
            imageActuallyPresent = isImagePresentOverride;
        } else {
            const previewEl = this.manager.getPreviewEl();
            const imgElement = previewEl.querySelector('.link-page-profile-image');
            if (imgElement) {
                // Check if src is not empty, not a placeholder, and remove hidden input is not 1
                const imgSrc = imgElement.getAttribute('src');
                imageActuallyPresent = imgSrc && imgSrc.trim() !== '' && !imgSrc.includes('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); // Check against transparent pixel or empty
            }
             // Consider the hidden field for removal state if no override is given
            if (this.fields.removeProfileImageHidden && this.fields.removeProfileImageHidden.value === '1') {
                imageActuallyPresent = false;
            }
        }
        
        this.fields.removeProfileImageBtn.style.display = imageActuallyPresent ? 'inline-block' : 'none';
    },

    // Called by main manager if AJAX (old way) updated the profile image
    // or if initial data loads an image.
    syncExternalImageUpdate: function(newImageUrl) {
        const imageUrlToUse = newImageUrl || '';
        if (imageUrlToUse && imageUrlToUse.trim() !== '') {
            this.originalImageSrc = imageUrlToUse;
             if (this.manager && this.manager.contentPreview && this.manager.contentPreview.updatePreviewProfileImage) {
                this.manager.contentPreview.updatePreviewProfileImage(imageUrlToUse, this.manager.getPreviewEl());
             }
            this.updateRemoveButtonVisibility(true);
        } else {
            this.originalImageSrc = null; // or a placeholder if you have one
            if (this.manager && this.manager.contentPreview && this.manager.contentPreview.removePreviewProfileImage) {
                this.manager.contentPreview.removePreviewProfileImage(this.manager.getPreviewEl());
            }
            this.updateRemoveButtonVisibility(false);
        }
        // Notify Styles Brain (Customization Manager) to update customVars
        if (this.manager.customization && typeof this.manager.customization.updateSetting === 'function') {
            this.manager.customization.updateSetting('--link-page-profile-img-url', imageUrlToUse);
        }
    }
}; 