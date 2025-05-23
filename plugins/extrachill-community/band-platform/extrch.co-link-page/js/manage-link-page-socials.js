// Social Icons Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Social icons script cannot run.');
        return;
    }
    manager.socialIcons = manager.socialIcons || {};

    let socialsSortableInstance = null; // To store the Sortable instance
    let isInitialSocialRender = true; // Moved up for wider scope if needed by debounce

    // Debounce function (copied from main manager if not globally available, or ensure it is)
    // For safety, defining it here if not guaranteed from elsewhere.
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    const debouncedSocialPreviewUpdate = debounce(function(currentSocials) { // Pass socials to ensure it uses the latest
        if (manager.contentPreview && typeof manager.contentPreview.renderSocials === 'function') {
            let previewElInsideIframe = manager.getPreviewEl ? manager.getPreviewEl() : null;
            let contentWrapperEl = previewElInsideIframe ? previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper') : null;

            if (previewElInsideIframe && contentWrapperEl) {
                manager.contentPreview.renderSocials(currentSocials, previewElInsideIframe, contentWrapperEl);
            } else {
                if (!isInitialSocialRender) { 
                    console.warn('[SocialsBrain] Debounced: Preview container or content wrapper not found for live update.');
                }
            }
        } else {
            if (!isInitialSocialRender) { 
                console.warn('[SocialsBrain] Debounced: Content Preview Renderer (renderSocials) not available for live update.');
            }
        }
    }, 300);


    manager.socialIcons.init = function(configData) {
        console.log('[SocialIcons.init] Received configData:', configData); // ADDED LOG
        const initialSocials = manager.initialData?.social_links ? JSON.parse(JSON.stringify(manager.initialData.social_links)) : [];
        let socials = initialSocials; // This 'socials' will be captured by closures
        
        const socialListEl = document.getElementById('bp-social-icons-list');
        const socialInputEl = document.getElementById('band_profile_social_links_json');
        const addSocialBtn = document.getElementById('bp-add-social-icon-btn');

        console.log('[SocialIcons] Attempting to find Add Social Icon button:', addSocialBtn);

        // Access supported social types from the passed configData parameter
        const allSocialTypes = configData?.supportedLinkTypes || {};
        console.log('[SocialIcons] allSocialTypes (from passed configData in init):', allSocialTypes); // Updated log message

        // Convert the object into an array format compatible with the existing logic
        const socialTypesArray = Object.keys(allSocialTypes).map(key => ({
            value: key,
            label: allSocialTypes[key].label,
            icon: allSocialTypes[key].icon
        }));
        console.log('[SocialIcons] socialTypesArray created:', socialTypesArray); // ADDED LOG

        // Filter unique and repeatable types based on the new array structure
        const uniqueTypes = socialTypesArray.filter(type => type.value !== 'website' && type.value !== 'email');
        console.log('[SocialIcons] uniqueTypes created:', uniqueTypes); // ADDED LOG
        const repeatableTypes = socialTypesArray.filter(type => type.value === 'website' || type.value === 'email');
        console.log('[SocialIcons] repeatableTypes created:', repeatableTypes); // ADDED LOG

        isInitialSocialRender = true; // Reset for this init call

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
                    const availableUniqueOptions = uniqueTypes.filter(opt => !currentlyUsedUniqueTypes.includes(opt.value));
                    const combinedOptions = [...repeatableTypes, ...availableUniqueOptions];
                    optionsHtml = combinedOptions.map(opt => {
                        return `<option value="${opt.value}"${currentTypeForThisRow === opt.value ? ' selected' : ''}>${opt.label}</option>`;
                    }).join('');
                } else {
                    // Use socialTypesArray (the array version) here
                    optionsHtml = socialTypesArray.map(opt => {
                        const isCurrentlySelectedByThisRow = opt.value === currentTypeForThisRow;
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
            updateSocialInput(true); // Pass flag for immediate preview update after full render
            initializeSortableForSocials(); 
            
            if (addSocialBtn) {
                // Simplified logic: Enable button if there's at least one repeatable type OR
                // if there's at least one unique type not currently used.
                const currentlyUsedUniqueTypes = socials.filter(s => s.type !== 'website' && s.type !== 'email').map(s => s.type);
                const hasAvailableUniqueType = uniqueTypes.some(type => !currentlyUsedUniqueTypes.includes(type.value));
                const hasRepeatableTypes = repeatableTypes.length > 0;

                if (hasRepeatableTypes || hasAvailableUniqueType) {
                    // console.log('[SocialIcons] Enabling Add Social Icon button: Repeatable available (', hasRepeatableTypes, ') or Unique available (', hasAvailableUniqueType, ').');
                    addSocialBtn.disabled = false;
                } else {
                    // console.log('[SocialIcons] Disabling Add Social Icon button: No repeatable or available unique types.');
                    addSocialBtn.disabled = true;
                }
            }

            if (isInitialSocialRender) {
                isInitialSocialRender = false;
            }
        }

        function getFirstAvailableType() {
            const currentUsedTypes = socials.map(s => s.type);
            let firstAvailable = uniqueTypes.find(st => !currentUsedTypes.includes(st.value));
            if (firstAvailable) {
                return firstAvailable;
            }
            return repeatableTypes.length > 0 ? repeatableTypes[0] : null;
        }

        // Modified updateSocialInput
        function updateSocialInput(forceImmediatePreview = false) {
            if (socialInputEl) {
                socialInputEl.value = JSON.stringify(socials);
                const event = new Event('input', { bubbles: true, cancelable: true });
                socialInputEl.dispatchEvent(event);
            }

            if (forceImmediatePreview) {
                // Call the core preview logic directly
                if (manager.contentPreview && typeof manager.contentPreview.renderSocials === 'function') {
                    let previewElInsideIframe = manager.getPreviewEl ? manager.getPreviewEl() : null;
                    let contentWrapperEl = previewElInsideIframe ? previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper') : null;
                    if (previewElInsideIframe && contentWrapperEl) {
                        manager.contentPreview.renderSocials(socials, previewElInsideIframe, contentWrapperEl);
                    }
                }
            } else {
                // Use the debounced version for URL input changes
                debouncedSocialPreviewUpdate(socials);
            }
        }
        
        // Modified updateSocialProp
        function updateSocialProp(idx, prop, value) {
            if (socials[idx]) {
                socials[idx][prop] = value;
            }
            // Only re-render the entire list of input fields if the type changes
            if (prop === 'type') {
                renderSocials(); // This will call updateSocialInput(true) for immediate preview
            } else {
                // For URL changes, just update the hidden input and trigger a debounced preview update.
                // The input field itself remains, so no focus loss.
                updateSocialInput(false); 
            }
        }

        function removeSocial(idx) {
            socials.splice(idx,1);
            renderSocials(); // Re-render fully and update preview immediately
        }

        if (addSocialBtn) {
            addSocialBtn.addEventListener('click', function() {
                console.log('[SocialIcons] Add Social Icon button clicked.');
                console.log('[SocialIcons] Supported Link Types (from passed configData in click handler):', configData?.supportedLinkTypes); // Updated log message
                if (!Array.isArray(socials)) {
                    socials = [];
                }
                const firstAvailable = getFirstAvailableType();
                if (firstAvailable) {
                    socials.push({type: firstAvailable.value, url:''});
                    renderSocials(); // Re-render fully and update preview immediately
                } else {
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

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});