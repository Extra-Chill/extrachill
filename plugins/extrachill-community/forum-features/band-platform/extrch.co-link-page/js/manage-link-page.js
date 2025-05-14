// Initialize the global manager object if it doesn't exist
window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {};

// Store initial data passed from PHP into the manager
ExtrchLinkPageManager.initialData = window.extrchInitialLinkPageData || {};
ExtrchLinkPageManager.initialLinkSectionsData = window.bpLinkPageLinks || [];
ExtrchLinkPageManager.ajaxConfig = window.extrchLinkPagePreviewAJAX || {};

// Live state for unsaved data used in previews
ExtrchLinkPageManager.liveState = {
    profileImgUrl: ExtrchLinkPageManager.initialData.profile_img_url || '',
    bgImgUrl: ExtrchLinkPageManager.initialData.background_image_url || ''
};

// --- Debounce function (local to this script, or could be part of a Utils section in Manager) ---
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

// --- Main AJAX Live Preview Update Function (now a method of the Manager) ---
ExtrchLinkPageManager.updatePreviewViaAJAX = function() {
    if (!ExtrchLinkPageManager.ajaxConfig || !ExtrchLinkPageManager.ajaxConfig.ajax_url) {
        console.error('AJAX preview data or ajax_url not available in ExtrchLinkPageManager.ajaxConfig.');
        return;
    }

    const previewEl = document.querySelector('.manage-link-page-preview-live');
    if (!previewEl) return;

    // Always get the latest customVars from the customization module
    let customVarsJson = '';
    if (window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.customization && typeof window.ExtrchLinkPageManager.customization.getCustomVarsJson === 'function') {
        customVarsJson = window.ExtrchLinkPageManager.customization.getCustomVarsJson();
    } else if (document.getElementById('link_page_custom_css_vars_json')) {
        // Fallback for legacy: use hidden input if needed
        customVarsJson = document.getElementById('link_page_custom_css_vars_json').value;
    }

    const formData = {
        action: 'extrch_render_link_page_preview',
        security_nonce: ExtrchLinkPageManager.ajaxConfig.nonce,
        link_page_id: ExtrchLinkPageManager.ajaxConfig.link_page_id,
        band_id: ExtrchLinkPageManager.ajaxConfig.band_id,

        // Info Tab
        band_profile_title: document.getElementById('band_profile_title')?.value,
        link_page_bio_text: document.getElementById('link_page_bio_text')?.value,
        profile_img_url: ExtrchLinkPageManager.liveState.profileImgUrl || ExtrchLinkPageManager.ajaxConfig.initial_profile_img_url,
        
        // Links Tab
        band_profile_social_links_json: document.getElementById('band_profile_social_links_json')?.value,
        link_page_links_json: document.getElementById('link_page_links_json')?.value,

        // Customize Tab
        link_page_background_type: document.getElementById('link_page_background_type')?.value,
        link_page_background_color: document.getElementById('link_page_background_color')?.value,
        link_page_background_gradient_start: document.getElementById('link_page_background_gradient_start')?.value,
        link_page_background_gradient_end: document.getElementById('link_page_background_gradient_end')?.value,
        link_page_background_gradient_direction: document.getElementById('link_page_background_gradient_direction')?.value,
        background_image_url: ExtrchLinkPageManager.liveState.bgImgUrl !== undefined ? ExtrchLinkPageManager.liveState.bgImgUrl : ExtrchLinkPageManager.ajaxConfig.initial_background_img_url,
        link_page_custom_css_vars_json: customVarsJson
    };

    if (typeof jQuery !== 'undefined') {
        jQuery.post(ExtrchLinkPageManager.ajaxConfig.ajax_url, formData, function(response) {
            if (response && response.success && response.data && typeof response.data.html !== 'undefined') {
                if (previewEl) {
                    // Step 1: Server HTML loaded
                    previewEl.innerHTML = response.data.html;

                    // Step 2: Force re-apply client-side profile image if it's a data URI
                    if (ExtrchLinkPageManager.liveState.profileImgUrl && ExtrchLinkPageManager.liveState.profileImgUrl.startsWith('data:image')) {
                        const previewProfileImg = previewEl.querySelector('.extrch-link-page-profile-img img');
                        if (previewProfileImg) {
                            previewProfileImg.src = ExtrchLinkPageManager.liveState.profileImgUrl;
                        }
                    }

                    // Step 3: General CSS variables & profile image SHAPE (via customization module)
                    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.reapplyStyles === 'function') {
                        // Only reapply styles to the preview, do NOT call setInputFieldsFromCustomVars() or update the hidden input value here.
                        ExtrchLinkPageManager.customization.reapplyStyles();
                    }
                }
            } else {
                console.error('[AJAX Preview] Error: Invalid response.', response);
                if (previewEl) previewEl.innerHTML = '<div class="preview-error">Error loading preview.</div>';
            }
        }).fail((xhr, status, error) => {
            console.error('[AJAX Preview] Request Failed:', status, error, xhr);
            if (previewEl) previewEl.innerHTML = '<div class="preview-error">Preview request failed.</div>';
        });
    } else {
        console.error('jQuery not available.');
    }
};

// Link Sections logic is now in manage-link-page-links.js
// Social Icons logic is now in manage-link-page-socials.js

// --- DOMContentLoaded ---
document.addEventListener('DOMContentLoaded', function() {
    if (ExtrchLinkPageManager.initialData && ExtrchLinkPageManager.ajaxConfig) {
        ExtrchLinkPageManager.liveState.profileImgUrl = ExtrchLinkPageManager.liveState.profileImgUrl || ExtrchLinkPageManager.ajaxConfig.initial_profile_img_url || '';
        ExtrchLinkPageManager.liveState.bgImgUrl = ExtrchLinkPageManager.liveState.bgImgUrl || ExtrchLinkPageManager.ajaxConfig.initial_background_img_url || '';
    }

    const debouncedUpdatePreview = debounce(ExtrchLinkPageManager.updatePreviewViaAJAX, 300);

    const fieldsToWatchForAJAX = [
        'band_profile_title',
        'link_page_bio_text', 
        'link_page_background_type'
    ];
    const fieldsRequiringDebounce = ['band_profile_title', 'link_page_bio_text'];

    fieldsToWatchForAJAX.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            const eventType = (element.tagName === 'SELECT' || element.type === 'checkbox') ? 'change' : 'input';
            let updateFunction = fieldsRequiringDebounce.includes(id) ? debouncedUpdatePreview : ExtrchLinkPageManager.updatePreviewViaAJAX;
            element.addEventListener(eventType, updateFunction);
        }
    });
    
    const profileImgUploadInput = document.getElementById('link_page_profile_image_upload');
    if (profileImgUploadInput) {
        profileImgUploadInput.addEventListener('change', function(e){
            const file = e.target.files && e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    ExtrchLinkPageManager.liveState.profileImgUrl = evt.target.result; 
                    const previewImg = document.querySelector('.manage-link-page-preview-live .extrch-link-page-profile-img img');
                    if(previewImg) previewImg.src = evt.target.result; // Immediate visual update
                    
                    ExtrchLinkPageManager.updatePreviewViaAJAX(); // Trigger full preview refresh
                };
                reader.readAsDataURL(file);
            } else {
                 ExtrchLinkPageManager.liveState.profileImgUrl = ExtrchLinkPageManager.ajaxConfig.initial_profile_img_url || ''; 
                 ExtrchLinkPageManager.updatePreviewViaAJAX(); // Also trigger AJAX update if the file is cleared
            }
        });
    }

    const removeProfileImgBtn = document.getElementById('bp-remove-profile-image-btn');
    const removeProfileImgHiddenInput = document.getElementById('remove_link_page_profile_image_hidden');
    if (removeProfileImgBtn && removeProfileImgHiddenInput) {
        removeProfileImgBtn.addEventListener('click', function() {
            ExtrchLinkPageManager.liveState.profileImgUrl = ''; // Clear the live state URL
            const previewImg = document.querySelector('.manage-link-page-preview-live .extrch-link-page-profile-img img');
            if (previewImg) {
                previewImg.src = ''; // Or set to a default placeholder image path
            }
            // Set the hidden input to 1 so the form handler processes removal on save
            removeProfileImgHiddenInput.value = '1'; 
             // Clear the file input value, if any, to prevent re-uploading an old selection if user saves without choosing new image
            if (profileImgUploadInput) {
                profileImgUploadInput.value = null;
            }

            ExtrchLinkPageManager.updatePreviewViaAJAX(); // Trigger full preview refresh
        });
    }

    // Initialize other modules if they exist and have an init function
    if (ExtrchLinkPageManager.links && typeof ExtrchLinkPageManager.links.init === 'function') {
        ExtrchLinkPageManager.links.init();
    }

    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.reapplyStyles === 'function') {
        ExtrchLinkPageManager.customization.reapplyStyles();
    }

    // --- Handle Save Button Click for Upload Feedback ---
    const form = document.getElementById('bp-manage-link-page-form');
    const saveButton = form ? form.querySelector('button[name="bp_save_link_page"]') : null;
    const saveButtonWrapper = document.querySelector('.bp-link-page-save-btn-wrap');
    const profileImageInput = document.getElementById('link_page_profile_image_upload');
    const backgroundImageInput = document.getElementById('link_page_background_image_upload'); // Added check for background image

    if (form && saveButton && saveButtonWrapper) {
        form.addEventListener('submit', function(event) {
            let newProfileImageSelected = profileImageInput && profileImageInput.files && profileImageInput.files.length > 0;
            let newBackgroundImageSelected = backgroundImageInput && backgroundImageInput.files && backgroundImageInput.files.length > 0;

            if (newProfileImageSelected || newBackgroundImageSelected) {
                // Disable button
                // saveButton.disabled = true;
                // saveButton.style.opacity = '0.7';

                // Add loading message
                let feedbackDiv = saveButtonWrapper.querySelector('.bp-save-feedback');
                if (!feedbackDiv) {
                    feedbackDiv = document.createElement('div');
                    feedbackDiv.className = 'bp-save-feedback';
                    feedbackDiv.style.marginTop = '10px';
                    feedbackDiv.style.color = 'var(--text-color)';
                    feedbackDiv.style.fontSize = '0.9em';
                    saveButtonWrapper.appendChild(feedbackDiv);
                }
                feedbackDiv.textContent = 'Saving, please wait... Image processing may take a moment.';
                // You could add a spinner here too if desired
            }
            // If no new image, the form submits normally without the message.
            // The button will be re-enabled on page reload by default.
        });
    }
}); 