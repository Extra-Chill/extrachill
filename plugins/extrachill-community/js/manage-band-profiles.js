/**
 * JavaScript for handling the Band Members management section on the frontend manage page.
 */
jQuery(document).ready(function($) {
    // Data passed from PHP via wp_localize_script (object name: bpManageMembersData)
    if (typeof bpManageMembersData === 'undefined') {
        console.error('bpManageMembersData is not defined. Ensure it is localized.');
        return;
    }
    const ajaxUrl = bpManageMembersData.ajaxUrl;
    const bandProfileId = bpManageMembersData.bandProfileId;
    // Nonces (ensure these are added via wp_localize_script)
    const ajaxAddNonce = bpManageMembersData.ajaxAddNonce || ''; 
    const ajaxRemovePlaintextNonce = bpManageMembersData.ajaxRemovePlaintextNonce || '';
    const ajaxInviteNonce = bpManageMembersData.ajaxInviteNonce || ''; // For later

    if ( !bandProfileId || bandProfileId <= 0 || !$('#bp-manage-members-section').length ) {
        return;
    }

    // --- DOM Elements ---
    const unifiedRosterList = $('#bp-unified-roster-list');
    const hiddenRemoveUserIdsInput = $('#bp-remove-member-ids-frontend'); // For removing linked users on main form save

    const showAddMemberFormLink = $('#bp-show-add-member-form-link');
    const addMemberFormArea = $('#bp-add-member-form-area');
    const newMemberEmailInput = $('#bp-new-member-email-input');
    const ajaxInviteMemberButton = $('#bp-ajax-invite-member-button');
    const cancelAddMemberFormLink = $('#bp-cancel-add-member-form-link');

    // --- State Variables ---
    let membersToRemove = []; // Array of user IDs (of existing *linked* members to remove via main form save)
    updateHiddenFormFields(); // Initial call

    // --- Show/Hide Add Member Form ---
    showAddMemberFormLink.on('click', function(e) {
        e.preventDefault();
        addMemberFormArea.slideDown();
        $(this).hide();
        newMemberEmailInput.focus();
    });

    cancelAddMemberFormLink.on('click', function(e) {
        e.preventDefault();
        addMemberFormArea.slideUp();
        newMemberEmailInput.val('');
        showAddMemberFormLink.show();
    });

    // --- AJAX: Add New Member to Roster (as Plaintext) ---
    ajaxInviteMemberButton.on('click', function() {
        const inviteEmail = newMemberEmailInput.val().trim();
        if (!inviteEmail) {
            alert('Please enter an email address.');
            newMemberEmailInput.focus();
            return;
        }
        $(this).prop('disabled', true).text('Sending...');
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'bp_ajax_invite_member_by_email',
                band_id: bandProfileId,
                invite_email: inviteEmail,
                nonce: bpManageMembersData.ajaxInviteMemberByEmailNonce
            },
            success: function(response) {
                if (response.success && response.data && response.data.updated_roster_item_html) {
                    unifiedRosterList.find('.no-members').remove();
                    unifiedRosterList.append(response.data.updated_roster_item_html);
                    newMemberEmailInput.val('').focus();
                } else {
                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Could not send invitation.'));
                }
            },
            error: function() {
                alert('An error occurred while sending the invitation. Please try again.');
            },
            complete: function() {
                ajaxInviteMemberButton.prop('disabled', false).text('Send Invitation');
            }
        });
    });

    // --- AJAX: Remove Plaintext Listed Member ---
    unifiedRosterList.on('click', '.bp-ajax-remove-plaintext-member', function(e) {
        e.preventDefault();
        const $thisLink = $(this);
        const listItem = $thisLink.closest('li');
        const plaintextId = $thisLink.data('ptid');
        const memberName = listItem.find('.member-name').text();

        if (!plaintextId) return;

        if (!confirm(`Are you sure you want to remove "${memberName}" from the roster listing?`)) {
            return;
        }

        // Optimistically hide, or add loading state
        listItem.css('opacity', '0.5'); 

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'bp_ajax_remove_plaintext_member_action',
                band_id: bandProfileId,
                plaintext_member_id: plaintextId,
                nonce: ajaxRemovePlaintextNonce
            },
            success: function(response) {
                if (response.success) {
                    listItem.fadeOut(function() { 
                        $(this).remove(); 
                        if (unifiedRosterList.children('li:not(.no-members)').length === 0) {
                            unifiedRosterList.append('<li class=\"no-members\">No members listed for this band yet.</li>');
                        }
                    });
                } else {
                    alert('Error: ' + (response.data || 'Could not remove listing.'));
                    listItem.css('opacity', '1'); // Revert on error
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                listItem.css('opacity', '1');
            }
        });
    });

    // --- Mark Linked Member for Removal (handled by main form save) ---
    unifiedRosterList.on('click', '.bp-remove-member-button', function(e) {
        e.preventDefault();
        const button = $(this);
        const listItem = button.closest('li');
        const userIdToRemove = listItem.data('user-id');

        if (!userIdToRemove) return;

        if (listItem.hasClass('marked-for-removal')) {
            // Optional: Implement un-marking if clicked again
            // membersToRemove = membersToRemove.filter(id => id !== userIdToRemove);
            // listItem.css('opacity', '1').removeClass('marked-for-removal');
            // button.show();
            // listItem.find('.temp-removal-text').remove();
        } else {
            if (!membersToRemove.includes(userIdToRemove)) {
                membersToRemove.push(userIdToRemove);
            }
            listItem.css('opacity', '0.5').addClass('marked-for-removal'); 
            button.hide(); 
            // Check if status label exists, if not, create one to append to
            let statusLabel = listItem.find('.member-status-label');
            if(!statusLabel.length) {
                statusLabel = $('<span class=\"member-status-label\"></span>').appendTo(listItem.find('.member-name').parent());
            }
            statusLabel.append(' <em class=\"temp-removal-text\" style=\"color:red;\">(Marked for removal)</em>');
        }
        updateHiddenFormFields();
    });

    // --- Update Hidden Form Fields (now only for removing linked users) ---
    function updateHiddenFormFields() {
        const uniqueMembersToRemoveIds = [...new Set(membersToRemove)];
        hiddenRemoveUserIdsInput.val(uniqueMembersToRemoveIds.join(','));
        // console.log("To Remove IDs (Linked Users):", hiddenRemoveUserIdsInput.val());
    }

    // ===== End Member/Roster Management Logic =====

    // ===== Dynamic Band Links Management =====

    const linkTypes = bpManageMembersData.linkTypes || {};
    const existingLinks = bpManageMembersData.existingLinks || [];
    const linksListContainer = $('#bp-links-list');
    const addLinkButton = $('#bp-add-link-button');
    const transRemoveLink = bpManageMembersData.text.removeLink || 'Remove Link';
    const transCustomLabel = bpManageMembersData.text.customLinkLabel || 'Custom Link Label';

    let linkIndex = 0; // To ensure unique array keys for inputs

    // --- Function to Render a Single Link Input Group ---
    function renderLinkItem(index, linkData = {}) {
        const typeKey = linkData.type_key || 'website'; // Default to website
        const url = linkData.url || '';
        const customLabel = linkData.custom_label || '';

        // Create dropdown options
        let typeOptionsHtml = '';
        for (const key in linkTypes) {
            typeOptionsHtml += `<option value="${key}" ${key === typeKey ? 'selected' : ''}>${linkTypes[key].label}</option>`;
        }

        // Check if the selected type requires a custom label
        const showCustomLabel = linkTypes[typeKey]?.has_custom_label || false;

        const itemHtml = `
            <div class="bp-dynamic-link-item" data-index="${index}" style="display: flex; flex-wrap: wrap; gap: 10px; padding: 10px; border: 1px solid #eee; margin-bottom: 10px; border-radius: 4px;">
                <div style="flex: 1 1 150px;">
                    <label for="band_links_${index}_type_key" class="screen-reader-text">Link Type</label>
                    <select name="band_links[${index}][type_key]" id="band_links_${index}_type_key" class="bp-link-type-select">
                        ${typeOptionsHtml}
                    </select>
                </div>
                 <div class="bp-link-custom-label-wrapper" style="flex: 1 1 150px; ${showCustomLabel ? '' : 'display: none;'}">
                    <label for="band_links_${index}_custom_label" class="screen-reader-text">${transCustomLabel}</label>
                    <input type="text" name="band_links[${index}][custom_label]" id="band_links_${index}_custom_label" value="${customLabel}" placeholder="${transCustomLabel}">
                </div>
                <div style="flex: 2 1 300px;">
                    <label for="band_links_${index}_url" class="screen-reader-text">URL</label>
                    <input type="url" name="band_links[${index}][url]" id="band_links_${index}_url" value="${url}" placeholder="https://..." required style="width: 100%;">
                </div>
                <div style="flex: 0 0 auto;">
                    <button type="button" class="button button-small bp-remove-link-button" title="${transRemoveLink}">&times;</button>
                </div>
            </div>
        `;
        return itemHtml;
    }

    // --- Initial Rendering of Existing Links ---
    if (existingLinks.length > 0) {
        existingLinks.forEach(link => {
            linksListContainer.append(renderLinkItem(linkIndex, link));
            linkIndex++;
        });
    }

    // --- Add Link Button Handler ---
    addLinkButton.on('click', function() {
        linksListContainer.append(renderLinkItem(linkIndex));
        linkIndex++;
    });

    // --- Remove Link Button Handler (Event Delegation) ---
    linksListContainer.on('click', '.bp-remove-link-button', function() {
        $(this).closest('.bp-dynamic-link-item').remove();
        // Note: Indices won't be sequential after removal, but PHP handles non-sequential arrays fine.
    });

    // --- Link Type Dropdown Change Handler (Event Delegation) ---
    linksListContainer.on('change', '.bp-link-type-select', function() {
        const selectedType = $(this).val();
        const $item = $(this).closest('.bp-dynamic-link-item');
        const $customLabelWrapper = $item.find('.bp-link-custom-label-wrapper');
        const $customLabelInput = $customLabelWrapper.find('input');

        if (linkTypes[selectedType]?.has_custom_label) {
            $customLabelWrapper.show();
        } else {
            $customLabelWrapper.hide();
            $customLabelInput.val(''); // Clear value when hidden
        }
    });

    // ===== End Dynamic Band Links Management =====

    // ===== Band Switcher Dropdown Logic =====
    const bandSwitcherSelect = $('#band-switcher-select');
    if (bandSwitcherSelect.length) {
        bandSwitcherSelect.on('change', function() {
            const selectedBandId = $(this).val();
            if (selectedBandId && selectedBandId !== '') {
                let currentUrl = window.location.href.split('?')[0];
                // Append other existing GET parameters if necessary, for now, just band_id
                // More robust URL parameter handling might be needed if other params are critical
                window.location.href = currentUrl + '?band_id=' + selectedBandId;
            } else if (selectedBandId === '') {
                // Optional: If they select the "-- Select a Band --" option, 
                // redirect to the base manage page without a band_id, if that's desired behavior.
                // For now, it does nothing, requiring a positive selection.
            }
        });
    }
    // ===== End Band Switcher Dropdown Logic =====

    // ===== Social Links Management (Adapted from manage-link-page-socials.js) =====
    const socialListContainer = $('#bp-social-icons-list'); // Container for social rows
    const addSocialButton = $('#bp-add-social-icon-btn');   // The "Add Social Icon" button
    const socialJsonInput = $('#band_profile_social_links_json'); // Hidden input holding the JSON

    if (socialListContainer.length && addSocialButton.length && socialJsonInput.length) {
        let currentSocials = [];
        try {
            const initialJson = socialJsonInput.val();
            if (initialJson) {
                currentSocials = JSON.parse(initialJson);
                if (!Array.isArray(currentSocials)) currentSocials = [];
            }
        } catch (e) {
            console.error("Error parsing initial social links JSON:", e);
            currentSocials = [];
        }

        const socialTypes = [
            { value: 'instagram', label: 'Instagram', icon: 'fab fa-instagram', placeholder: 'Instagram Profile URL' },
            { value: 'twitter', label: 'Twitter', icon: 'fab fa-twitter', placeholder: 'Twitter Profile URL' },
            { value: 'facebook', label: 'Facebook', icon: 'fab fa-facebook', placeholder: 'Facebook Page URL' },
            { value: 'youtube', label: 'YouTube', icon: 'fab fa-youtube', placeholder: 'YouTube Channel URL' },
            { value: 'tiktok', label: 'TikTok', icon: 'fab fa-tiktok', placeholder: 'TikTok Profile URL' },
            { value: 'soundcloud', label: 'SoundCloud', icon: 'fab fa-soundcloud', placeholder: 'SoundCloud Profile URL' },
            { value: 'bandcamp', label: 'Bandcamp', icon: 'fab fa-bandcamp', placeholder: 'Bandcamp Page URL' },
            { value: 'spotify', label: 'Spotify', icon: 'fab fa-spotify', placeholder: 'Spotify Artist/Profile URL' },
            { value: 'applemusic', label: 'Apple Music', icon: 'fab fa-apple', placeholder: 'Apple Music Artist/Profile URL' }, // Note: fa-apple might not be specific enough
            { value: 'website', label: 'Website', icon: 'fas fa-globe', placeholder: 'Your Website URL' },
            { value: 'email', label: 'Email', icon: 'fas fa-envelope', placeholder: 'Contact Email Address' },
            // Add other types with icons and placeholders as needed
        ];

        function renderSocialLinksUI() {
            socialListContainer.empty(); // Clear existing items
            currentSocials.forEach((socialItem, index) => {
                const selectedTypeInfo = socialTypes.find(st => st.value === socialItem.type) || socialTypes.find(st => st.value === 'website');
                let urlInputType = (socialItem.type === 'email') ? 'email' : 'url';

                const rowHtml = `
                    <div class="bp-social-row" data-index="${index}">
                        <div class="bp-social-type-icon"><i class="${selectedTypeInfo.icon || 'fas fa-link'}"></i></div>
                        <select class="bp-social-type-select">
                            ${socialTypes.map(st => `<option value="${st.value}"${st.value === socialItem.type ? ' selected' : ''}>${st.label}</option>`).join('')}
                        </select>
                        <input type="${urlInputType}" class="bp-social-url-input" placeholder="${selectedTypeInfo.placeholder || 'Enter URL'}" value="${socialItem.url || ''}">
                        <div class="bp-social-actions">
                            <button type="button" class="button bp-move-social-up-btn" title="Move Up" ${index === 0 ? 'disabled' : ''}>&#8593;</button>
                            <button type="button" class="button bp-move-social-down-btn" title="Move Down" ${index === currentSocials.length - 1 ? 'disabled' : ''}>&#8595;</button>
                            <button type="button" class="button bp-remove-social-btn" title="Remove">&times;</button>
                        </div>
                    </div>
                `;
                socialListContainer.append(rowHtml);
            });
            updateHiddenJsonInput();
        }

        function updateHiddenJsonInput() {
            socialJsonInput.val(JSON.stringify(currentSocials));
        }

        socialListContainer.on('change', '.bp-social-type-select', function() {
            const index = $(this).closest('.bp-social-row').data('index');
            currentSocials[index].type = $(this).val();
            // Update icon and placeholder based on new type
            const selectedTypeInfo = socialTypes.find(st => st.value === currentSocials[index].type);
            const $row = $(this).closest('.bp-social-row');
            $row.find('.bp-social-type-icon i').attr('class', selectedTypeInfo.icon || 'fas fa-link');
            $row.find('.bp-social-url-input').attr('placeholder', selectedTypeInfo.placeholder || 'Enter URL');
            if (currentSocials[index].type === 'email') {
                $row.find('.bp-social-url-input').attr('type', 'email');
            } else {
                $row.find('.bp-social-url-input').attr('type', 'url');
            }
            updateHiddenJsonInput();
        });

        socialListContainer.on('input', '.bp-social-url-input', function() {
            const index = $(this).closest('.bp-social-row').data('index');
            currentSocials[index].url = $(this).val();
            updateHiddenJsonInput();
        });

        socialListContainer.on('click', '.bp-remove-social-btn', function(e) {
            e.preventDefault();
            const index = $(this).closest('.bp-social-row').data('index');
            currentSocials.splice(index, 1);
            renderSocialLinksUI(); // Re-render to update indices and UI
        });

        socialListContainer.on('click', '.bp-move-social-up-btn', function(e) {
            e.preventDefault();
            const index = $(this).closest('.bp-social-row').data('index');
            if (index > 0) {
                [currentSocials[index], currentSocials[index - 1]] = [currentSocials[index - 1], currentSocials[index]];
                renderSocialLinksUI();
            }
        });

        socialListContainer.on('click', '.bp-move-social-down-btn', function(e) {
            e.preventDefault();
            const index = $(this).closest('.bp-social-row').data('index');
            if (index < currentSocials.length - 1) {
                [currentSocials[index], currentSocials[index + 1]] = [currentSocials[index + 1], currentSocials[index]];
                renderSocialLinksUI();
            }
        });

        addSocialButton.on('click', function() {
            currentSocials.push({ type: 'website', url: '' }); // Add a new default social link
            renderSocialLinksUI();
        });

        renderSocialLinksUI(); // Initial render
    }
    // ===== End Social Links Management =====

}); 