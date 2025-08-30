// Social Icons Management Module - Simplified Version
//
// Uses centralized data management with DOM-based UI updates
// Integrates with ExtraChillArtistPlatform_SocialLinks manager
(function(manager, config) {
    if (!manager) {
        console.error('[SocialIcons] ExtrchLinkPageManager not found.');
        return;
    }
    
    manager.socialIcons = manager.socialIcons || {};
    manager.socialIcons.allowPreviewUpdate = false;

    let socialsSortableInstance = null;
    const hiddenInputId = 'artist_profile_social_links_json';
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

    // Centralized URL validation
    function isValidUrl(url) {
        if (!url || typeof url !== 'string') return false;
        try {
            new URL(url.startsWith('http') ? url : 'https://' + url);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Extract social links data from centralized source
    function getSocialsDataFromDOM() {
        // Get socials from centralized data source
        if (manager.getSocials && typeof manager.getSocials === 'function') {
            return manager.getSocials() || [];
        }
        
        console.warn('[Socials] Centralized socials data not available - this should not happen');
        return [];
    }
    
    // Expose for external access
    manager.socialIcons.getSocialsDataFromDOM = getSocialsDataFromDOM;

    // Notify preview module of social icons changes
    function notifySocialsChanged() {
        if (!manager.isInitialized || !manager.socialIcons.allowPreviewUpdate) {
            return;
        }
        
        const socials = getSocialsDataFromDOM();
        const position = getSocialIconsPositionFromDOM();
        
        document.dispatchEvent(new CustomEvent('socialIconsChanged', {
            detail: { 
                socials: socials,
                position: position
            }
        }));
    }
    
    // Serialize social icons data for form submission
    function serializeSocialsForSave() {
        if (!hiddenInput) {
            console.warn('[SocialIcons] Hidden input not found for serialization');
            return false;
        }
        
        const currentData = getSocialsDataFromDOM();
        hiddenInput.value = JSON.stringify(currentData);
        console.log('[SocialIcons] Serialized', currentData.length, 'social icons for save');
        return true;
    }
    
    // Expose serialization method
    manager.socialIcons.serializeForSave = serializeSocialsForSave;

    // Remove tab change listener - no longer needed since we serialize at save time
    const debouncedSocialPreviewUpdate = debounce(notifySocialsChanged, 300);

    // Observer for the hidden input (primarily for debugging or external changes)
    let hiddenInputObserver;
    function observeHiddenInput() {
        if (!hiddenInput) return;
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
            console.warn('[SocialIcons] Essential DOM elements (list, add button, or hidden input) not found. Module will not function.');
            return;
        }

        observeHiddenInput(); // Start observing the hidden input
        
        // Enable preview updates after initialization
        manager.socialIcons.allowPreviewUpdate = true;
        console.log('[SocialIcons] Module initialized successfully, preview updates enabled');

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
            if (Sortable) {
                socialsSortableInstance = new Sortable(socialListEl, {
                    animation: 150,
                    handle: '.bp-social-drag-handle',
                    onEnd: function () {
                        if (isInitialSortableSocialsEnd) {
                            isInitialSortableSocialsEnd = false; // Consume the flag
                            // No hidden input update during initialization - wait for save time
                            return;
                        }
                        notifySocialsChanged(); // For actual user drags
                    }
                });
            }
        }

        // Only update preview on blur (for URL input) or change (for type select)
        socialListEl.addEventListener('blur', function(e) {
            if (e.target.classList.contains('bp-social-url-input')) {
                const url = e.target.value.trim();
                if (url) {
                    notifySocialsChanged();
                }
            }
        }, true); // Use capture to catch blur on children

        socialListEl.addEventListener('change', function(e) {
            if (e.target.classList.contains('bp-social-type-select')) {
                notifySocialsChanged();
            }
        });

        socialListEl.addEventListener('click', function(e) {
            if (e.target.classList.contains('bp-remove-social-btn') || e.target.closest('.bp-remove-social-btn')) {
                e.preventDefault();
                const row = e.target.closest('.bp-social-row');
                if (row) {
                    row.remove();
                    notifySocialsChanged();
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
                if (!firstAvailable && repeatableTypes.length) {
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
            radio.addEventListener('change', function() {
                // Emit position change event
                document.dispatchEvent(new CustomEvent('socialIconsPositionChanged', {
                    detail: { position: this.value }
                }));
                // Also notify of general socials change
                notifySocialsChanged();
            });
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

    // Self-initialize on DOMContentLoaded 
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof manager.socialIcons.init === 'function') {
            manager.socialIcons.init(config);
        }
    });

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}, window.extrchLinkPageConfig);