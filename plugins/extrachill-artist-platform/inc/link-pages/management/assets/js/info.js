/**
 * Manage Link Page - Info Tab (Title, Bio, Profile Image)
 * Handles UI and calls the content engine for live preview updates.
 */
window.ExtrchLinkPageInfoManager = {
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

        this.fields.titleInput = document.getElementById('artist_profile_title');
        this.fields.bioTextarea = document.getElementById('link_page_bio_text');
        this.fields.profileImageUpload = document.getElementById('link_page_profile_image_upload');
        this.fields.removeProfileImageBtn = document.getElementById('bp-remove-profile-image-btn');
        this.fields.removeProfileImageHidden = document.getElementById('remove_link_page_profile_image_hidden');

        // Get the preview image element for live updates
        const previewEl = this.manager.getPreviewEl();
        const imgEl = previewEl.querySelector('.link-page-profile-image');
        if (imgEl) {
            this.originalImageSrc = imgEl.src;
            this.fields.profileImagePreview = imgEl;
        }
        
        this._attachEventListeners();
        this.updateRemoveButtonVisibility();
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

                this.manager.customization.updateSetting('--link-page-profile-img-url', e.target.result);
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
        this.manager.customization.updateSetting('--link-page-profile-img-url', '');
    },
    
    updateRemoveButtonVisibility: function(isImagePresent = null) {
        let imageActuallyPresent = false;
        
        if (isImagePresent !== null) {
            imageActuallyPresent = isImagePresent;
        } else {
            const previewEl = this.manager.getPreviewEl();
            const imgElement = previewEl.querySelector('.link-page-profile-image');
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
        
        this.manager.customization.updateSetting('--link-page-profile-img-url', imageUrlToUse);
    }
}; 

/**
 * Serializes current info settings into hidden inputs for form submission.
 * This method should ONLY be called by the save handler, not during user interactions.
 */
function serializeInfoForSave() {
    let success = true;
    
    // Serialize profile image removal flag
    const removeProfileImageHidden = document.getElementById('remove_link_page_profile_image_hidden');
    if (removeProfileImageHidden) {
        // Check if image was removed (no file selected and preview is hidden)
        const profileImageUpload = document.getElementById('link_page_profile_image_upload');
        const profileImagePreview = document.querySelector('.profile-image-preview');
        
        if (profileImageUpload && !profileImageUpload.files?.length && 
            profileImagePreview?.style.display === 'none') {
            removeProfileImageHidden.value = '1'; // Mark for removal
            console.log('[InfoManager] Marked profile image for removal');
        } else {
            removeProfileImageHidden.value = '0'; // Keep image
            console.log('[InfoManager] Profile image will be kept');
        }
    } else {
        console.warn('[InfoManager] Remove profile image hidden input not found');
        success = false;
    }
    
    return success;
}

// Expose the serialize method for the save handler
window.ExtrchLinkPageInfoManager.serializeForSave = serializeInfoForSave; 