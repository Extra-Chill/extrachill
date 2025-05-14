// Modular Social Links Handler for Band Profile and Link Page Management
(function(){
    // Only run if the social links input and list exist
    const socialInputEl = document.getElementById('band_profile_social_links_json');
    const socialListEl = document.getElementById('bp-social-icons-list');
    const addBtn = document.getElementById('bp-add-social-icon-btn');
    if (!socialInputEl || !socialListEl || !addBtn) return;
    let socials = [];
    try { socials = JSON.parse(socialInputEl.value) || []; } catch(e) { socials = []; }
    const socialTypes = [
        { value: 'instagram', label: 'Instagram' },
        { value: 'twitter', label: 'Twitter' },
        { value: 'facebook', label: 'Facebook' },
        { value: 'youtube', label: 'YouTube' },
        { value: 'tiktok', label: 'TikTok' },
        { value: 'soundcloud', label: 'SoundCloud' },
        { value: 'bandcamp', label: 'Bandcamp' },
        { value: 'website', label: 'Website' },
        { value: 'email', label: 'Email' },
        // Add more as needed
    ];
    function updateSocialInput() {
        socialInputEl.value = JSON.stringify(socials);
        // Manually trigger input event for live preview
        const event = new Event('input', { bubbles: true });
        socialInputEl.dispatchEvent(event);
    }
    function renderSocials() {
        socialListEl.innerHTML = '';
        socials.forEach((social, idx) => {
            const row = document.createElement('div');
            row.className = 'bp-social-row';
            row.style.display = 'flex';
            row.style.alignItems = 'center';
            row.style.gap = '10px';
            row.style.marginBottom = '8px';
            row.setAttribute('data-idx', idx.toString()); // Ensure parent row has data-idx

            // Social type dropdown
            let options = socialTypes.map(opt => `<option value="${opt.value}"${social.type===opt.value?' selected':''}>${opt.label}</option>`).join('');
            let inputType = (social.type === 'email') ? 'email' : 'url';
            let inputPlaceholder = (social.type === 'email') ? 'Email Address' : 'Profile URL';
            
            // Generate clean HTML, relying on event delegation for button clicks
            row.innerHTML = `
                <select class="bp-social-type-select">${options}</select>
                <input type="${inputType}" class="bp-social-url-input" placeholder="${inputPlaceholder}" value="${social.url||''}" style="flex:3;">
                <button type="button" class="button bp-remove-social-btn">&times;</button>
                <button type="button" class="button bp-move-social-up-btn" title="Move Up">&#8593;</button>
                <button type="button" class="button bp-move-social-down-btn" title="Move Down">&#8595;</button>
            `;

            // Update select and input to use addEventListener instead of inline onchange for better practice
            // And ensure they use the row's data-idx when calling global functions.
            const typeSelect = row.querySelector('.bp-social-type-select');
            if(typeSelect) {
                typeSelect.addEventListener('change', function() {
                    // 'idx' is from the forEach loop, correctly scoped here for this row
                    window.bpUpdateSocialType(idx, this.value);
                });
            }

            const urlInput = row.querySelector('.bp-social-url-input');
            if(urlInput) {
                urlInput.addEventListener('input', function() { // Using 'input' for better reactivity
                     // 'idx' is from the forEach loop
                    window.bpUpdateSocialUrl(idx, this.value);
                });
            }
            
            // Disable move buttons if at extremes (visual only, click handled by delegation)
            const moveUpBtn = row.querySelector('.bp-move-social-up-btn');
            if (moveUpBtn && idx === 0) moveUpBtn.disabled = true;
            const moveDownBtn = row.querySelector('.bp-move-social-down-btn');
            if (moveDownBtn && idx === socials.length - 1) moveDownBtn.disabled = true;

            socialListEl.appendChild(row);
        });
        updateSocialInput();
    }
    window.bpUpdateSocialType = function(idx, val) { socials[idx].type = val; renderSocials(); }
    window.bpUpdateSocialUrl = function(idx, val) { socials[idx].url = val; renderSocials(); }
    window.bpRemoveSocial = function(idx) { socials.splice(idx,1); renderSocials(); }
    window.bpMoveSocial = function(idx, dir) {
        const newIdx = idx + dir;
        if (newIdx < 0 || newIdx >= socials.length) return;
        const temp = socials[newIdx];
        socials[newIdx] = socials[idx];
        socials[idx] = temp;
        renderSocials();
    }
    addBtn.onclick = function() {
        socials.push({type:'instagram',url:''});
        renderSocials();
    };
    renderSocials();
    // Expose for debugging
    window.bpLinkPageSocialLinks = socials;
})(); 