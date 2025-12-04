/**
 * JavaScript for handling the Artist Members management section on the frontend manage page.
 */
(function() {
    'use strict';

    const ajaxUrl = typeof bpManageMembersData !== 'undefined' ? bpManageMembersData.ajaxUrl : null;
    const artistProfileId = typeof bpManageMembersData !== 'undefined' ? bpManageMembersData.artistProfileId : null;
    const ajaxAddNonce = typeof bpManageMembersData !== 'undefined' ? (bpManageMembersData.ajaxAddNonce || '') : '';
    const ajaxRemovePlaintextNonce = typeof bpManageMembersData !== 'undefined' ? (bpManageMembersData.ajaxRemovePlaintextNonce || '') : '';

    const initializedTabs = new Set();

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof bpManageMembersData === 'undefined') {
            console.error('bpManageMembersData is not defined. Ensure it is localized.');
        }

        initializeArtistImagePreviews(document);
        initArtistSwitcher();
    });

    function initializeProfileManagersTabEventListeners(rosterTabContentElement) {
        if (!rosterTabContentElement) {
            console.warn('Roster tab content element not found when trying to init listeners.');
            return;
        }

        if (rosterTabContentElement.dataset.rosterListenersInitialized) {
            return;
        }

        const unifiedRosterListSelector = '#bp-unified-roster-list';
        const hiddenRemoveUserIdsInputSelector = '#bp-remove-member-ids-frontend';
        let membersToRemove = [];

        const hiddenRemoveUserIdsInput = rosterTabContentElement.querySelector(hiddenRemoveUserIdsInputSelector);
        if (hiddenRemoveUserIdsInput) {
            updateHiddenFormFields();
        }

        function updateHiddenFormFields() {
            const uniqueMembersToRemoveIds = [...new Set(membersToRemove)];
            if (hiddenRemoveUserIdsInput) {
                hiddenRemoveUserIdsInput.value = uniqueMembersToRemoveIds.join(',');
            }
        }

        rosterTabContentElement.addEventListener('click', function(e) {
            const target = e.target;

            // Show add member form
            if (target.matches('#bp-show-add-member-form-link') || target.closest('#bp-show-add-member-form-link')) {
                e.preventDefault();
                const link = target.matches('#bp-show-add-member-form-link') ? target : target.closest('#bp-show-add-member-form-link');
                const addMemberFormArea = rosterTabContentElement.querySelector('#bp-add-member-form-area');
                const newMemberEmailInput = rosterTabContentElement.querySelector('#bp-new-member-email-input');
                if (addMemberFormArea) {
                    addMemberFormArea.style.display = 'block';
                }
                link.style.display = 'none';
                if (newMemberEmailInput) newMemberEmailInput.focus();
                return;
            }

            // Cancel add member form
            if (target.matches('#bp-cancel-add-member-form-link') || target.closest('#bp-cancel-add-member-form-link')) {
                e.preventDefault();
                const addMemberFormArea = rosterTabContentElement.querySelector('#bp-add-member-form-area');
                const newMemberEmailInput = rosterTabContentElement.querySelector('#bp-new-member-email-input');
                const showAddMemberFormLink = rosterTabContentElement.querySelector('#bp-show-add-member-form-link');
                if (addMemberFormArea) addMemberFormArea.style.display = 'none';
                if (newMemberEmailInput) newMemberEmailInput.value = '';
                if (showAddMemberFormLink) showAddMemberFormLink.style.display = '';
                return;
            }

            // Invite member by email
            if (target.matches('#bp-ajax-invite-member-button') || target.closest('#bp-ajax-invite-member-button')) {
                e.preventDefault();
                const button = target.matches('#bp-ajax-invite-member-button') ? target : target.closest('#bp-ajax-invite-member-button');
                const newMemberEmailInput = rosterTabContentElement.querySelector('#bp-new-member-email-input');
                const inviteEmail = newMemberEmailInput ? newMemberEmailInput.value.trim() : '';

                if (!inviteEmail) {
                    alert('Please enter an email address.');
                    if (newMemberEmailInput) newMemberEmailInput.focus();
                    return;
                }

                button.disabled = true;
                button.textContent = 'Sending...';

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'bp_ajax_invite_member_by_email',
                        artist_id: artistProfileId,
                        invite_email: inviteEmail,
                        nonce: bpManageMembersData.ajaxInviteMemberByEmailNonce
                    })
                })
                .then(response => response.json())
                .then(function(response) {
                    if (response.success && response.data && response.data.updated_roster_item_html) {
                        const unifiedRosterList = rosterTabContentElement.querySelector(unifiedRosterListSelector);
                        const noMembers = unifiedRosterList ? unifiedRosterList.querySelector('.no-members') : null;
                        if (noMembers) noMembers.remove();
                        if (unifiedRosterList) {
                            unifiedRosterList.insertAdjacentHTML('beforeend', response.data.updated_roster_item_html);
                        }
                        if (newMemberEmailInput) {
                            newMemberEmailInput.value = '';
                            newMemberEmailInput.focus();
                        }
                    } else {
                        alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Could not send invitation.'));
                    }
                })
                .catch(function() {
                    alert('An error occurred while sending the invitation. Please try again.');
                })
                .finally(function() {
                    button.disabled = false;
                    button.textContent = 'Send Invitation';
                });
                return;
            }

            // Remove plaintext member
            if (target.matches('.bp-ajax-remove-plaintext-member') || target.closest('.bp-ajax-remove-plaintext-member')) {
                e.preventDefault();
                const link = target.matches('.bp-ajax-remove-plaintext-member') ? target : target.closest('.bp-ajax-remove-plaintext-member');
                const listItem = link.closest('li');
                const plaintextId = link.dataset.ptid;
                const memberNameEl = listItem ? listItem.querySelector('.member-name') : null;
                const memberName = memberNameEl ? memberNameEl.textContent : '';

                if (!plaintextId || !confirm(`Are you sure you want to remove "${memberName}" from the roster listing?`)) return;

                listItem.style.opacity = '0.5';

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'bp_ajax_remove_plaintext_member_action',
                        artist_id: artistProfileId,
                        plaintext_member_id: plaintextId,
                        nonce: ajaxRemovePlaintextNonce
                    })
                })
                .then(response => response.json())
                .then(function(response) {
                    if (response.success) {
                        listItem.style.transition = 'opacity 0.3s';
                        listItem.style.opacity = '0';
                        setTimeout(function() {
                            listItem.remove();
                            const actualUnifiedRosterList = rosterTabContentElement.querySelector(unifiedRosterListSelector);
                            if (actualUnifiedRosterList) {
                                const remainingItems = actualUnifiedRosterList.querySelectorAll('li:not(.no-members)');
                                if (remainingItems.length === 0) {
                                    actualUnifiedRosterList.insertAdjacentHTML('beforeend', '<li class="no-members">No members listed for this artist yet.</li>');
                                }
                            }
                        }, 300);
                    } else {
                        alert('Error: ' + (response.data || 'Could not remove listing.'));
                        listItem.style.opacity = '1';
                    }
                })
                .catch(function() {
                    alert('An error occurred. Please try again.');
                    listItem.style.opacity = '1';
                });
                return;
            }

            // Mark member for removal
            if (target.matches('.bp-remove-member-button') || target.closest('.bp-remove-member-button')) {
                e.preventDefault();
                const button = target.matches('.bp-remove-member-button') ? target : target.closest('.bp-remove-member-button');
                const listItem = button.closest('li');
                const userIdToRemove = listItem ? listItem.dataset.userId : null;

                if (!userIdToRemove) return;

                if (!listItem.classList.contains('marked-for-removal')) {
                    if (!membersToRemove.includes(userIdToRemove)) {
                        membersToRemove.push(userIdToRemove);
                    }
                    listItem.style.opacity = '0.5';
                    listItem.classList.add('marked-for-removal');
                    button.style.display = 'none';

                    let statusLabel = listItem.querySelector('.member-status-label');
                    if (!statusLabel) {
                        const memberNameParent = listItem.querySelector('.member-name');
                        if (memberNameParent && memberNameParent.parentElement) {
                            statusLabel = document.createElement('span');
                            statusLabel.className = 'member-status-label';
                            memberNameParent.parentElement.appendChild(statusLabel);
                        }
                    }
                    if (statusLabel) {
                        const removalText = document.createElement('em');
                        removalText.className = 'temp-removal-text';
                        removalText.textContent = ' (Marked for removal)';
                        statusLabel.appendChild(removalText);
                    }
                }

                if (hiddenRemoveUserIdsInput) updateHiddenFormFields();
                return;
            }
        });

        rosterTabContentElement.dataset.rosterListenersInitialized = 'true';
    }

    // Listen for managers tab activation
    document.addEventListener('artistManagersTabActivated', function(event) {
        if (event.detail && event.detail.tabPaneElement) {
            const tabId = event.detail.tabId;
            const tabPaneElement = event.detail.tabPaneElement;

            if (tabId === 'manage-artist-profile-managers-content') {
                if (!initializedTabs.has(tabId)) {
                    initializeProfileManagersTabEventListeners(tabPaneElement);
                    initializedTabs.add(tabId);
                }
            }

            initializeArtistImagePreviews(tabPaneElement);
        }
    });

    // Listen for info tab activation for image previews
    document.addEventListener('artistInfoTabActivated', function(event) {
        if (event.detail && event.detail.tabPaneElement) {
            initializeArtistImagePreviews(event.detail.tabPaneElement);
        }
    });

    function initArtistSwitcher() {
        const artistSwitcherSelect = document.getElementById('artist-switcher-select');
        if (artistSwitcherSelect) {
            artistSwitcherSelect.addEventListener('change', function() {
                const selectedArtistId = this.value;
                if (selectedArtistId && selectedArtistId !== '') {
                    let currentUrl = window.location.href.split('?')[0];
                    window.location.href = currentUrl + '?artist_id=' + selectedArtistId;
                }
            });
        }
    }

})();

/**
 * Initialize Image Previews for artist profile images
 */
function initializeArtistImagePreviews(contextElement) {
    const context = contextElement || document;

    // Header Image Preview
    const headerImageInput = context.querySelector('#artist_header_image');
    const headerImagePreviewContainer = context.querySelector('#artist-header-image-preview-container');

    if (headerImageInput && headerImagePreviewContainer && !headerImageInput.dataset.previewInitialized) {
        const headerImagePreviewImg = headerImagePreviewContainer.querySelector('#artist-header-image-preview-img');
        const headerImageNoImageNotice = headerImagePreviewContainer.querySelector('.no-image-notice');

        headerImageInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (headerImagePreviewImg) {
                        headerImagePreviewImg.src = e.target.result;
                        headerImagePreviewImg.style.display = '';
                    }
                    if (headerImageNoImageNotice) {
                        headerImageNoImageNotice.style.display = 'none';
                    }
                };
                reader.readAsDataURL(file);
            } else {
                const currentSrc = headerImagePreviewImg ? headerImagePreviewImg.src : '';
                if (!currentSrc || currentSrc.startsWith('data:image')) {
                    if (headerImagePreviewImg) {
                        headerImagePreviewImg.src = '';
                        headerImagePreviewImg.style.display = 'none';
                    }
                    if (headerImageNoImageNotice) {
                        headerImageNoImageNotice.style.display = '';
                    }
                }
            }
        });

        headerImageInput.dataset.previewInitialized = 'true';
    }

    // Featured Image (Profile Picture) Preview
    const featuredImageInput = context.querySelector('#featured_image');
    const featuredImagePreviewContainer = context.querySelector('#featured-image-preview-container');

    if (featuredImageInput && featuredImagePreviewContainer && !featuredImageInput.dataset.previewInitialized) {
        const featuredImagePreviewImg = featuredImagePreviewContainer.querySelector('#featured-image-preview-img');
        const featuredImageNoImageNotice = featuredImagePreviewContainer.querySelector('.no-image-notice');

        // Store initial src if present
        if (featuredImagePreviewImg && featuredImagePreviewImg.src && !featuredImagePreviewImg.dataset.initialSrc) {
            featuredImagePreviewImg.dataset.initialSrc = featuredImagePreviewImg.src;
        }

        featuredImageInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (featuredImagePreviewImg) {
                        featuredImagePreviewImg.src = e.target.result;
                        featuredImagePreviewImg.style.display = '';
                    }
                    if (featuredImageNoImageNotice) {
                        featuredImageNoImageNotice.style.display = 'none';
                    }
                };
                reader.readAsDataURL(file);
            } else {
                const initialSrc = featuredImagePreviewImg ? featuredImagePreviewImg.dataset.initialSrc : '';
                if (initialSrc) {
                    featuredImagePreviewImg.src = initialSrc;
                    featuredImagePreviewImg.style.display = '';
                    if (featuredImageNoImageNotice) featuredImageNoImageNotice.style.display = 'none';
                } else {
                    if (featuredImagePreviewImg) {
                        featuredImagePreviewImg.src = '';
                        featuredImagePreviewImg.style.display = 'none';
                    }
                    if (featuredImageNoImageNotice) featuredImageNoImageNotice.style.display = '';
                }
            }
        });

        featuredImageInput.dataset.previewInitialized = 'true';
    }
}
