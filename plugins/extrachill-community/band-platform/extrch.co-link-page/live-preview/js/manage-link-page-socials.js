// Social Icons Management Module
//
// CANONICAL FLOW: The DOM is the single source of truth for social icons.
// - Do NOT update the hidden input on every UI change.
// - Only serialize the DOM to the hidden input when explicitly called (by the save handler before submit).
// - The live preview can be updated on UI change, but should read directly from the DOM.
(function(manager, config) {
    if (!manager) {
        // console.error('[SocialIcons] ExtrchLinkPageManager not found.'); // Keep
        return;
    }
    manager.socialIcons = manager.socialIcons || {};
    manager.socialIcons.allowPreviewUpdate = false; // Initialize the flag

    let socialsSortableInstance = null;
    let isInitialSocialRender = true;
    let isInitialSortableSocialsEnd = true; // Flag for Sortable's first onEnd

    const hiddenInputId = 'band_profile_social_links_json';
    let socialListEl, addSocialBtn, hiddenInput, supportedTypes = {};
    let socialIconsPositionRadios = [];

    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    // Simple URL validation
    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Reads the DOM and returns the current socials array
    function getSocialsDataFromDOM() {
        if (!socialListEl) {
            // console.warn('[SocialIcons] Social list element not found in DOM for getSocialsDataFromDOM.'); // Comment out
            return [];
        }
        const rows = socialListEl.querySelectorAll('.bp-social-row');
        // console.log(`[SocialIcons] Number of .bp-social-row elements found: ${rows.length}`); // Comment out
        const data = [];
        rows.forEach((row, index) => {
            const typeSelect = row.querySelector('.bp-social-type-select');
            const urlInput = row.querySelector('.bp-social-url-input');
            if (typeSelect && urlInput && typeSelect.value) {
                // console.log(`[SocialIcons] Reading row  ${index}: Type= ${typeSelect.value}, URL= ${urlInput.value}`); // Comment out
                data.push({ type: typeSelect.value, url: urlInput.value });
            }
        });
        // console.log('[SocialIcons] Final socials data from DOM:', data); // Comment out
        return data;
    }
    manager.socialIcons.getSocialsDataFromDOM = getSocialsDataFromDOM;

    // --- New function to update the hidden input ---
    function updateSocialsHiddenInput() {
        if (!hiddenInput) {
            // console.warn('[SocialIcons] Hidden input not found for updating.'); // Comment out
            return;
        }
        const currentData = getSocialsDataFromDOM();
        const jsonValue = JSON.stringify(currentData);
        if (hiddenInput.value !== jsonValue) {
            hiddenInput.value = jsonValue;
            // console.log('[SocialIcons] Hidden input value updated:', jsonValue); // Comment out
        }
    }
    // --- End New function ---

    // Live preview update logic (reads directly from DOM)
    function updateSocialsPreview() {
        if (!manager.isInitialized || !manager.socialIcons.allowPreviewUpdate) { // Check the new flag
            // console.log('[SocialIcons] Manager not initialized or preview update not allowed, skipping preview update.');
            return;
        }
        if (manager.contentPreview && typeof manager.contentPreview.renderSocials === 'function') {
            let previewElInsideIframe = manager.getPreviewEl ? manager.getPreviewEl() : null;
            let contentWrapperEl = previewElInsideIframe ? previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper') : null;
            if (previewElInsideIframe && contentWrapperEl) {
                const socials = getSocialsDataFromDOM();
                const position = getSocialIconsPositionFromDOM(); // Get current position from radio buttons
                manager.contentPreview.renderSocials(socials, previewElInsideIframe, contentWrapperEl, position);
                // --- Call the new function to update the hidden input after preview updates ---
                updateSocialsHiddenInput(); // This updates the main social links JSON, not the position
                // --- End Call ---
            }
        }
    }
    const debouncedSocialPreviewUpdate = debounce(updateSocialsPreview, 300);

    // Observer for the hidden input (primarily for debugging or external changes)
    let hiddenInputObserver;
    function observeHiddenInput() {
        if (!hiddenInput || typeof MutationObserver === 'undefined') return;
        if (hiddenInputObserver) hiddenInputObserver.disconnect(); // Disconnect previous if any

        let previousValue = hiddenInput.value;
        hiddenInputObserver = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                    const newValue = hiddenInput.value;
                    if (newValue !== previousValue) {
                        // console.log(`[SocialIcons - Observer] Hidden input value changed! Old value: ${previousValue} New value: ${newValue}`); // Comment out
                        previousValue = newValue;
                        // Potentially trigger a preview update if the change wasn't from this module
                    }
                }
            });
        });
        hiddenInputObserver.observe(hiddenInput, { attributes: true });
        // console.log(`[SocialIcons - Observer] Started observing hidden input #${hiddenInputId}.`); // Comment out
    }

    function initModule(configData) {
        // console.log('[SocialIcons] init called with configData:', configData); // Comment out
        supportedTypes = (configData && configData.social_types) ? configData.social_types : {};
        socialListEl = document.getElementById('bp-social-icons-list');
        addSocialBtn = document.getElementById('bp-add-social-icon-btn');
        hiddenInput = document.getElementById(hiddenInputId);
        socialIconsPositionRadios = document.querySelectorAll('input[name="link_page_social_icons_position"]');

        if (!socialListEl || !addSocialBtn || !hiddenInput) {
            // console.warn('[SocialIcons] Essential DOM elements (list, add button, or hidden input) not found. Module will not function.'); // Keep
            return;
        }

        observeHiddenInput(); // Start observing the hidden input
        // updateSocialsHiddenInput(); // Initial sync of hidden input from DOM state

        // Supported social types from configData
        const allSocialTypes = configData?.supportedLinkTypes || {};
        const socialTypesArray = Object.keys(allSocialTypes).map(key => ({
            value: key,
            label: allSocialTypes[key].label,
            icon: allSocialTypes[key].icon
        }));
        const uniqueTypes = socialTypesArray.filter(type => type.value !== 'website' && type.value !== 'email').map(type => type);
        const repeatableTypes = socialTypesArray.filter(type => type.value === 'website' || type.value === 'email').map(type => type);

        isInitialSocialRender = true;
        isInitialSortableSocialsEnd = true; // Reset flag on each init

        function initializeSortableForSocials() {
            if (socialsSortableInstance) {
                socialsSortableInstance.destroy();
                socialsSortableInstance = null;
            }
            if (typeof Sortable !== 'undefined') {
                socialsSortableInstance = new Sortable(socialListEl, {
                    animation: 150,
                    handle: '.bp-social-drag-handle',
                    onEnd: function () {
                        if (isInitialSortableSocialsEnd) {
                            isInitialSortableSocialsEnd = false; // Consume the flag
                            updateSocialsHiddenInput(); // Sync hidden input without triggering full preview update
                            return;
                        }
                        updateSocialsPreview(); // For actual user drags
                    }
                });
            }
        }

        // Only update preview on blur (for URL input) or change (for type select)
        socialListEl.addEventListener('blur', function(e) {
            if (e.target.classList.contains('bp-social-url-input')) {
                const url = e.target.value.trim();
                if (url) {
                    updateSocialsPreview();
                }
            }
        }, true); // Use capture to catch blur on children

        socialListEl.addEventListener('change', function(e) {
            if (e.target.classList.contains('bp-social-type-select')) {
                updateSocialsPreview();
            }
        });

        socialListEl.addEventListener('click', function(e) {
            if (e.target.classList.contains('bp-remove-social-btn') || e.target.closest('.bp-remove-social-btn')) {
                e.preventDefault();
                const row = e.target.closest('.bp-social-row');
                if (row) {
                    row.remove();
                    updateSocialsPreview();
                } else {
                    // console.warn('[SocialIcons] Could not find .bp-social-row to remove.', e.target); // Comment out
                }
            }
        });
        if (addSocialBtn) {
            addSocialBtn.addEventListener('click', function() {
                // Add a new row with the first available type
                const currentSocials = getSocialsDataFromDOM();
                const currentlyUsedUniqueTypes = currentSocials.filter(s => s.type !== 'website' && s.type !== 'email').map(s => s.type);
                let firstAvailable = uniqueTypes.find(st => !currentlyUsedUniqueTypes.includes(st.value));
                if (!firstAvailable && repeatableTypes.length > 0) {
                    firstAvailable = repeatableTypes[0];
                }
                if (firstAvailable) {
                    const row = document.createElement('div');
                    row.className = 'bp-social-row';
                    row.setAttribute('data-idx', currentSocials.length.toString());
                    let optionsHtml = socialTypesArray.map(opt => {
                        const isCurrentlySelectedByThisRow = opt.value === firstAvailable.value;
                        const isUsedByAnotherRow = (opt.value !== 'website' && opt.value !== 'email') && currentSocials.some(s => s.type === opt.value);
                        if (isCurrentlySelectedByThisRow || !isUsedByAnotherRow) {
                            return `<option value="${opt.value}"${isCurrentlySelectedByThisRow ? ' selected' : ''}>${opt.label}</option>`;
                        }
                        return '';
                    }).join('');
                    row.innerHTML = `
                        <span class="bp-social-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <select class="bp-social-type-select">${optionsHtml}</select>
                        <input type="url" class="bp-social-url-input" placeholder="Profile URL" value="">
                        <a href="#" class="bp-remove-social-btn bp-remove-item-link ml-auto" title="Remove Social Icon">&times;</a>
                    `;
                    socialListEl.appendChild(row);
                    // initializeSortableForSocials(); // This call was problematic here, should only be called once in initModule
                    // No preview update here; will happen on blur/change
                } else {
                    // console.warn('[SocialIcons] No available social types to add.'); // Comment out
                }
            });
        }

        initializeSortableForSocials(); // Only needed once on init

        socialIconsPositionRadios.forEach(radio => {
            radio.addEventListener('change', updateSocialsPreview);
        });

        // --- Initial hydration/preview update on page load ---
        // This ensures the preview and hidden input reflect the PHP-rendered state
        // on initial load, aligning with the canonical architecture.
        // updateSocialsHiddenInput(); // Directly update the hidden input on init as well to match controls for social links data
        // updateSocialsPreview(); // REMOVED - PHP handles initial preview render. JS only updates on user interaction.
        // --- End initial update ---

        // Make sure attachEventListeners, addSocialRow, populateTypeSelect are defined and called
        // attachEventListeners(); // This line is causing a ReferenceError and seems redundant
    }

    // Ensure this is exposed correctly for the main manager
    manager.socialIcons.init = initModule;

    // Helper to get the currently selected social icons position from the radio buttons
    function getSocialIconsPositionFromDOM() {
        const checkedRadio = document.querySelector('input[name="link_page_social_icons_position"]:checked');
        return checkedRadio ? checkedRadio.value : 'above'; // Default to 'above' if nothing is checked (shouldn't happen with defaults)
    }

    // ... (other functions like addSocialRow, populateTypeSelect, attachEventListeners) ...

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}, window.extrchLinkPageConfig);