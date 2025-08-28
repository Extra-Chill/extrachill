// Manage Link Page - Featured Link (Advanced Tab Interactivity & Customize Tab Logic)
(function(manager) {
    if (!manager) {
        // console.warn('ExtrchLinkPageManager is not defined. Featured Link script cannot run.');
        return;
    }

    manager.featuredLink = manager.featuredLink || {};
    manager.featuredLink.isInitialized = false;

    // --- Cached DOM Elements (Advanced Tab) ---
    let enableFeaturedLinkCheckbox = null;
    let featuredLinkSelectContainer = null;
    let featuredLinkOriginalUrlSelect = null;

    // --- Cached DOM Elements (Customize Tab) ---
    let featuredLinkSettingsCard = null;
    let thumbnailUploadInput = null;
    let thumbnailPreviewImg = null; // This is for the Customize tab's own preview, not the live preview iframe
    let removeThumbnailButton = null; // Added for remove thumbnail functionality
    let customDescriptionTextarea = null;
    
    let currentAjaxRequest = null; // To store the current AJAX request

    function getLinkByUrl(linkUrl) {
        let foundLink = null;
        const sources = [];
        if (window.bpLinkPageLinks && Array.isArray(window.bpLinkPageLinks)) {
            sources.push(window.bpLinkPageLinks);
        } else if (manager && manager.links && typeof manager.links.getLinksData === 'function') {
            const linksDataFromManager = manager.links.getLinksData();
            if (Array.isArray(linksDataFromManager)) {
                sources.push(linksDataFromManager);
            }
        }

        for (const source of sources) {
            for (const section of source) {
                if (section && Array.isArray(section.links)) {
                    const normalizedTargetUrl = linkUrl.replace(/\/$/, '');
                    foundLink = section.links.find(link => 
                        (link.link_url && link.link_url.replace(/\/$/, '') === normalizedTargetUrl) || 
                        (link.url && link.url.replace(/\/$/, '') === normalizedTargetUrl)
                    );
                    if (foundLink) return foundLink;
                }
            }
        }
        return null;
    }

    function _getLinkPageId() {
        const linkPageIdInput = document.querySelector('input[name="link_page_id"]');
        return linkPageIdInput ? linkPageIdInput.value : null;
    }

    // --- Advanced Tab Logic ---
    function initAdvancedTabControls() {
        enableFeaturedLinkCheckbox = document.getElementById('bp-enable-featured-link');
        featuredLinkSelectContainer = document.getElementById('bp-featured-link-select-container');
        featuredLinkOriginalUrlSelect = document.getElementById('bp-featured-link-original-id');

        if (!enableFeaturedLinkCheckbox || !featuredLinkSelectContainer || !featuredLinkOriginalUrlSelect) {
            // console.warn('[FeaturedLink] Advanced tab controls not found.');
            return false;
        }

        enableFeaturedLinkCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            featuredLinkSelectContainer.style.display = isChecked ? 'block' : 'none';
            
            if (isChecked) {
                // If enabling, populate dropdown if empty, then attempt selection
                if (featuredLinkOriginalUrlSelect.options.length <= 1) {
                    populateLinksDropdown(true); // force repopulate when enabling
                }
                attemptInitialSelection(); // attempt to select, might be empty

                // If a link becomes selected (or was already selected and just became visible),
                // treat this as a user action to update the preview and fetch OG image.
                if (featuredLinkOriginalUrlSelect.value) {
                    handleFeaturedLinkSelectionChange(featuredLinkOriginalUrlSelect.value);
                } else {
                    // If no link is selected when enabling, still dispatch event to clear any existing highlights
                    document.dispatchEvent(new CustomEvent('featuredLinkOriginalUrlChanged', { detail: { newUrl: null } }));
                }
            } else { // Unchecked: clear everything related to featured link
                featuredLinkOriginalUrlSelect.value = '';
                if (customDescriptionTextarea) customDescriptionTextarea.value = '';
                if (thumbnailPreviewImg) {
                    thumbnailPreviewImg.src = '#';
                    thumbnailPreviewImg.style.display = 'none';
                }
                if (thumbnailUploadInput) thumbnailUploadInput.value = '';

                if (manager.contentPreview && typeof manager.contentPreview.updatePreviewFeaturedLink === 'function') {
                    manager.contentPreview.updatePreviewFeaturedLink(
                        { isActive: false }, // Signal to remove and clear skip URL in renderer
                        manager.getPreviewEl(), 
                        manager.getPreviewContentWrapperEl()
                    );
                }
                if (manager.links && typeof manager.links.updateLinksPreview === 'function') {
                    manager.links.updateLinksPreview(); 
                }
                // Dispatch event to clear highlight in links tab
                document.dispatchEvent(new CustomEvent('featuredLinkOriginalUrlChanged', { detail: { newUrl: null } }));
            }
            updateCustomizeCardVisibility();
        });
        
        featuredLinkOriginalUrlSelect.addEventListener('change', function() {
            updateCustomizeCardVisibility();
            // User explicitly changed the selection, so update everything and fetch thumbnail
            handleFeaturedLinkSelectionChange(this.value, true);
        });

        // On initial load, if PHP indicates feature is enabled & a link is selected:
        // populate the dropdown, ensure the correct item is selected, and make sure customize card visibility is correct.
        // DO NOT trigger preview updates or AJAX calls here; PHP handles initial render.
        if (enableFeaturedLinkCheckbox.checked) {
            populateLinksDropdown(); // Populate dropdown options
            attemptInitialSelection(); // Ensure correct <select> option is chosen based on PHP data
        }
        // Visibility of dependent elements based on initial PHP state
        featuredLinkSelectContainer.style.display = enableFeaturedLinkCheckbox.checked ? 'block' : 'none';
        updateCustomizeCardVisibility(); 

        return true;
    }

    function handleFeaturedLinkSelectionChange(selectedValue, isInitialSelection = false) {
        if (currentAjaxRequest) {
            currentAjaxRequest.abort();
        }

        // Always clear any previously uploaded custom image when changing the link
        if (thumbnailUploadInput) {
            thumbnailUploadInput.value = '';
        }
        if (thumbnailPreviewImg) {
            thumbnailPreviewImg.src = '#';
            thumbnailPreviewImg.style.display = 'none';
            delete thumbnailPreviewImg.dataset.lastUserUpload;
        }
        clearOgImageRemovedFlag();
        const hiddenThumbIdInput = document.getElementById('featured_link_thumbnail_id_action');
        if (hiddenThumbIdInput) hiddenThumbIdInput.value = 'remove';

        if (selectedValue) {
            const linkData = getLinkByUrl(selectedValue);
            let originalTitle = linkData ? (linkData.link_text || linkData.title || '') : '';

            // Update live preview (title from input, clear thumbnail for AJAX)
            triggerFeaturedLinkPreviewUpdate({
                title: originalTitle,
                // Always clear thumbnail so OG image is fetched for new link
                thumbnailUrl: ''
            });

            const nonce = window.extrchLinkPageConfig?.nonces?.featured_link_nonce;
            if (!nonce) {
                console.error('Featured link nonce not found.');
                triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: '' });
                return;
            }

            currentAjaxRequest = new XMLHttpRequest();
            currentAjaxRequest.open('POST', window.ajaxurl, true);
            currentAjaxRequest.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            currentAjaxRequest.onload = function() {
                if (currentAjaxRequest.status >= 200 && currentAjaxRequest.status < 400) {
                    try {
                        const response = JSON.parse(currentAjaxRequest.responseText);
                        if (response.success && response.data && typeof response.data.og_image_url !== 'undefined') {
                            triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: response.data.og_image_url });
                        } else {
                            triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: '' });
                        }
                    } catch (e) {
                        console.error('Error parsing OG image fetch response:', e);
                        triggerFeaturedLinkPreviewUpdate({ isActive: true, thumbnailUrl: '' });
                    }
                } else {
                    console.error('Error fetching OG image:', currentAjaxRequest.statusText);
                    triggerFeaturedLinkPreviewUpdate({ isActive: true, thumbnailUrl: '' });
                }
                currentAjaxRequest = null;
            };
            currentAjaxRequest.onerror = function() {
                console.error('Network error while fetching OG image.');
                triggerFeaturedLinkPreviewUpdate({ isActive: true, thumbnailUrl: '' });
                currentAjaxRequest = null;
            };
            currentAjaxRequest.send(
                'action=extrch_fetch_og_image_for_preview' +
                '&security=' + encodeURIComponent(nonce) +
                '&url_to_fetch=' + encodeURIComponent(selectedValue)
            );

            document.dispatchEvent(new CustomEvent('featuredLinkOriginalUrlChanged', { detail: { newUrl: selectedValue || null } }));
        } else {
            if (manager.contentPreview && typeof manager.contentPreview.updatePreviewFeaturedLink === 'function') {
                manager.contentPreview.updatePreviewFeaturedLink(
                    { isActive: false }, 
                    manager.getPreviewEl(), 
                    manager.getPreviewContentWrapperEl()
                );
            }
            if (manager.links && typeof manager.links.updateLinksPreview === 'function') {
                manager.links.updateLinksPreview();
            }
            document.dispatchEvent(new CustomEvent('featuredLinkOriginalUrlChanged', { detail: { newUrl: null } }));
        }
    }

    function attemptInitialSelection() {
        if (!featuredLinkOriginalUrlSelect) return;
        const initialSelectedUrl = String(featuredLinkOriginalUrlSelect.dataset.initialSelectedUrl || '').trim();
        // console.log('[FeaturedLink] attemptInitialSelection - Trying to select URL from data attribute:', initialSelectedUrl);
        
        if (initialSelectedUrl) {
            let optionToSelect = null;
            for (let i = 0; i < featuredLinkOriginalUrlSelect.options.length; i++) {
                if (featuredLinkOriginalUrlSelect.options[i].value === initialSelectedUrl) {
                    optionToSelect = featuredLinkOriginalUrlSelect.options[i];
                    break;
                }
            }

            if (optionToSelect) {
                featuredLinkOriginalUrlSelect.value = initialSelectedUrl;
                // console.log('[FeaturedLink] attemptInitialSelection - SUCCESS: Set dropdown to URL:', initialSelectedUrl);
            } else {
                // console.warn('[FeaturedLink] attemptInitialSelection - FAILED to set selection. initialSelectedUrl:', initialSelectedUrl, 'Matching option not found.');
            }
        } else {
             // console.log('[FeaturedLink] attemptInitialSelection - No initialSelectedUrl found in data attribute.');
        }
    }

    function populateLinksDropdown(forceRepopulate = false) {
        if (!featuredLinkOriginalUrlSelect) {
            // console.warn('[FeaturedLink] populateLinksDropdown: featuredLinkOriginalUrlSelect element not found.');
            return;
        }

        if (!forceRepopulate && featuredLinkOriginalUrlSelect.options.length > 1) {
            // attemptInitialSelection(); // Don't call here, it's called in init if needed
            return;
        }

        let linksData = [];
        if (window.bpLinkPageLinks && Array.isArray(window.bpLinkPageLinks)) {
            linksData = window.bpLinkPageLinks;
        } else if (manager && manager.links && typeof manager.links.getLinksData === 'function') {
            const linksDataFromManager = manager.links.getLinksData();
            if (Array.isArray(linksDataFromManager)) {
                linksData = linksDataFromManager;
            }
        }

        const currentSelectedValueBeforeRepopulation = featuredLinkOriginalUrlSelect.value;

        while (featuredLinkOriginalUrlSelect.options.length > 1) featuredLinkOriginalUrlSelect.remove(1);
        if (featuredLinkOriginalUrlSelect.options.length === 0 || featuredLinkOriginalUrlSelect.options[0].value !== "") {
            const placeholder = document.createElement('option');
            placeholder.value = "";
            placeholder.textContent = "-- Select a Link --";
            featuredLinkOriginalUrlSelect.insertBefore(placeholder, featuredLinkOriginalUrlSelect.firstChild);
        }

        if (!Array.isArray(linksData) || linksData.length === 0) {
            // console.warn('[FeaturedLink] No valid linksData to populate options.');
            if (featuredLinkOriginalUrlSelect.options.length > 1) {
                while (featuredLinkOriginalUrlSelect.options.length > 1) featuredLinkOriginalUrlSelect.remove(1);
                }
            return;
        }
        
        // console.log('[FeaturedLink] Populating options from linksData:', JSON.parse(JSON.stringify(linksData)));

        linksData.forEach(section => {
            if (section && Array.isArray(section.links)) {
                section.links.forEach(link => {
                    if (link && link.link_url && typeof link.link_text !== 'undefined') { 
                        const option = document.createElement('option');
                        option.value = link.link_url;
                        option.textContent = `${link.link_text} (${link.link_url})`;
                        featuredLinkOriginalUrlSelect.appendChild(option);
                    }
                });
            }
        });
        
        // Try to reselect the previously selected URL or the initial one
        const initialSelectedUrl = String(featuredLinkOriginalUrlSelect.dataset.initialSelectedUrl || '').trim();
        let optionToReselect = currentSelectedValueBeforeRepopulation || initialSelectedUrl;
        
        if (optionToReselect) {
            let foundOption = false;
            for (let i = 0; i < featuredLinkOriginalUrlSelect.options.length; i++) {
                if (featuredLinkOriginalUrlSelect.options[i].value === optionToReselect) {
                    featuredLinkOriginalUrlSelect.value = optionToReselect;
                    foundOption = true;
                    break;
                }
            }
            if (!foundOption) {
                 featuredLinkOriginalUrlSelect.value = ""; // Clear if previous/initial selection no longer exists
            }
        } else {
            featuredLinkOriginalUrlSelect.value = ""; // Ensure it's cleared if no prior value or initial value
        }
        
        updateCustomizeCardVisibility(); // Update card based on new selection state
    }
    
    document.addEventListener('bpLinkPageLinksRefreshed', function() {
        if (manager.featuredLink.isInitialized && enableFeaturedLinkCheckbox && enableFeaturedLinkCheckbox.checked && featuredLinkOriginalUrlSelect) {
            populateLinksDropdown(true);
            // After repopulating, if a value is selected, ensure its preview logic runs
            // BUT only if no custom thumbnail already exists and user hasn't explicitly removed the OG image
            if (featuredLinkOriginalUrlSelect.value) {
                // Check if there's a custom thumbnail ID set (indicates user uploaded thumbnail)
                // When value is "remove", it means there IS a custom thumbnail (PHP sets this when thumbnail exists)
                const hiddenThumbIdInput = document.getElementById('featured_link_thumbnail_id_action');
                const hasCustomThumbnail = hiddenThumbIdInput && hiddenThumbIdInput.value === 'remove';
                
                // Check if user has explicitly removed the OG image
                const ogImageRemovedInput = document.getElementById('featured_link_og_image_removed');
                const hasRemovedOgImage = ogImageRemovedInput && ogImageRemovedInput.value === '1';
                
                if (!hasCustomThumbnail && !hasRemovedOgImage) {
                    // Only fetch OG image if no custom thumbnail exists AND user hasn't removed the OG image
                    handleFeaturedLinkSelectionChange(featuredLinkOriginalUrlSelect.value);
                } else {
                    // Just update the preview with existing data without fetching new OG image
                    const linkData = getLinkByUrl(featuredLinkOriginalUrlSelect.value);
                    let originalTitle = linkData ? (linkData.link_text || linkData.title || '') : '';
                    triggerFeaturedLinkPreviewUpdate({
                        title: originalTitle
                        // Don't specify thumbnailUrl - let it keep whatever is currently displayed
                    });
                }
            }
        }
    });

    // --- Customize Tab Logic ---
    function initCustomizeTabControls() {
        featuredLinkSettingsCard = document.getElementById('featured-link-settings-card');
        thumbnailUploadInput = document.getElementById('featured_link_thumbnail_upload');
        thumbnailPreviewImg = document.getElementById('featured_link_thumbnail_preview_img'); 
        removeThumbnailButton = document.getElementById('remove_featured_link_thumbnail_btn');
        customDescriptionTextarea = document.getElementById('featured_link_custom_description');

        if (!featuredLinkSettingsCard || !thumbnailUploadInput || !customDescriptionTextarea) {
            return false;
        }

        thumbnailUploadInput.addEventListener('change', function(event) {
            clearOgImageRemovedFlag();
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Update live preview with user-uploaded image
                    triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: e.target.result });
                    // If you still want to show a preview in the customize tab itself:
                    if (thumbnailPreviewImg) {
                        thumbnailPreviewImg.src = e.target.result;
                        thumbnailPreviewImg.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            } else { 
                 // No file selected by user (e.g., they hit cancel in file dialog)
                 // We don't want to clear if there's an existing OG image or PHP-rendered one without explicit action.
                 // The "Remove Thumbnail" button will handle explicit clearing.
                 // So, do nothing here, or at most, revert to `dataset.initialSrc` if it was a user-upload attempt that was cancelled.
                 if (thumbnailPreviewImg && thumbnailPreviewImg.dataset.lastUserUpload) {
                    // This state means user had uploaded, then tried again and cancelled.
                    // Revert to previous state or OG. For now, let's do nothing unless we have a clear "remove" action.
                 } else if (thumbnailPreviewImg) {
                    const initialSrc = thumbnailPreviewImg.dataset.initialSrc;
                    if (initialSrc && initialSrc !== '#') {
                        thumbnailPreviewImg.src = initialSrc;
                        thumbnailPreviewImg.style.display = 'block';
                        // Potentially trigger preview update if this initialSrc is what should be shown
                        // triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: initialSrc });
                    } else {
                        // thumbnailPreviewImg.src = '#';
                        // thumbnailPreviewImg.style.display = 'none';
                        // triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: '' }); // This might clear an OG image
                    }
                 }
            }
        });

        if (removeThumbnailButton) {
            removeThumbnailButton.addEventListener('click', function(e) {
                e.preventDefault();
                if (thumbnailUploadInput) {
                    thumbnailUploadInput.value = ''; // Clear the file input
                }
                if (thumbnailPreviewImg) {
                    thumbnailPreviewImg.src = '#'; // Clear customize tab preview
                    thumbnailPreviewImg.style.display = 'none';
                    delete thumbnailPreviewImg.dataset.lastUserUpload;
                }
                // Signal that the thumbnail should be cleared (or revert to OG if one was fetched for current link)
                // Set a hidden input to tell PHP to remove custom attachment ID on save
                const hiddenThumbIdInput = document.getElementById('featured_link_thumbnail_id_action');
                if(hiddenThumbIdInput) hiddenThumbIdInput.value = 'remove';

                // If the OG image is being shown (no custom upload), set the OG image removed flag
                const ogImageRemovedInput = document.getElementById('featured_link_og_image_removed');
                if (ogImageRemovedInput) ogImageRemovedInput.value = '1';

                // Do NOT re-fetch OG image here. Just clear the preview.
                triggerFeaturedLinkPreviewUpdate({ thumbnailUrl: '' });

                // Hide the remove button after removal
                removeThumbnailButton.style.display = 'none';
            });
        }

        // Update the remove button visibility whenever the preview is updated
        function updateRemoveButtonVisibility() {
            if (!removeThumbnailButton) return;
            // Show if there is a thumbnail in the preview or saved
            const hasThumbnail = (thumbnailPreviewImg && thumbnailPreviewImg.src && thumbnailPreviewImg.src !== '#' && thumbnailPreviewImg.style.display !== 'none');
            removeThumbnailButton.style.display = hasThumbnail ? '' : 'none';
        }

        // Patch triggerFeaturedLinkPreviewUpdate to call updateRemoveButtonVisibility
        const originalTriggerFeaturedLinkPreviewUpdate = triggerFeaturedLinkPreviewUpdate;
        triggerFeaturedLinkPreviewUpdate = function(updatedPortion = {}) {
            originalTriggerFeaturedLinkPreviewUpdate(updatedPortion);
            updateRemoveButtonVisibility();
        };

        // Update title in preview in real time, surgical update
        const featuredLinkTitleInput = document.getElementById('featured_link_custom_title'); // If you ever add a title input
        if (featuredLinkTitleInput) {
            featuredLinkTitleInput.addEventListener('input', function() {
                const previewEl = manager.getPreviewEl && manager.getPreviewEl();
                if (!previewEl) return;
                const titleEl = previewEl.querySelector('.link-page-featured-link-section .featured-link-title');
                if (titleEl) titleEl.textContent = this.value;
            });
        }

        // Update description in preview in real time, surgical update
        customDescriptionTextarea.addEventListener('input', function() {
            const previewEl = manager.getPreviewEl && manager.getPreviewEl();
            if (!previewEl) return;
            const featuredSection = previewEl.querySelector('.link-page-featured-link-section');
            if (!featuredSection) return;
            const contentDiv = featuredSection.querySelector('.featured-link-content');
            if (!contentDiv) return;
            const titleRow = contentDiv.querySelector('.featured-link-title-row');
            let descEl = contentDiv.querySelector('.featured-link-description');
            const value = this.value;
            if (descEl && value === '') {
                descEl.remove(); // Remove if cleared
            } else if (!descEl && value !== '' && titleRow) {
                descEl = document.createElement('p');
                descEl.className = 'featured-link-description';
                descEl.textContent = value;
                // Insert after the entire .featured-link-title-row element
                if (titleRow.nextElementSibling) {
                    contentDiv.insertBefore(descEl, titleRow.nextElementSibling);
                } else {
                    contentDiv.appendChild(descEl);
                }
            } else if (descEl) {
                descEl.textContent = value;
            }
        });
        
        updateCustomizeCardVisibility(); 
        // Initial preview update if card is visible and link selected is handled by Advanced Tab's init or change events
        return true;
    }

    function updateCustomizeCardVisibility() {
        if (featuredLinkSettingsCard && enableFeaturedLinkCheckbox && featuredLinkOriginalUrlSelect) {
            const showCard = enableFeaturedLinkCheckbox.checked && featuredLinkOriginalUrlSelect.value !== '';
            featuredLinkSettingsCard.style.display = showCard ? 'block' : 'none';
        }
    }

    // Holds the latest data passed to trigger, to avoid race conditions with AJAX
    let latestPreviewDataState = {}; 

    function triggerFeaturedLinkPreviewUpdate(updatedPortion = {}) {
        if (!manager.contentPreview || typeof manager.contentPreview.updatePreviewFeaturedLink !== 'function') {
            return;
        }

        // Merge updatedPortion with the latest state
        latestPreviewDataState = { ...latestPreviewDataState, ...updatedPortion };

        if (!enableFeaturedLinkCheckbox || !enableFeaturedLinkCheckbox.checked || !featuredLinkOriginalUrlSelect || !featuredLinkOriginalUrlSelect.value) {
            // Feature is off or no link selected, clear everything
            if (manager.contentPreview.clearPreviewFeaturedLink) {
                 manager.contentPreview.clearPreviewFeaturedLink(manager.getPreviewEl());
             }
            if (manager.contentPreview.setFeaturedLinkUrlToSkipForPreview) {
                manager.contentPreview.setFeaturedLinkUrlToSkipForPreview(null);
            }
            if (manager.links && manager.links.updateLinksPreview) {
                manager.links.updateLinksPreview();
            }
            latestPreviewDataState = {}; // Reset state
            return;
        }

        const selectedLinkUrl = featuredLinkOriginalUrlSelect.value;
        const linkData = getLinkByUrl(selectedLinkUrl);

        if (!linkData) {
            if (manager.contentPreview.clearPreviewFeaturedLink) {
                 manager.contentPreview.clearPreviewFeaturedLink(manager.getPreviewEl());
            }
            latestPreviewDataState = {}; // Reset state
            return;
        }
        
        let shareItemId = linkData.id;
        if (!shareItemId && selectedLinkUrl) { // Ensure selectedLinkUrl is not empty before hashing
            shareItemId = selectedLinkUrl.split('').reduce((acc, char) => (acc * 31 + char.charCodeAt(0)) & 0xFFFFFFFF, 0).toString(16);
        }


        const dataForPreview = {
            originalLinkUrl: linkData.link_url || linkData.url, 
            originalLinkTitle: linkData.link_text || linkData.title, 
            originalLinkId: shareItemId,
            thumbnailUrl: latestPreviewDataState.thumbnailUrl, // Will be undefined if not explicitly set by upload/AJAX/updatedPortion
            title: latestPreviewDataState.title !== undefined ? latestPreviewDataState.title : 
                   (linkData.link_text || linkData.title),
            description: latestPreviewDataState.description !== undefined ? latestPreviewDataState.description : 
                         (customDescriptionTextarea ? customDescriptionTextarea.value : '')
        };
        
        // If it's initial load AND updatedPortion did NOT provide a thumbnailUrl,
        // and latestPreviewDataState.thumbnailUrl is also undefined,
        // it means we want to preserve what PHP might have rendered.
        // The `updatePreviewFeaturedLink` function will need to handle `thumbnailUrl: undefined`
        // on initial load as "do not touch existing thumbnail".
        if (manager.featuredLink.isPerformingInitialSetup && updatedPortion.thumbnailUrl === undefined && latestPreviewDataState.thumbnailUrl === undefined) {
            // Send a signal or ensure dataForPreview.thumbnailUrl remains undefined
            // so that the renderer knows not to overwrite a PHP-rendered image.
            // dataForPreview.thumbnailUrl will be undefined here, which is what we want to pass to the renderer.
            // For initial load, if PHP has rendered the thumbnail, dataForPreview.thumbnailUrl will be undefined.
            // The renderer must be smart enough: if undefined, do not touch existing thumbnail.
        }
        
        // Ensure that if thumbnail is explicitly set to empty string (e.g. by AJAX returning no image), it's passed as such.
        if (updatedPortion.thumbnailUrl === '') {
            dataForPreview.thumbnailUrl = '';
            latestPreviewDataState.thumbnailUrl = ''; // Persist this explicit clear
        }


        manager.contentPreview.updatePreviewFeaturedLink(dataForPreview, manager.getPreviewEl(), manager.getPreviewContentWrapperEl());

        if (manager.contentPreview.setFeaturedLinkUrlToSkipForPreview) {
            manager.contentPreview.setFeaturedLinkUrlToSkipForPreview(dataForPreview.originalLinkUrl);
        }

        if (manager.links && manager.links.updateLinksPreview) {
            manager.links.updateLinksPreview(); // This re-renders the main links list
        }
    }

    // --- Helper functions to update only title/description in preview ---
    function updateFeaturedLinkTitleInPreview(newTitle) {
        const previewEl = manager.getPreviewEl && manager.getPreviewEl();
        if (!previewEl) return;
        const titleEl = previewEl.querySelector('.link-page-featured-link-section .featured-link-title');
        if (titleEl) titleEl.textContent = newTitle;
    }

    function updateFeaturedLinkDescriptionInPreview(newDescription) {
        const previewEl = manager.getPreviewEl && manager.getPreviewEl();
        if (!previewEl) return;
        const descEl = previewEl.querySelector('.link-page-featured-link-section .featured-link-description');
        if (descEl) descEl.textContent = newDescription;
    }

    // Expose for external use (e.g., from manage-link-page-links.js)
    manager.featuredLink.triggerFeaturedLinkPreviewUpdate = triggerFeaturedLinkPreviewUpdate;

    manager.featuredLink.init = function() {
        if (manager.featuredLink.isInitialized) return;

        manager.featuredLink.isPerformingInitialSetup = true; // Flag for initial setup phase

        let advancedTabReady = false;
        let customizeTabReady = false;

        if (document.getElementById('bp-enable-featured-link')) {
            advancedTabReady = initAdvancedTabControls();
        }

        if (document.getElementById('featured_link_custom_description')) { 
             customizeTabReady = initCustomizeTabControls();
        }
        
        if(advancedTabReady && !customizeTabReady){
            const customizeTabObserver = new MutationObserver((mutationsList, observer) => {
                for(const mutation of mutationsList) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        const customizeTabContent = document.getElementById('customize-tab-content');
                        if (customizeTabContent && customizeTabContent.classList.contains('is-active-pane')) {
                            if (!manager.featuredLink.customizeTabInitialized) {
                                customizeTabReady = initCustomizeTabControls();
                                if (customizeTabReady) {
                                    manager.featuredLink.customizeTabInitialized = true;
                                    updateCustomizeCardVisibility();
                                    // Initial trigger if card visible and link selected is now handled by Advanced tab's init/change
                                    observer.disconnect();
                                }
                            }
                        }
                    }
                }
            });
            const mainTabsContainer = document.querySelector('.shared-tab-content-wrapper');
            if (mainTabsContainer) {
                customizeTabObserver.observe(mainTabsContainer, { attributes: true, subtree: true, attributeFilter: ['class'] });
            }
        }

        manager.featuredLink.isInitialized = true;
        manager.featuredLink.customizeTabInitialized = customizeTabReady; // Track if customize tab elements were found and initialized

        if (advancedTabReady && enableFeaturedLinkCheckbox) {
            featuredLinkSelectContainer.style.display = enableFeaturedLinkCheckbox.checked ? 'block' : 'none';
            // PATCH: Do NOT call handleFeaturedLinkSelectionChange or triggerFeaturedLinkPreviewUpdate on initial load.
            // Only set up the dropdown and visibility. Let PHP-rendered featured link remain untouched.
            // if (enableFeaturedLinkCheckbox.checked) {
            //     populateLinksDropdown();
            //     if (featuredLinkOriginalUrlSelect && featuredLinkOriginalUrlSelect.value) {
            //         handleFeaturedLinkSelectionChange(featuredLinkOriginalUrlSelect.value, true); // Pass true for isInitialLoad
            //     }
            // }
            if (enableFeaturedLinkCheckbox.checked) {
                populateLinksDropdown();
            }
        }
        
        if (customizeTabReady) {
            updateCustomizeCardVisibility();
        }
        
        manager.featuredLink.isPerformingInitialSetup = false; // End of initial setup phase
        manager.featuredLink.isInitialized = true;
        manager.featuredLink.customizeTabInitialized = customizeTabReady; 
    };

    // When a new link is selected or a new image is uploaded, clear the OG image removed flag
    function clearOgImageRemovedFlag() {
        const ogImageRemovedInput = document.getElementById('featured_link_og_image_removed');
        if (ogImageRemovedInput) ogImageRemovedInput.value = '';
    }

    manager.featuredLink.getCurrentFeaturedUrlToSkip = function() {
        const select = document.getElementById('bp-featured-link-original-id');
        return select && select.value ? select.value.replace(/\/$/, '') : null;
    };

})(window.ExtrchLinkPageManager); 

document.addEventListener('DOMContentLoaded', function() {
    if (window.ExtrchLinkPageManager && typeof window.ExtrchLinkPageManager.featuredLink.init === 'function') {
        window.ExtrchLinkPageManager.featuredLink.init();
    }
}); 