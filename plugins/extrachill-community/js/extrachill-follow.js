jQuery(document).ready(function($) {
    $(document).on('click', '.extrachill-follow-button', function() {
        var button = $(this);
        var userId = button.data('user-id');
        var action = button.data('action');
        var nonce = button.data('nonce'); // Get nonce from the button

        // Target all buttons for this user, not just the one clicked
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
                if (!response.success) {
                    // If the AJAX call fails, revert the state of all targeted buttons
                    var revertText = newAction === 'follow' ? 'Following' : 'Follow';
                    var revertAction = newAction === 'follow' ? 'unfollow' : 'follow';
                    allButtons.text(revertText).data('action', revertAction);

                    console.error('Error:', response);
                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Please try again.'));
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // If AJAX itself fails, revert the state of all targeted buttons
                var revertText = newAction === 'follow' ? 'Following' : 'Follow';
                var revertAction = newAction === 'follow' ? 'unfollow' : 'follow';
                allButtons.text(revertText).data('action', revertAction);

                console.error('AJAX Error:', textStatus, errorThrown);
                alert('AJAX request failed: ' + textStatus);
            }
        });
    });
});
