// Social Icons Management Module
//
// CANONICAL FLOW: The DOM is the single source of truth for social icons.
// - Do NOT update the hidden input on every UI change.
// - Only serialize the DOM to the hidden input when explicitly called (by the save handler before submit).
// - The live preview can be updated on UI change, but should read directly from the DOM.
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Social icons script cannot run.');
        return;
    }
    manager.socialIcons = manager.socialIcons || {};

    let socialsSortableInstance = null;
    let isInitialSocialRender = true;

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
        console.log('[SocialIcons] getSocialsDataFromDOM called.');
        const socialListEl = document.getElementById('bp-social-icons-list');
        console.log('[SocialIcons] socialListEl:', socialListEl);
        const rows = socialListEl ? socialListEl.querySelectorAll('.bp-social-row') : [];
        console.log('[SocialIcons] Number of .bp-social-row elements found:', rows.length);
        const socials = [];
        rows.forEach((row, index) => {
            const typeSelect = row.querySelector('.bp-social-type-select');
            const urlInput = row.querySelector('.bp-social-url-input');
            const type = typeSelect ? typeSelect.value : '';
            const url = urlInput ? urlInput.value.trim() : '';
            console.log('[SocialIcons] Reading row ', index, ': Type=', type, ', URL=', url);
            if (type && url) {
                socials.push({ type, url });
            }
        });
        console.log('[SocialIcons] Final socials data from DOM:', socials);
        return socials;
    }
    manager.socialIcons.getSocialsDataFromDOM = getSocialsDataFromDOM;

    // --- New function to update the hidden input ---
    function updateSocialsHiddenInput() {
        const socialInputEl = document.getElementById('band_profile_social_links_json');
        if (!socialInputEl) {
            console.error('[SocialIcons] Hidden input #band_profile_social_links_json not found.');
            return;
        }
        const socialsData = getSocialsDataFromDOM();
        socialInputEl.value = JSON.stringify(socialsData);
        console.log('[SocialIcons] Hidden input value updated:', socialInputEl.value);
    }
    // --- End New function ---

    // Live preview update logic (reads directly from DOM)
    function updateSocialsPreview() {
        if (manager.contentPreview && typeof manager.contentPreview.renderSocials === 'function') {
            let previewElInsideIframe = manager.getPreviewEl ? manager.getPreviewEl() : null;
            let contentWrapperEl = previewElInsideIframe ? previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper') : null;
            if (previewElInsideIframe && contentWrapperEl) {
                const socials = getSocialsDataFromDOM();
                manager.contentPreview.renderSocials(socials, previewElInsideIframe, contentWrapperEl);
                // --- Call the new function to update the hidden input after preview updates ---
                updateSocialsHiddenInput();
                // --- End Call ---
            }
        }
    }
    const debouncedSocialPreviewUpdate = debounce(updateSocialsPreview, 300);

    manager.socialIcons.init = function(configData) {
        console.log('[SocialIcons] init called with configData:', configData);
        const socialListEl = document.getElementById('bp-social-icons-list');
        const socialInputEl = document.getElementById('band_profile_social_links_json');
        const addSocialBtn = document.getElementById('bp-add-social-icon-btn');
        if (!socialListEl || !socialInputEl) {
            console.error("Social icons list or JSON input element not found. Cannot initialize social icons UI.");
            return;
        }

        // --- MutationObserver for Debugging ---
        const observer = new MutationObserver(function(mutationsList) {
            for(const mutation of mutationsList) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                    console.log('[SocialIcons - Observer] Hidden input value changed!', 'Old value:', mutation.oldValue, 'New value:', socialInputEl.value);
                } else if (mutation.type === 'childList') {
                     console.log('[SocialIcons - Observer] Hidden input child list changed. This is unexpected.', mutation);
                } else if (mutation.type === 'attributes') {
                     console.log('[SocialIcons - Observer] Hidden input attribute changed (' + mutation.attributeName + ').', mutation);
                }
            }
        });

        // Start observing the target node for configured mutations
        observer.observe(socialInputEl, { attributes: true, childList: true, subtree: false, attributeOldValue: true });
        console.log('[SocialIcons - Observer] Started observing hidden input #band_profile_social_links_json.');
        // --- End MutationObserver ---


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
                        updateSocialsPreview();
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
                    console.warn('[SocialIcons] Could not find .bp-social-row to remove.', e.target);
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
                    initializeSortableForSocials();
                    // No preview update here; will happen on blur/change
                } else {
                    console.warn('[SocialIcons] No available social types to add.');
                }
            });
        }

        initializeSortableForSocials(); // Only needed once on init

        // --- Initial hydration/preview update on page load ---
        // This ensures the preview and hidden input reflect the PHP-rendered state
        // on initial load, aligning with the canonical architecture.
        updateSocialsHiddenInput(); // Directly update the hidden input on init as well
        updateSocialsPreview(); // Also update preview
        // --- End initial update ---
    };

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});