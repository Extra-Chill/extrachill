// Social Icons Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Social icons script cannot run.');
        return;
    }
    manager.socialIcons = manager.socialIcons || {};

    let socialsSortableInstance = null; // To store the Sortable instance

    manager.socialIcons.init = function() {
        const initialSocials = manager.initialData?.social_links ? JSON.parse(JSON.stringify(manager.initialData.social_links)) : [];
        let socials = initialSocials;
        
        const socialListEl = document.getElementById('bp-social-icons-list');
        const socialInputEl = document.getElementById('band_profile_social_links_json');
        const addSocialBtn = document.getElementById('bp-add-social-icon-btn'); // Get button reference early

        // Master list of all possible social types
        const allSocialTypes = [
            { value: 'instagram', label: 'Instagram' },
            { value: 'twitter', label: 'Twitter' },
            { value: 'facebook', label: 'Facebook' },
            { value: 'youtube', label: 'YouTube' },
            { value: 'tiktok', label: 'TikTok' },
            { value: 'soundcloud', label: 'SoundCloud' },
            { value: 'bandcamp', label: 'Bandcamp' },
            { value: 'spotify', label: 'Spotify' }, 
            { value: 'applemusic', label: 'Apple Music' }, 
            { value: 'website', label: 'Website' }, // 'website' can be added multiple times
            { value: 'email', label: 'Email' },     // 'email' can be added multiple times
            // Add other relevant types here
        ];
        const uniqueTypes = allSocialTypes.filter(type => type.value !== 'website' && type.value !== 'email');
        const repeatableTypes = allSocialTypes.filter(type => type.value === 'website' || type.value === 'email');

        let isInitialSocialRender = true;

        function initializeSortableForSocials() {
            if (socialsSortableInstance) {
                socialsSortableInstance.destroy();
                socialsSortableInstance = null;
            }
            if (socialListEl && typeof Sortable !== 'undefined') {
                socialsSortableInstance = new Sortable(socialListEl, {
                    animation: 150,
                    handle: '.bp-social-drag-handle',
                    onEnd: function (evt) {
                        if (evt.oldIndex === evt.newIndex) return;
                        const item = socials.splice(evt.oldIndex, 1)[0];
                        socials.splice(evt.newIndex, 0, item);
                        renderSocials(); 
                    }
                });
            } else if (typeof Sortable === 'undefined') {
                if (!isInitialSocialRender) console.warn('[SocialIcons] SortableJS is not loaded. Drag and drop reordering for social icons will not be available.');
            }
        }

        function renderSocials() {
            if (!socialListEl) return;

            if (socialsSortableInstance) {
                socialsSortableInstance.destroy();
                socialsSortableInstance = null;
            }

            socialListEl.innerHTML = '';
            const currentlyUsedUniqueTypes = socials.filter(s => s.type !== 'website' && s.type !== 'email').map(s => s.type);

            socials.forEach((social, idx) => {
                const row = document.createElement('div');
                row.className = 'bp-social-row'; 
                row.setAttribute('data-idx', idx.toString());

                const currentTypeForThisRow = social.type;
                let optionsHtml = '';

                if (currentTypeForThisRow === 'website' || currentTypeForThisRow === 'email') {
                    // For repeatable types, always show all repeatable types + any unique types not otherwise used
                     const availableUniqueOptions = uniqueTypes.filter(opt => !currentlyUsedUniqueTypes.includes(opt.value));
                     const combinedOptions = [...repeatableTypes, ...availableUniqueOptions];
                     optionsHtml = combinedOptions.map(opt => {
                        return `<option value="${opt.value}"${currentTypeForThisRow === opt.value ? ' selected' : ''}>${opt.label}</option>`;
                    }).join('');
                } else {
                    // For unique types, show itself + any other unique type not used by *another* row + all repeatable types
                    optionsHtml = allSocialTypes.map(opt => {
                        const isCurrentlySelectedByThisRow = opt.value === currentTypeForThisRow;
                        // Is this option type (if unique) used by any OTHER row?
                        const isUsedByAnotherRow = (opt.value !== 'website' && opt.value !== 'email') && socials.some((s, i) => i !== idx && s.type === opt.value);

                        if (isCurrentlySelectedByThisRow || !isUsedByAnotherRow) {
                            return `<option value="${opt.value}"${isCurrentlySelectedByThisRow ? ' selected' : ''}>${opt.label}</option>`;
                        }
                        return ''; 
                    }).join('');
                }

                let inputType = (social.type === 'email') ? 'email' : 'url';
                let inputPlaceholder = (social.type === 'email') ? 'Email Address' : 'Profile URL';

                row.innerHTML = `
                    <span class="bp-social-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <select class="bp-social-type-select">${optionsHtml}</select>
                    <input type="${inputType}" class="bp-social-url-input" placeholder="${inputPlaceholder}" value="${social.url || ''}">
                    <a href="#" class="bp-remove-social-btn bp-remove-item-link ml-auto" title="Remove Social Icon">&times;</a>
                `;

                const typeSelect = row.querySelector('.bp-social-type-select');
                if (typeSelect) {
                    typeSelect.addEventListener('change', function() { updateSocialProp(idx, 'type', this.value); });
                }
                const urlInput = row.querySelector('.bp-social-url-input');
                if (urlInput) {
                    urlInput.addEventListener('input', function() { updateSocialProp(idx, 'url', this.value); });
                }
                
                const removeBtn = row.querySelector('.bp-remove-social-btn');
                if(removeBtn) {
                    removeBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        removeSocial(idx);
                    });
                }
                
                socialListEl.appendChild(row);
            });
            updateSocialInput();
            initializeSortableForSocials(); 
            
            // Enable/disable Add button
            if (addSocialBtn) {
                const nonRepeatableSocialTypes = allSocialTypes.filter(type => type.value !== 'website' && type.value !== 'email');
                const usedNonRepeatableTypesCount = socials.filter(s => s.type !== 'website' && s.type !== 'email').map(s => s.type).length;
                
                if (usedNonRepeatableTypesCount >= nonRepeatableSocialTypes.length) {
                    // Check if 'website' or 'email' is the only option left for adding
                    const firstAvailableTypeToAdd = getFirstAvailableType();
                    if (!firstAvailableTypeToAdd || (firstAvailableTypeToAdd.value !== 'website' && firstAvailableTypeToAdd.value !== 'email' && usedNonRepeatableTypesCount >= nonRepeatableSocialTypes.length ) ){
                        addSocialBtn.disabled = true;
                    } else {
                         addSocialBtn.disabled = false;
                    }
                } else {
                    addSocialBtn.disabled = false;
                }
            }

            if (isInitialSocialRender) {
                isInitialSocialRender = false;
            }
        }

        function getFirstAvailableType() {
            const currentUsedTypes = socials.map(s => s.type);
            // Prioritize unique types
            let firstAvailable = uniqueTypes.find(st => !currentUsedTypes.includes(st.value));
            if (firstAvailable) {
                return firstAvailable;
            }
            // If all unique types are used, allow adding repeatable types
            return repeatableTypes.length > 0 ? repeatableTypes[0] : null; // Default to website or email if available
        }

        function updateSocialInput() {
            if (socialInputEl) {
                socialInputEl.value = JSON.stringify(socials);
                const event = new Event('input', { bubbles: true, cancelable: true });
                socialInputEl.dispatchEvent(event);
            }

            if (manager.contentPreview && typeof manager.contentPreview.renderSocials === 'function') {
                let previewElInsideIframe = null;
                if (typeof manager.getPreviewEl === 'function') {
                    previewElInsideIframe = manager.getPreviewEl(); 
                } else {
                    if (!isInitialSocialRender) console.warn('[SocialsBrain] manager.getPreviewEl is not available.');
                }

                let contentWrapperEl = null;
                if (previewElInsideIframe) {
                    contentWrapperEl = previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper');
                } else {
                    if (!isInitialSocialRender) console.warn('[SocialsBrain] previewElInsideIframe is null, cannot find contentWrapperEl.');
                }

                if (previewElInsideIframe && contentWrapperEl) {
                    manager.contentPreview.renderSocials(socials, previewElInsideIframe, contentWrapperEl);
                } else {
                    if (!isInitialSocialRender) { 
                        console.warn('[SocialsBrain] Preview container or content wrapper not found for live update. социальных сетей');
                    }
                }
            } else {
                if (!isInitialSocialRender) { 
                    console.warn('[SocialsBrain] Content Preview Renderer (renderSocials) not available for live update.');
                }
            }
        }

        function updateSocialProp(idx, prop, value) {
            if (socials[idx]) socials[idx][prop] = value;
            // If type changes, we need to re-render to update all dropdowns and potentially the add button state
            renderSocials(); 
        }
        function removeSocial(idx) {
            socials.splice(idx,1);
            renderSocials(); 
        }

        if (addSocialBtn) {
            addSocialBtn.addEventListener('click', function() {
                if (!Array.isArray(socials)) {
                    socials = [];
                }
                const firstAvailable = getFirstAvailableType();

                if (firstAvailable) {
                    socials.push({type: firstAvailable.value, url:''});
                    renderSocials();
                } else {
                    // This case should ideally be prevented by disabling the button
                    console.warn('[SocialIcons] No available social types to add.');
                }
            });
        } else {
            console.warn('[SocialIcons] Add Social Icon button (bp-add-social-icon-btn) not found.');
        }
        
        if (socialListEl && socialInputEl) {
            renderSocials(); 
        } else {
            console.error("Social icons list or JSON input element not found. Cannot initialize social icons UI.");
        }
    };

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); // Ensure manager is defined