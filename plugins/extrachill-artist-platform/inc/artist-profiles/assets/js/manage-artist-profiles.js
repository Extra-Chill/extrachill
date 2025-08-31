/**
 * JavaScript for handling the Artist Members management section on the frontend manage page.
 */
jQuery(document).ready(function($) {
    // Data passed from PHP via wp_localize_script (object name: bpManageMembersData)
    if (typeof bpManageMembersData === 'undefined') {
        console.error('bpManageMembersData is not defined. Ensure it is localized.');
    }
    const ajaxUrl = bpManageMembersData ? bpManageMembersData.ajaxUrl : null;
    const artistProfileId = bpManageMembersData ? bpManageMembersData.artistProfileId : null;
    const ajaxAddNonce = bpManageMembersData ? bpManageMembersData.ajaxAddNonce || '' : ''; 
    const ajaxRemovePlaintextNonce = bpManageMembersData ? bpManageMembersData.ajaxRemovePlaintextNonce || '' : '';
    // const ajaxInviteNonce = bpManageMembersData.ajaxInviteNonce || ''; // For later, if direct invite from main page is re-added

    // Keep track of initialized tabs to avoid redundant setups
    const initializedTabs = new Set();

    // Call once on ready for elements that might be outside tabs or in the default active tab
    initializeArtistImagePreviews(document); 

    // This function will set up listeners for the profile managers tab.
    // Simple add/remove functionality that reloads the page after changes.
    function initializeProfileManagersTabEventListeners(managersTabContentElement) {
        const $rosterTabContent = $(rosterTabContentElement);

        if ($rosterTabContent.length === 0) {
            console.warn('Roster tab content element not found when trying to init listeners.');
            return; 
        }
        // console.log('Roster tab content element found. Initializing delegated listeners for roster.', $rosterTabContent);

        // Prevent re-initializing if already done for this specific element instance
        if ($rosterTabContent.data('rosterListenersInitialized')) {
            // console.log('Roster listeners already initialized for this tab content.');
            return;
        }

        const unifiedRosterListSelector = '#bp-unified-roster-list'; 
        const hiddenRemoveUserIdsInputSelector = '#bp-remove-member-ids-frontend'; 
        let membersToRemove = [];
        
        const $hiddenRemoveUserIdsInput = $rosterTabContent.find(hiddenRemoveUserIdsInputSelector);
        if ($hiddenRemoveUserIdsInput.length) {
            updateHiddenFormFields(); 
        }

        // Use more specific delegation from $rosterTabContent
        $rosterTabContent.on('click.rosterEvents', '#bp-show-add-member-form-link', function(e) {
            // console.log('[DEBUG] Delegated click fired on #bp-show-add-member-form-link');
            e.preventDefault();
            const $this = $(this);
            const addMemberFormArea = $rosterTabContent.find('#bp-add-member-form-area');
            const newMemberEmailInput = $rosterTabContent.find('#bp-new-member-email-input');
            addMemberFormArea.slideDown();
            $this.hide();
            if(newMemberEmailInput.length) newMemberEmailInput.focus();
        });

        $rosterTabContent.on('click.rosterEvents', '#bp-cancel-add-member-form-link', function(e) {
            // console.log('[DEBUG] Delegated click fired on #bp-cancel-add-member-form-link');
            e.preventDefault();
            const addMemberFormArea = $rosterTabContent.find('#bp-add-member-form-area');
            const newMemberEmailInput = $rosterTabContent.find('#bp-new-member-email-input');
            const showAddMemberFormLink = $rosterTabContent.find('#bp-show-add-member-form-link');
            addMemberFormArea.slideUp();
            if(newMemberEmailInput.length) newMemberEmailInput.val('');
            if(showAddMemberFormLink.length) showAddMemberFormLink.show();
        });

        $rosterTabContent.on('click.rosterEvents', '#bp-ajax-invite-member-button', function(e) {
            // console.log('[DEBUG] Delegated click fired on #bp-ajax-invite-member-button');
            e.preventDefault();
            const $thisButton = $(this);
            const newMemberEmailInput = $rosterTabContent.find('#bp-new-member-email-input');
            const inviteEmail = newMemberEmailInput.val().trim();
            if (!inviteEmail) {
                alert('Please enter an email address.');
                newMemberEmailInput.focus();
                return;
            }
            $thisButton.prop('disabled', true).text('Sending...');
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: { action: 'bp_ajax_invite_member_by_email', artist_id: artistProfileId, invite_email: inviteEmail, nonce: bpManageMembersData.ajaxInviteMemberByEmailNonce },
                success: function(response) {
                    if (response.success && response.data && response.data.updated_roster_item_html) {
                        const $unifiedRosterList = $rosterTabContent.find(unifiedRosterListSelector);
                        $unifiedRosterList.find('.no-members').remove();
                        $unifiedRosterList.append(response.data.updated_roster_item_html);
                        newMemberEmailInput.val('').focus();
                    } else { alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Could not send invitation.')); }
                },
                error: function() { alert('An error occurred while sending the invitation. Please try again.'); },
                complete: function() { $thisButton.prop('disabled', false).text('Send Invitation'); }
            });
        });

        $rosterTabContent.on('click.rosterEvents', unifiedRosterListSelector + ' .bp-ajax-remove-plaintext-member', function(e) { 
            // console.log('[DEBUG] Delegated click fired on .bp-ajax-remove-plaintext-member');
            e.preventDefault();
            const $thisLink = $(this); const listItem = $thisLink.closest('li'); const plaintextId = $thisLink.data('ptid'); const memberName = listItem.find('.member-name').text();
            if (!plaintextId || !confirm(`Are you sure you want to remove "${memberName}" from the roster listing?`)) return;
            listItem.css('opacity', '0.5'); 
            $.ajax({
                url: ajaxUrl, type: 'POST', data: { action: 'bp_ajax_remove_plaintext_member_action', artist_id: artistProfileId, plaintext_member_id: plaintextId, nonce: ajaxRemovePlaintextNonce },
                success: function(response) {
                    if (response.success) {
                        listItem.fadeOut(function() { 
                            $(this).remove(); 
                            const $actualUnifiedRosterList = $rosterTabContent.find(unifiedRosterListSelector);
                            if ($actualUnifiedRosterList.children('li:not(.no-members)').length === 0) $actualUnifiedRosterList.append('<li class="no-members">No members listed for this artist yet.</li>');
                        });
                    } else { alert('Error: ' + (response.data || 'Could not remove listing.')); listItem.css('opacity', '1'); }
                },
                error: function() { alert('An error occurred. Please try again.'); listItem.css('opacity', '1'); }
            });
        });

        $rosterTabContent.on('click.rosterEvents', unifiedRosterListSelector + ' .bp-remove-member-button', function(e) {
            // console.log('[DEBUG] Delegated click fired on .bp-remove-member-button');
            e.preventDefault();
            const button = $(this); const listItem = button.closest('li'); const userIdToRemove = listItem.data('user-id');
            if (!userIdToRemove) return;
            if (listItem.hasClass('marked-for-removal')) { 
                // Logic to unmark (optional, for now just basic removal marking)
                // membersToRemove = membersToRemove.filter(id => id !== userIdToRemove);
                // listItem.css('opacity', '1').removeClass('marked-for-removal');
                // button.text('Remove').show(); // Or whatever the original text was
                // listItem.find('.temp-removal-text').remove();
            } else {
                if (!membersToRemove.includes(userIdToRemove)) membersToRemove.push(userIdToRemove);
                listItem.css('opacity', '0.5').addClass('marked-for-removal'); button.hide(); 
                let statusLabel = listItem.find('.member-status-label');
                if(!statusLabel.length) statusLabel = $('<span class="member-status-label"></span>').appendTo(listItem.find('.member-name').parent()); // Ensure it appends within the li structure correctly
                statusLabel.append(' <em class="temp-removal-text">(Marked for removal)</em>');
            }
            if ($hiddenRemoveUserIdsInput.length) updateHiddenFormFields();
        });

        function updateHiddenFormFields() {
            const uniqueMembersToRemoveIds = [...new Set(membersToRemove)];
            $hiddenRemoveUserIdsInput.val(uniqueMembersToRemoveIds.join(','));
        }
        
        $rosterTabContent.data('rosterListenersInitialized', true); // Mark as initialized
        // console.log('Roster listeners have been initialized for:', $rosterTabContent[0].id);

    } // End of initializeRosterTabEventListeners

    // Listen for managers tab activation
    document.addEventListener('artistManagersTabActivated', function(event) {
        if (event.detail && event.detail.tabPaneElement) {
            const tabId = event.detail.tabId;
            const tabPaneElement = event.detail.tabPaneElement;

            // Initialize profile managers tab if it's the one activated
            if (tabId === 'manage-artist-profile-managers-content') {
                if (!initializedTabs.has(tabId)) {
                    initializeProfileManagersTabEventListeners(tabPaneElement);
                    initializedTabs.add(tabId);
                }
            }

            // Initialize image previews for this tab
            initializeArtistImagePreviews(tabPaneElement);
        }
    });

    // Listen for info tab activation for image previews
    document.addEventListener('artistInfoTabActivated', function(event) {
        if (event.detail && event.detail.tabPaneElement) {
            initializeArtistImagePreviews(event.detail.tabPaneElement);
        }
    });

    // ===== Artist Switcher Dropdown Logic (Usually outside tabs, so direct binding is fine) =====
    const artistSwitcherSelect = $('#artist-switcher-select');
    if (artistSwitcherSelect.length) {
        artistSwitcherSelect.on('change', function() {
            const selectedArtistId = $(this).val();
            if (selectedArtistId && selectedArtistId !== '') {
                let currentUrl = window.location.href.split('?')[0];
                window.location.href = currentUrl + '?artist_id=' + selectedArtistId;
            } else if (selectedArtistId === '') {
                // No action, or redirect to base manage page
            }
        });
    }
    // ===== End Artist Switcher Dropdown Logic =====

    /*
    // ===== Social Links Management (REMOVED - Now managed on Link Page) =====
    const socialListContainer = $('#bp-social-icons-list'); // Container for social rows
    const addSocialButton = $('#bp-add-social-icon-btn');   // The "Add Social Icon" button
    const socialJsonInput = $('#artist_profile_social_links_json'); // Hidden input holding the JSON

    if (socialListContainer.length && addSocialButton.length && socialJsonInput.length) {
        // ... entire logic for social links management was here ...
        // ... including currentSocials, allSocialTypes, uniqueTypes, repeatableTypes ...
        // ... initializeSortableForSocials, renderSocialLinksUI, getFirstAvailableType ...
        // ... updateHiddenJsonInput, and all event handlers ...
        // ... renderSocialLinksUI(); // Initial render
    }
    // ===== End Social Links Management =====
    */

}); 

// --- Function to Initialize Image Previews ---
// Takes a context element to search within, defaults to document
function initializeArtistImagePreviews(contextElement) {
    const $ = jQuery;
    const $context = $(contextElement || document);

    // --- Header Image Preview ---
    // Use $context.find() to scope the search for inputs
    const headerImageInput = $context.find('#artist_header_image');
    const headerImagePreviewContainer = $context.find('#artist-header-image-preview-container'); // Find container relative to context
    
    // Check if this specific input was already initialized to avoid duplicate listeners
    if (headerImageInput.length && headerImagePreviewContainer.length && !headerImageInput.data('previewInitialized')) {
        const headerImagePreviewImg = headerImagePreviewContainer.find('#artist-header-image-preview-img');
    const headerImageNoImageNotice = headerImagePreviewContainer.find('.no-image-notice');

        headerImageInput.on('change.artiststImagePreview', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    headerImagePreviewImg.attr('src', e.target.result).show();
                    if(headerImageNoImageNotice.length) {
                        headerImageNoImageNotice.hide();
                    }
                }
                reader.readAsDataURL(file);
            } else {
                // If no file is selected, and there's no initial src (or we want to revert to placeholder)
                // Check if current src is a data URL (meaning it was a preview) or if we should show placeholder
                const currentSrc = headerImagePreviewImg.attr('src');
                if (!currentSrc || currentSrc.startsWith('data:image')) { // If no src or it's a preview, show notice
                     headerImagePreviewImg.attr('src', '').hide(); // Clear/hide preview
                    if(headerImageNoImageNotice.length) {
                        headerImageNoImageNotice.show();
                    }
                }
                // If there was an existing server-persisted image, this logic might need adjustment
                // to show that instead of the "no image" notice. For now, clearing is simple.
            }
        }).data('previewInitialized', true); // Mark as initialized
    }

    // --- Featured Image (Profile Picture) Preview ---
    const featuredImageInput = $context.find('#featured_image');
    const featuredImagePreviewContainer = $context.find('#featured-image-preview-container');

    if (featuredImageInput.length && featuredImagePreviewContainer.length && !featuredImageInput.data('previewInitialized')) {
        const featuredImagePreviewImg = featuredImagePreviewContainer.find('#featured-image-preview-img');
    const featuredImageNoImageNotice = featuredImagePreviewContainer.find('.no-image-notice');

        // Store initial src if present, to revert if file selection is cancelled
        if (featuredImagePreviewImg.attr('src') && !featuredImagePreviewImg.data('initial-src')) {
            featuredImagePreviewImg.data('initial-src', featuredImagePreviewImg.attr('src'));
        }

        featuredImageInput.on('change.artistImagePreview', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    featuredImagePreviewImg.attr('src', e.target.result).show();
                    if (featuredImageNoImageNotice.length) featuredImageNoImageNotice.hide();
                }
                reader.readAsDataURL(file);
            } else {
                const initialSrc = featuredImagePreviewImg.data('initial-src');
                if (initialSrc) {
                    featuredImagePreviewImg.attr('src', initialSrc).show();
                    if (featuredImageNoImageNotice.length) featuredImageNoImageNotice.hide();
                } else {
                    featuredImagePreviewImg.attr('src', '').hide();
                    if (featuredImageNoImageNotice.length) featuredImageNoImageNotice.show();
                }
            }
        }).data('previewInitialized', true); // Mark as initialized
        
        // Initial state check for existing image to show preview or notice
        // This part is tricky if the element is initially hidden; state might not be as expected.
        // However, data('initial-src') should capture it if src was there on load.
        // const initialSrcOnLoad = featuredImagePreviewImg.data('initial-src');
        // if (initialSrcOnLoad) {
        //     featuredImagePreviewImg.attr('src', initialSrcOnLoad).show();
        //      if (featuredImageNoImageNotice.length) featuredImageNoImageNotice.hide();
        // } else if (!featuredImagePreviewImg.attr('src')) { // If no src at all (even after trying initial)
        //     if (featuredImageNoImageNotice.length) featuredImageNoImageNotice.show();
        //     featuredImagePreviewImg.hide();
        // }
    }
} 