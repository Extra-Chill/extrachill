// Social Icons Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Social icons script cannot run.');
        return;
    }
    manager.socialIcons = manager.socialIcons || {};

    manager.socialIcons.init = function() {
        const initialSocials = manager.initialData?.social_links ? JSON.parse(JSON.stringify(manager.initialData.social_links)) : [];
        let socials = initialSocials;
        
        const socialListEl = document.getElementById('bp-social-icons-list');
        const socialInputEl = document.getElementById('band_profile_social_links_json');
        const socialTypes = [
            { value: 'instagram', label: 'Instagram' },
            { value: 'twitter', label: 'Twitter' },
            { value: 'facebook', label: 'Facebook' },
            { value: 'youtube', label: 'YouTube' },
            { value: 'tiktok', label: 'TikTok' },
            { value: 'soundcloud', label: 'SoundCloud' },
            { value: 'bandcamp', label: 'Bandcamp' },
            { value: 'spotify', label: 'Spotify' }, // Added Spotify
            { value: 'applemusic', label: 'Apple Music' }, // Added Apple Music
            { value: 'website', label: 'Website' },
            { value: 'email', label: 'Email' },
            // Add other relevant types here
        ];
        let isInitialSocialRender = true;

        function renderSocials() {
            if (!socialListEl) return;
            socialListEl.innerHTML = '';
            socials.forEach((social, idx) => {
                const row = document.createElement('div');
                row.className = 'bp-social-row';
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.gap = '10px';
                row.style.marginBottom = '8px';
                row.setAttribute('data-idx', idx.toString());

                let optionsHtml = socialTypes.map(opt => `<option value="${opt.value}"${social.type === opt.value ? ' selected' : ''}>${opt.label}</option>`).join('');
                let inputType = (social.type === 'email') ? 'email' : 'url';
                let inputPlaceholder = (social.type === 'email') ? 'Email Address' : 'Profile URL';

                const moveUpButtonHtml = `<button type="button" class="button bp-move-social-up-btn" title="Move Up" ${idx === 0 ? 'disabled' : ''}>&#8593;</button>`;
                const moveDownButtonHtml = `<button type="button" class="button bp-move-social-down-btn" title="Move Down" ${idx === socials.length - 1 ? 'disabled' : ''}>&#8595;</button>`;

                row.innerHTML = `
                    <select class="bp-social-type-select">${optionsHtml}</select>
                    <input type="${inputType}" class="bp-social-url-input" placeholder="${inputPlaceholder}" value="${social.url || ''}" style="flex:3;">
                    ${moveUpButtonHtml}
                    ${moveDownButtonHtml}
                    <a href="#" class="bp-remove-social-btn bp-remove-item-link" title="Remove Social Icon" style="color:red;text-decoration:none;margin-left:auto;">&times;</a>
                `;

                const typeSelect = row.querySelector('.bp-social-type-select');
                if (typeSelect) {
                    typeSelect.addEventListener('change', function() { updateSocialProp(idx, 'type', this.value); });
                }
                const urlInput = row.querySelector('.bp-social-url-input');
                if (urlInput) {
                    urlInput.addEventListener('input', function() { updateSocialProp(idx, 'url', this.value); });
                }
                
                // Attach event listeners for move buttons directly here
                const moveUpBtn = row.querySelector('.bp-move-social-up-btn');
                if (moveUpBtn) {
                    moveUpBtn.addEventListener('click', function() { moveSocial(idx, -1); });
                }
                const moveDownBtn = row.querySelector('.bp-move-social-down-btn');
                if (moveDownBtn) {
                    moveDownBtn.addEventListener('click', function() { moveSocial(idx, 1); });
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
            isInitialSocialRender = false;
        }

        function updateSocialInput() {
            if (!socialInputEl) return;
            socialInputEl.value = JSON.stringify(socials);
            const event = new Event('input', { bubbles: true, cancelable: true });
            socialInputEl.dispatchEvent(event);
            if (!isInitialSocialRender && manager.updatePreviewViaAJAX) {
                 if (!manager.debouncedUpdatePreviewSocials) {
                    manager.debouncedUpdatePreviewSocials = debounce(manager.updatePreviewViaAJAX, 350);
                }
                manager.debouncedUpdatePreviewSocials();
            }
        }

        function updateSocialProp(idx, prop, value) {
            if (socials[idx]) socials[idx][prop] = value;
            if (prop === 'type') renderSocials(); // Re-render if type changes to update input type/placeholder
            else updateSocialInput();
        }
        function removeSocial(idx) {
            socials.splice(idx,1);
            renderSocials();
        }
        function moveSocial(idx, dir) {
            const newIdx = idx + dir;
            if (newIdx < 0 || newIdx >= socials.length) return;
            [socials[newIdx], socials[idx]] = [socials[idx], socials[newIdx]];
            renderSocials();
        }

        // Removed delegated event listener block as listeners are added directly during render.

        const addSocialBtn = document.getElementById('bp-add-social-icon-btn');
        if (addSocialBtn) {
            addSocialBtn.addEventListener('click', function() {
                socials.push({type:'website',url:''}); // Default to 'website' or another common type
                renderSocials();
            });
        }
        
        if (socialListEl && socialInputEl) {
            renderSocials(); // Initial render
        } else {
            console.error("Social icons list or JSON input element not found. Cannot initialize social icons UI.");
        }
    };

    // Debounce function (can be shared if moved to a global util, or kept local)
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    // Initialize when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', manager.socialIcons.init);
    } else {
        manager.socialIcons.init();
    }

})(window.ExtrchLinkPageManager);