window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {};

// Consume localized config for AJAX and IDs
if (typeof extrchLinkPageConfig !== 'undefined') {
    ExtrchLinkPageManager.ajaxConfig = {
        ajax_url: extrchLinkPageConfig.ajax_url,
        nonce: extrchLinkPageConfig.nonce,
        link_page_id: extrchLinkPageConfig.link_page_id,
        band_id: extrchLinkPageConfig.band_id
    };
} else {
    console.error('ExtrchLinkPageConfig not localized. AJAX functionality may be affected.');
    ExtrchLinkPageManager.ajaxConfig = {}; // Ensure the object exists
}

// Defer initialization of initialData and liveState until DOMContentLoaded
// ExtrchLinkPageManager.initialData = window.extrchInitialLinkPageData || {};
// ExtrchLinkPageManager.initialLinkSectionsData = window.bpLinkPageLinks || [];
// ExtrchLinkPageManager.liveState = {
//     profileImgUrl: (ExtrchLinkPageManager.initialData && ExtrchLinkPageManager.initialData.profile_img_url) || '',
//     bgImgUrl: (ExtrchLinkPageManager.initialData && ExtrchLinkPageManager.initialData.background_image_url) || ''
// };

// --- Function to get the preview container element ---
ExtrchLinkPageManager.getPreviewEl = function() {
    const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
    if (previewContainerParent) {
        const previewContainer = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (previewContainer) {
            return previewContainer;
        }
        // console.warn('[Manager] .extrch-link-page-preview-container not found within .manage-link-page-preview-live.');
        return null;
    }
    // console.warn('[Manager] .manage-link-page-preview-live not found.');
    return null;
};

// --- Debounce function ---
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

// --- Main AJAX Live Preview Update Function ---
ExtrchLinkPageManager.updatePreviewViaAJAX = function() {
    if (!ExtrchLinkPageManager.ajaxConfig || !ExtrchLinkPageManager.ajaxConfig.ajax_url) {
        // console.error('AJAX preview data or ajax_url not available in ExtrchLinkPageManager.ajaxConfig.');
        return;
    }

    const previewEl = document.querySelector('.manage-link-page-preview-live');
    if (!previewEl) return;

    let customVarsJson = '';
    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.getCustomVarsJson === 'function') {
        customVarsJson = ExtrchLinkPageManager.customization.getCustomVarsJson();
    } else if (document.getElementById('link_page_custom_css_vars_json')) {
        customVarsJson = document.getElementById('link_page_custom_css_vars_json').value;
    }

    const formData = {
        action: 'extrch_render_link_page_preview',
        security_nonce: ExtrchLinkPageManager.ajaxConfig.nonce,
        link_page_id: ExtrchLinkPageManager.ajaxConfig.link_page_id,
        band_id: ExtrchLinkPageManager.ajaxConfig.band_id,
        band_profile_social_links_json: document.getElementById('band_profile_social_links_json')?.value,
        link_page_links_json: document.getElementById('link_page_links_json')?.value,
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
                    previewEl.innerHTML = response.data.html;
                    if (ExtrchLinkPageManager.liveState.profileImgUrl && ExtrchLinkPageManager.liveState.profileImgUrl.startsWith('data:image')) {
                        const previewProfileImg = previewEl.querySelector('.extrch-link-page-profile-img img');
                        if (previewProfileImg) {
                            previewProfileImg.src = ExtrchLinkPageManager.liveState.profileImgUrl;
                        }
                    }
                    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.reapplyStyles === 'function') {
                        ExtrchLinkPageManager.customization.reapplyStyles();
                    }
                }
            } else {
                // console.error('[AJAX Preview] Error: Invalid response.', response);
                if (previewEl) previewEl.innerHTML = '<div class="preview-error">Error loading preview.</div>';
            }
        }).fail((xhr, status, error) => {
            // console.error('[AJAX Preview] Request Failed:', status, error, xhr);
            if (previewEl) previewEl.innerHTML = '<div class="preview-error">Preview request failed.</div>';
        });
    } else {
        // console.error('jQuery not available.');
    }
};

// --- Form Submission Handler (simplified, actual image upload happens via form submission) ---
ExtrchLinkPageManager.handleFormSubmission = function(event) {
    // This function can be expanded if specific client-side actions are needed BEFORE form submission
    // For now, it's mostly a placeholder for the bp_save_link_page action.
    // The save button feedback logic is separate.
};


// ----- Jump to Preview Button Logic -----
ExtrchLinkPageManager.initializeJumpToPreview = function() {
    const jumpButton = document.getElementById('extrch-jump-to-preview-btn');
    const previewElement = document.querySelector('.manage-link-page-preview-live');
    const mobileBreakpoint = 768; // Screen width in pixels

    if (!jumpButton || !previewElement) return;

    const mainIconElement = jumpButton.querySelector('.main-icon-wrapper i');
    const arrowIconElement = jumpButton.querySelector('.directional-arrow');

    if (!mainIconElement || !arrowIconElement) {
        console.warn('Jump to preview button is missing main or arrow icon elements.');
        return;
    }

    let isPreviewVisible = false;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            isPreviewVisible = entry.isIntersecting;
            toggleButtonState(); 
        });
    }, { threshold: 0.1 });

    observer.observe(previewElement);

    function getActiveSettingsElement() {
        // UPDATED SELECTORS to use shared classes
        let activeTab = document.querySelector('.shared-tabs-buttons-container .shared-tab-button.active');
        if (activeTab) return activeTab;

        // Fallback to accordion item if desktop tab not found (or for explicit mobile check)
        // This selector might also need adjustment if the structure of .shared-tab-item is different
        const activeAccordionHeader = document.querySelector('.shared-tab-item .shared-tab-button.active');
        if (activeAccordionHeader) {
            return activeAccordionHeader.closest('.shared-tab-item') || activeAccordionHeader;
        }
        return null; 
    }

    function toggleButtonState() {
        const isMobile = window.innerWidth <= mobileBreakpoint;
        
        if (isMobile) {
            jumpButton.style.display = 'flex'; // Always show on mobile, icons change
            setTimeout(() => jumpButton.classList.add('visible'), 10);

            const previewRect = previewElement.getBoundingClientRect();

            if (isPreviewVisible) {
                // State 1: At least 10% of Preview is visible (IntersectionObserver is true)
                mainIconElement.className = 'fas fa-cog';
                arrowIconElement.className = 'directional-arrow fas fa-arrow-up';
                    arrowIconElement.style.display = 'block';
                jumpButton.title = 'Scroll to Active Settings';
            } else {
                // Preview is less than 10% visible (IntersectionObserver is false)
                // Determine icon based on whether the TOP of the preview is above or below the viewport top.
                if (previewRect.top < 0) {
                    // State 3: Preview is NOT significantly visible, and its TOP is ABOVE the viewport.
                    // User has scrolled down past the beginning of the preview.
                    mainIconElement.className = 'fas fa-magnifying-glass';
                    arrowIconElement.className = 'directional-arrow fas fa-arrow-up';
                    arrowIconElement.style.display = 'block';
                    jumpButton.title = 'Scroll to Live Preview';
                } else {
                    // State 2: Preview is NOT significantly visible, and its TOP is WITHIN or BELOW the viewport.
                    // User is above or at the very start of the preview.
                    mainIconElement.className = 'fas fa-magnifying-glass';
                    arrowIconElement.className = 'directional-arrow fas fa-arrow-down';
                    arrowIconElement.style.display = 'block';
                    jumpButton.title = 'Scroll to Live Preview';
                }
            }
        } else { // Hide on desktop
            jumpButton.classList.remove('visible');
            // Proper handling for transitionend to set display: none
            const handleTransitionEnd = () => {
                if (!jumpButton.classList.contains('visible')) {
                    jumpButton.style.display = 'none';
                    if(arrowIconElement) arrowIconElement.style.display = 'none'; // Hide arrow too
                }
                jumpButton.removeEventListener('transitionend', handleTransitionEnd);
            };
            if (getComputedStyle(jumpButton).transitionProperty !== 'none' && getComputedStyle(jumpButton).transitionDuration !== '0s' && getComputedStyle(jumpButton).opacity !== '0') {
                 jumpButton.addEventListener('transitionend', handleTransitionEnd);
            } else {
                 // If no transition, hide immediately
                 if (!jumpButton.classList.contains('visible')) {
                    jumpButton.style.display = 'none';
                    if(arrowIconElement) arrowIconElement.style.display = 'none'; // Hide arrow too
                 }
            }
        }
    }

    jumpButton.addEventListener('click', () => {
        if (isPreviewVisible) {
            const activeSettings = getActiveSettingsElement();
            let targetScrollElement = null;

            if (activeSettings) {
                targetScrollElement = activeSettings;
            } else {
                // Fallback: No active tab, scroll to the top of the settings area
                targetScrollElement = document.querySelector('.shared-tabs-buttons-container'); // UPDATED SELECTOR
            }

            if (targetScrollElement) {
                let fixedHeaderHeight = 0;
                const adminBar = document.getElementById('wpadminbar');
                if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                    fixedHeaderHeight += adminBar.offsetHeight;
                }
                
                const elementPosition = targetScrollElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        } else {
            if (previewElement) {
                previewElement.scrollIntoView({ behavior: 'smooth' });
            }
        }
    });

    // Initial check and listen for resize
    window.addEventListener('resize', debounce(toggleButtonState, 150));
    // Listen for tab changes to potentially update button state if needed,
    // though viewport check should be primary driver.
    // Example: if a tab change might affect which element is "active settings" when preview is visible.
    // document.addEventListener('ExtrchLinkPageTabChanged', toggleButtonState); // REMOVED - Assuming such an event is dispatched
    // --- Note: 'ExtrchLinkPageTabChanged' is a custom event. If shared-tabs.js doesn't dispatch this,
    // this listener might not fire. However, shared-tabs.js *does* handle active states which should
    // make getActiveSettingsElement work correctly when called.
    // Consider dispatching a generic 'sharedTabChanged' event from shared-tabs.js if needed by other scripts.

    toggleButtonState(); // Initial check
};
// ----- End Jump to Preview Button Logic -----

ExtrchLinkPageManager.isInitialized = false;

// --- Main Initialization Function ---
ExtrchLinkPageManager.init = function() {
    if (this.isInitialized) {
        // console.log('ExtrchLinkPageManager already initialized.');
        return;
    }
    // console.log('ExtrchLinkPageManager main init function running...');

    // Initialize initialData and liveState here, safely after DOM and inline scripts have loaded
    this.initialData = window.extrchInitialLinkPageData || {};
    this.initialLinkSectionsData = window.bpLinkPageLinks || []; // Keep this if bpLinkPageLinks is separate
    this.liveState = {
        profileImgUrl: (this.initialData && this.initialData.profile_img_url) || '',
        bgImgUrl: (this.initialData && this.initialData.background_image_url) || ''
    };
    // Log the initial profile image URL that JS sees
    console.log('[ExtrchLinkPageManager] Initial profileImgUrl from liveState:', this.liveState.profileImgUrl);

    // IIFE-based modules (like customization, fonts, sizing, colors, background, previewUpdater, contentRenderer, uiUtils)
    // should have already run and attached their properties (e.g., ExtrchLinkPageManager.customization).
    // We now call their respective init methods if they exist.

    if (this.customization && typeof this.customization.init === 'function') {
        this.customization.init();
    } else if (this.customization && typeof this.customization.reapplyStyles === 'function') {
        this.customization.reapplyStyles(); // Fallback for initial style application
    }

    if (this.fonts && typeof this.fonts.init === 'function') { this.fonts.init(); }
    if (this.sizing && typeof this.sizing.init === 'function') { this.sizing.init(); }
    if (this.colors && typeof this.colors.init === 'function') { this.colors.init(); }
    if (this.background && typeof this.background.init === 'function') { this.background.init(); }
    if (this.previewUpdater && typeof this.previewUpdater.init === 'function') { this.previewUpdater.init(); }
    if (this.contentPreview && typeof this.contentPreview.init === 'function') { this.contentPreview.init(); }
    if (this.uiUtils && typeof this.uiUtils.init === 'function') { this.uiUtils.init(); }

    // Initialize modules that were assigned globally and then attached to the manager
    // These modules' init might expect the manager instance itself.
    if (this.info && typeof this.info.init === 'function') {
        this.info.init(this); // ExtrchLinkPageInfoManager.init(ExtrchLinkPageManager)
        if (this.ajaxConfig && this.ajaxConfig.initial_profile_img_url) {
            this.info.syncExternalImageUpdate(this.ajaxConfig.initial_profile_img_url);
        }
    }
    if (this.linkSections && typeof this.linkSections.init === 'function') {
        this.linkSections.init(this);
    }
    if (this.socialIcons && typeof this.socialIcons.init === 'function') {
        this.socialIcons.init(this);
    }
    if (this.advancedSettings && typeof this.advancedSettings.init === 'function') {
        this.advancedSettings.init(this);
    }
    if (this.analytics && typeof this.analytics.init === 'function') {
        this.analytics.init(this);
    }

    // Initialize other components like JumpToPreview
    this.initializeJumpToPreview();

    // Attach main form submission handler (mostly for image upload feedback)
    const form = document.getElementById('bp-manage-link-page-form');
    if (form) {
        // form.addEventListener('submit', this.handleFormSubmission.bind(this)); // this.handleFormSubmission is currently a stub

        const saveButton = form.querySelector('button[name="bp_save_link_page"]');
    const saveButtonWrapper = document.querySelector('.bp-link-page-save-btn-wrap');
    const profileImageInput = document.getElementById('link_page_profile_image_upload');
        const backgroundImageInput = document.getElementById('link_page_background_image_upload');

        if (saveButton && saveButtonWrapper) {
        form.addEventListener('submit', function(event) {
            let newProfileImageSelected = profileImageInput && profileImageInput.files && profileImageInput.files.length > 0;
            let newBackgroundImageSelected = backgroundImageInput && backgroundImageInput.files && backgroundImageInput.files.length > 0;

            if (newProfileImageSelected || newBackgroundImageSelected) {
                let feedbackDiv = saveButtonWrapper.querySelector('.bp-save-feedback');
                if (!feedbackDiv) {
                    feedbackDiv = document.createElement('div');
                    feedbackDiv.className = 'bp-save-feedback';
                    feedbackDiv.style.marginTop = '10px';
                    feedbackDiv.style.fontSize = '0.9em';
                    saveButtonWrapper.appendChild(feedbackDiv);
                }
                feedbackDiv.textContent = 'Saving, please wait... Image processing may take a moment.';
                }
            });
        }
    }
    
    // Debounced AJAX update setup (if any fields still use direct AJAX)
    const debouncedUpdatePreview = debounce(this.updatePreviewViaAJAX.bind(this), 300);
    const fieldsToWatchForAJAX = [ /* 'link_page_background_type' - if it still uses AJAX */ ];
    fieldsToWatchForAJAX.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            const eventType = (element.tagName === 'SELECT' || element.type === 'checkbox') ? 'change' : 'input';
            element.addEventListener(eventType, debouncedUpdatePreview);
        }
    });

    // Live preview ready message listener (if still using iframe postMessage for ready state)
    // This logic might be outdated if the preview is no longer in an iframe or uses a different ready signal.
    const livePreviewContainer = document.querySelector('.manage-link-page-preview-live');
    if (livePreviewContainer) {
        window.addEventListener('message', function (event) {
            // Optional: Check event.origin for security
            // if (event.origin !== 'expected_origin') return;
            if (event.data === 'extrchPreviewReady') {
                if (livePreviewContainer) {
                    livePreviewContainer.style.visibility = 'visible';
                    // console.log('extrchPreviewReady received, making preview visible.');
                }
            }
        });
    }

    this.isInitialized = true;
    // console.log('ExtrchLinkPageManager fully initialized.');

    document.dispatchEvent(new CustomEvent('ExtrchLinkPageManagerFullyInitialized', { detail: { manager: this } }));

    // --- START: Explicitly set initial profile image in preview AFTER all inits ---
    if (this.liveState && this.liveState.profileImgUrl) {
        const previewElement = this.getPreviewEl();
        if (previewElement && this.contentPreview && typeof this.contentPreview.updatePreviewProfileImage === 'function') {
            console.log('[ExtrchLinkPageManager] Attempting to explicitly set profile image in preview init:', this.liveState.profileImgUrl);
            this.contentPreview.updatePreviewProfileImage(this.liveState.profileImgUrl, previewElement);
        }
    }
    // --- END: Explicitly set initial profile image ---
};


// --- DOMContentLoaded ---
document.addEventListener('DOMContentLoaded', function() {
    // console.log('DOMContentLoaded event fired.');

    // Assign globally defined modules to the main manager object
    // These modules should have defined their global objects by the time DOMContentLoaded fires.
    if (typeof ExtrchLinkPageInfoManager !== 'undefined') {
        window.ExtrchLinkPageManager.info = ExtrchLinkPageInfoManager;
    } else {
        // console.warn('ExtrchLinkPageInfoManager is not defined on DOMContentLoaded.');
    }
    if (typeof ExtrchLinkPageLinksManager !== 'undefined') { 
        window.ExtrchLinkPageManager.linkSections = ExtrchLinkPageLinksManager;
    } else {
        // console.warn('ExtrchLinkPageLinksManager is not defined on DOMContentLoaded.');
    }
    if (typeof ExtrchLinkPageSocialsManager !== 'undefined') { 
        window.ExtrchLinkPageManager.socialIcons = ExtrchLinkPageSocialsManager;
    } else {
        // console.warn('ExtrchLinkPageSocialsManager is not defined on DOMContentLoaded.');
    }
    if (typeof ExtrchLinkPageAdvancedSettingsManager !== 'undefined') { 
        window.ExtrchLinkPageManager.advancedSettings = ExtrchLinkPageAdvancedSettingsManager;
    } else {
        // console.warn('ExtrchLinkPageAdvancedSettingsManager is not defined on DOMContentLoaded.');
    }
    if (typeof ExtrchLinkPageAnalyticsManager !== 'undefined') { 
        window.ExtrchLinkPageManager.analytics = ExtrchLinkPageAnalyticsManager;
    } else {
        // console.warn('ExtrchLinkPageAnalyticsManager is not defined on DOMContentLoaded.');
    }

    // IIFE modules (like customization, fonts, etc.) should have already run and attached 
    // their properties (e.g., ExtrchLinkPageManager.customization) to window.ExtrchLinkPageManager.

    // Now, call the main init function for ExtrchLinkPageManager
    if (typeof window.ExtrchLinkPageManager.init === 'function') {
        window.ExtrchLinkPageManager.init();
    } else {
        // console.error('ExtrchLinkPageManager.init is not defined when trying to call from DOMContentLoaded.');
    }

    // --- Form Validation Handler for Tabs ---
    const form = document.getElementById('bp-manage-link-page-form');
    if (form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                let firstInvalidElement = null;
                for (const element of form.elements) {
                    if (element.willValidate && !element.validity.valid) { // Check willValidate also
                        firstInvalidElement = element;
                        break;
                    }
                }

                if (firstInvalidElement) {
                    const tabPane = firstInvalidElement.closest('.shared-tab-pane');
                    
                    // Check if the tab pane is currently hidden (either directly or because its parent desktop area is hidden)
                    let isHidden = false;
                    if (tabPane) {
                        const paneStyles = window.getComputedStyle(tabPane);
                        if (paneStyles.display === 'none') {
                            isHidden = true;
                        } else {
                            // If in desktop mode, the pane might be in desktopContentArea
                            // and the *pane itself* might be display:block, but its content (if it's a wrapper) or itself might be hidden
                            // Check if its parent (the desktopContentArea or original accordion item) is visible.
                            const desktopContentArea = component.querySelector('.shared-desktop-tab-content-area');
                            const parentItem = tabPane.closest('.shared-tab-item'); // original accordion structure

                            if (tabPane.parentElement === desktopContentArea && window.getComputedStyle(desktopContentArea).display === 'none') {
                                isHidden = true; // Pane is in hidden desktop area
                            } else if (tabPane.parentElement !== desktopContentArea && parentItem && window.getComputedStyle(parentItem).display === 'none') {
                                isHidden = true; // Pane is in its original item, but that item is hidden (less likely with current tab logic)
                            }
                        }
                    }

                    if (tabPane && isHidden) {
                        event.preventDefault(); // Stop submission to show the error

                        const tabButton = document.querySelector(`.shared-tab-button[data-tab="${tabPane.id}"]`);

                        if (tabButton) {
                            tabButton.click(); // Activate the tab

                            requestAnimationFrame(() => {
                                firstInvalidElement.focus();
                                if (typeof firstInvalidElement.reportValidity === 'function') {
                                    firstInvalidElement.reportValidity();
                                }
                            });
                        }
                    } else if (firstInvalidElement && typeof firstInvalidElement.reportValidity === 'function'){
                        // If the element is already visible but form submission was perhaps prematurely stopped
                        // or if we want to ensure the message shows.
                        // We only preventDefault if we are switching tabs.
                        // If it's visible, let browser handle or explicitly call reportValidity IF we are stopping submission.
                        // This path is less likely to be hit if the above handles hidden cases.
                    }
                }
            } // else form is valid, let it submit
        });
    }
});

// Note: The individual submodule scripts (e.g., manage-link-page-customization.js, manage-link-page-info.js)
// must be enqueued and loaded BEFORE this main manage-link-page.js script for this to work correctly.
// Specifically, IIFE modules must run to populate parts of ExtrchLinkPageManager, and global object modules
// must define their objects before the DOMContentLoaded listener here attempts to assign them and call init.
