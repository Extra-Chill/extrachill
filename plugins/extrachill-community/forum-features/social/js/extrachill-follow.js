jQuery(document).ready(function($) {

    // Check if follow data is localized
    if (typeof bpFollowData === 'undefined') {
        console.error('bpFollowData is not defined. Ensure it is localized.');
        // Don't necessarily return, other scripts might be in this file.
        // Instead, just don't attach the handler.
    } else {
        // Artist Follow Button Click Handler
        $(document).on('click', '.bp-follow-artist-button', function(e) {
            e.preventDefault(); // Prevent default button action, we'll handle it

            var button = $(this);
            var artistId = button.data('artist-id');
            var currentAction = button.data('action'); // 'follow' or 'unfollow'
            var nonce = bpFollowData.nonce; // Use nonce from localized data
            var ajaxUrl = bpFollowData.ajaxUrl; // Use AJAX URL from localized data
            var artistName = button.closest('.artist-profile-details, .artist-hero-content, .artist-card-content').find('.entry-title, .artist-hero-title, .artist-card-title a').first().text().trim();
            if (!artistName) {
                artistName = "this artist"; // Fallback artist name
            }


            if (!artistId || !currentAction || !nonce || !ajaxUrl) {
                console.error('Missing data for follow action', { artistId, currentAction, nonce, ajaxUrl });
                alert('Could not perform action due to missing data.');
                return;
            }

            function performAjaxRequest(shareConsent) {
                // Optimistic UI update
                var originalText = button.text();
                // For 'follow' action, text changes to 'Following' if successful. For 'unfollow', to 'Follow'.
                var newText = currentAction === 'follow' ? bpFollowData.i18n.following : bpFollowData.i18n.follow;
                var newAction = currentAction === 'follow' ? 'unfollow' : 'follow';
                
                button.text(bpFollowData.i18n.processing).prop('disabled', true);

                var ajaxData = {
                    action: 'bp_toggle_follow_artist', // AJAX action
                    artist_id: artistId,
                    nonce: nonce,
                    // current_action: currentAction // Let backend derive from is_following for robustness
                };

                if (currentAction === 'follow') {
                    ajaxData.share_email_consent = shareConsent;
                }

                $.ajax({
                    url: ajaxUrl,
                    type: 'post',
                    data: ajaxData,
                    success: function(response) {
                        if (response.success && response.data) {
                            // Update button state based on response
                            button.text(response.data.new_state === 'following' ? bpFollowData.i18n.following : bpFollowData.i18n.follow);
                            button.data('action', response.data.new_state === 'following' ? 'unfollow' : 'follow');
                            
                            // Update follower count
                            var $count = $('#artist-follower-count-' + artistId + ', .artist-follower-count[data-artist-id=\"' + artistId + '\"]');
                            if ($count.length && typeof response.data.new_count_formatted !== 'undefined') {
                                $count.text(response.data.new_count_formatted);
                            }
                            
                            console.log('Follow status updated:', response.data.new_state);
                        } else {
                            // Revert optimistic update on failure
                            button.text(originalText);
                            // Restore original action
                            button.data('action', currentAction); 
                            alert('Error: ' + (response.data && response.data.message ? response.data.message : bpFollowData.i18n.errorMessage));
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // Revert optimistic update on AJAX error
                        button.text(originalText);
                        button.data('action', currentAction);
                        console.error('AJAX Error:', textStatus, errorThrown);
                        alert(bpFollowData.i18n.ajaxRequestFailed + ': ' + textStatus);
                    },
                    complete: function() {
                        // Re-enable button
                        button.prop('disabled', false);
                        // Remove any existing modal
                        $('#bp-follow-consent-modal').remove();
                        $('.bp-follow-modal-backdrop').remove();
                    }
                });
            }

            if (currentAction === 'follow') {
                // Remove any existing modal first
                $('#bp-follow-consent-modal').remove();
                $('.bp-follow-modal-backdrop').remove();

                // Create and show modal for 'follow' action
                var modalHTML = 
                    '<div class="bp-follow-modal-backdrop"></div>' +
                    '<div id="bp-follow-consent-modal" class="bp-modal">' +
                        '<div class="bp-modal-content">' +
                            '<h3 class="bp-modal-title">Follow ' + artistName + '?</h3>' +
                            '<p>Following adds this artist\'s forum activity to your \'Following Feed\'. Manage all followed artists in your account settings.</p>' +
                            '<div class="bp-modal-consent-option">' +
                                '<label for="bp_share_email_consent">' +
                                    '<input type="checkbox" id="bp_share_email_consent" name="bp_share_email_consent" checked>' +
                                    ' Share my email with ' + artistName + ' for their direct updates.' +
                                '</label>' +
                            '</div>' +
                            '<div class="bp-modal-actions">' +
                                '<button type="button" class="button bp-modal-confirm" id="bp_confirm_follow_btn">' + (bpFollowData.i18n.confirmFollow || 'Confirm Follow') + '</button>' +
                                '<button type="button" class="button bp-modal-cancel" id="bp_cancel_follow_btn">' + (bpFollowData.i18n.cancel || 'Cancel') + '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                $('body').append(modalHTML);

                $('#bp_confirm_follow_btn').on('click', function() {
                    var shareConsent = $('#bp_share_email_consent').is(':checked');
                    performAjaxRequest(shareConsent);
                });

                $('#bp_cancel_follow_btn, .bp-follow-modal-backdrop').on('click', function() {
                    $('#bp-follow-consent-modal').remove();
                    $('.bp-follow-modal-backdrop').remove();
                });

            } else { // 'unfollow' action
                performAjaxRequest(null); // No consent needed for unfollow
            }
        });
    }

});
