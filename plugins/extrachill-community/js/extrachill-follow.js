/* follow button */

jQuery(document).ready(function($) {
    $(document).on('click', '.extrachill-follow-button', function() {
        var button = $(this);
        var userId = button.data('user-id');
        var action = button.data('action');
        var nonce = button.data('nonce'); // Get nonce from the button

        $.ajax({
            url: extrachill_ajax.ajaxurl,
            type: 'post',
            data: {
                action: 'extrachill_' + action + '_user',
                followed_id: userId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Toggle button text and data-action attribute
                    var newText = action === 'follow' ? 'Following' : 'Follow';
                    var newAction = action === 'follow' ? 'unfollow' : 'follow';
                    button.text(newText).data('action', newAction);
                } else {
                    console.error('Error:', response);
                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Please try again.'));
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                alert('AJAX request failed: ' + textStatus);
            }
        });
    });
});