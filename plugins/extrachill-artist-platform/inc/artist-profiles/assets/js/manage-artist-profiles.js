/**
 * JavaScript for handling the Artist Members management section on the frontend manage page.
 */
(function() {
    'use strict';

    const config = typeof apManageMembersData !== 'undefined' ? apManageMembersData : null;
    const restUrl = config ? config.restUrl : null;
    const artistProfileId = config ? config.artistProfileId : null;
    const i18n = config ? config.i18n : {};

    const initializedTabs = new Set();

    document.addEventListener('DOMContentLoaded', function() {
        if (!config) {
            console.error('apManageMembersData is not defined. Ensure it is localized.');
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
            if (target.matches('#bp-send-invite-member-button') || target.closest('#bp-send-invite-member-button')) {
                e.preventDefault();
                const button = target.matches('#bp-send-invite-member-button') ? target : target.closest('#bp-send-invite-member-button');
                const newMemberEmailInput = rosterTabContentElement.querySelector('#bp-new-member-email-input');
                const inviteEmail = newMemberEmailInput ? newMemberEmailInput.value.trim() : '';

                if (!inviteEmail) {
                    alert(i18n.enterEmail || 'Please enter an email address.');
                    if (newMemberEmailInput) newMemberEmailInput.focus();
                    return;
                }

                button.disabled = true;
                button.textContent = i18n.sendingInvitation || 'Sending...';

                fetch(`${restUrl}/artist/roster/invite`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        artist_id: artistProfileId,
                        email: inviteEmail
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => Promise.reject(err));
                    }
                    return response.json();
                })
                .then(function(data) {
                    const unifiedRosterList = rosterTabContentElement.querySelector(unifiedRosterListSelector);
                    const noMembers = unifiedRosterList ? unifiedRosterList.querySelector('.no-members') : null;
                    if (noMembers) noMembers.remove();

                    if (unifiedRosterList && data.invitation && data.invitation.user_id) {
                        const rosterItemHtml = renderRosterItem(data.invitation);
                        unifiedRosterList.insertAdjacentHTML('beforeend', rosterItemHtml);
                    }

                    if (newMemberEmailInput) {
                        newMemberEmailInput.value = '';
                        newMemberEmailInput.focus();
                    }
                })
                .catch(function(error) {
                    alert('Error: ' + (error.message || i18n.errorSendingInvitation || 'Could not send invitation.'));
                })
                .finally(function() {
                    button.disabled = false;
                    button.textContent = i18n.sendInvitation || 'Send Invitation';
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

    /**
     * Renders a roster item from REST API response data
     */
    function renderRosterItem(data) {
        const displayName = data.display_name || data.email;
        const avatarUrl = data.avatar_url || '';
        const profileUrl = data.profile_url || '#';
        const status = data.status || 'pending';
        const userId = data.user_id || '';

        let statusLabel = '';
        if (status === 'pending') {
            statusLabel = '<span class="member-status-label pending">(Invitation Pending)</span>';
        }

        let avatarHtml = '';
        if (avatarUrl) {
            avatarHtml = `<img src="${avatarUrl}" alt="${displayName}" class="roster-member-avatar" width="40" height="40">`;
        }

        return `
            <li class="roster-member-item" data-user-id="${userId}" data-status="${status}">
                <div class="roster-member-info">
                    ${avatarHtml}
                    <a href="${profileUrl}" class="member-name" target="_blank">${displayName}</a>
                    ${statusLabel}
                </div>
                <button type="button" class="bp-remove-member-button button-link-delete" title="Remove member">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </li>
        `;
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
