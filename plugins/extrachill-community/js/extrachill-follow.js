jQuery(document).ready(function($) {

    // Check if follow data is localized
    if (typeof bpFollowData === 'undefined') {
        console.error('bpFollowData is not defined. Ensure it is localized.');
        // Don't necessarily return, other scripts might be in this file.
        // Instead, just don't attach the handler.
    } else {
        // Band Follow Button Click Handler
        $(document).on('click', '.bp-follow-band-button', function() {
            var button = $(this);
            var bandId = button.data('band-id');
            var action = button.data('action'); // 'follow' or 'unfollow'
            var nonce = bpFollowData.nonce; // Use nonce from localized data
            var ajaxUrl = bpFollowData.ajaxUrl; // Use AJAX URL from localized data

            if (!bandId || !action || !nonce || !ajaxUrl) {
                console.error('Missing data for follow action', { bandId, action, nonce, ajaxUrl });
                alert('Could not perform action due to missing data.');
                return;
            }

            // Optimistic UI update
            var originalText = button.text();
            var newText = action === 'follow' ? 'Following' : 'Follow';
            var newAction = action === 'follow' ? 'unfollow' : 'follow';
            button.text('Processing...').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'post',
                data: {
                    action: 'bp_toggle_follow_band', // New AJAX action
                    band_id: bandId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        // Update button state based on response
                        button.text(response.data.new_state === 'following' ? 'Following' : 'Follow');
                        button.data('action', response.data.new_state === 'following' ? 'unfollow' : 'follow');
                        
                        // Update follower count
                        var $count = $('#band-follower-count-' + bandId);
                        if ($count.length && response.data.new_count_formatted) {
                            $count.text(response.data.new_count_formatted);
                        }
                        
                        console.log('Follow status updated:', response.data.new_state);
                    } else {
                        // Revert optimistic update on failure
                        button.text(originalText);
                        alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Could not update follow status.'));
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Revert optimistic update on AJAX error
                    button.text(originalText);
                    console.error('AJAX Error:', textStatus, errorThrown);
                    alert('AJAX request failed: ' + textStatus);
                },
                complete: function() {
                    // Re-enable button
                    button.prop('disabled', false);
                }
            });
        });
    }

    // --- Old User Follow Logic (Commented Out) ---
    /*
    $(document).on('click', '.extrachill-follow-button', function() {
        var button = $(this);
        var userId = button.data('user-id');
        var action = button.data('action');
        var nonce = button.data('nonce'); // Ensure nonce is correctly set

        // Target all buttons for this user
        var allButtons = $('.extrachill-follow-button[data-user-id="' + userId + '"]');

        // Optimistically update the text and action for all targeted buttons
        var newText = action === 'follow' ? 'Following' : 'Follow';
        var newAction = action === 'follow' ? 'unfollow' : 'follow';
        allButtons.text(newText).data('action', newAction);

        $.ajax({
            url: extrachill_ajax.ajaxurl,
            type: 'post',
            data: {
                action: 'extrachill_' + action + '_user',
                followed_id: userId,
                nonce: nonce
            },
            success: function(response) {
                if (!response || typeof response.success === 'undefined') {
                    console.error('Invalid response:', response);
                    alert('An unexpected error occurred. Please try again.');

                    // Revert button states
                    var revertText = newAction === 'follow' ? 'Following' : 'Follow';
                    var revertAction = newAction === 'follow' ? 'unfollow' : 'follow';
                    allButtons.text(revertText).data('action', revertAction);
                    return;
                }

                if (response.success) {
                    console.log('Success:', response);
                } else {
                    console.error('Error in response:', response);
                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Please try again.'));

                    // Revert button states
                    var revertText = newAction === 'follow' ? 'Following' : 'Follow';
                    var revertAction = newAction === 'follow' ? 'unfollow' : 'follow';
                    allButtons.text(revertText).data('action', revertAction);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                alert('AJAX request failed: ' + textStatus);

                // Revert button states
                var revertText = newAction === 'follow' ? 'Following' : 'Follow';
                var revertAction = newAction === 'follow' ? 'unfollow' : 'follow';
                allButtons.text(revertText).data('action', revertAction);
            }
        });
    });
    */
});
