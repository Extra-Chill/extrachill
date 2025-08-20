jQuery(document).ready(function($) {
    $('body').on('click', '.upvote-icon', function() {
        if (!extrachill_ajax.is_user_logged_in) {
            window.location.href = '/login';
            return;
        }

        var $this = $(this);
        var post_id = $this.data('post-id'); 
        var post_type = $this.data('type');
        var nonce = $this.data('nonce');
        var community_user_id = extrachill_ajax.user_id; // Assuming you have the user ID available

        if (!post_id || !nonce || !post_type) {
            console.error('Post ID, nonce, or post type is missing.');
            return;
        }

        var isUpvoted = $this.find('i').hasClass('fa-solid');
        var action = isUpvoted ? 'remove_upvote' : 'upvote';
        var $countSpan = $this.closest('.upvote').find('.upvote-count');
        var currentCount = parseInt($countSpan.text(), 10) || 0;

        // Optimistically update UI
        var updatedCount = isUpvoted ? currentCount - 1 : currentCount + 1;
        $countSpan.text(updatedCount);
        $this.find('i').toggleClass('fa-solid fa-regular');

        var ajaxUrl = extrachill_ajax.ajaxurl; // Use the local AJAX URL for handling upvote
        var ajaxData = {
            action: 'handle_upvote', // Action for handling local upvotes
            post_id: post_id,
            type: post_type,
            nonce: nonce,
            community_user_id: community_user_id
        };

        // Execute AJAX request
        $.ajax({
            url: ajaxUrl,
            type: 'post',
            data: ajaxData,
            success: function(response) {
                if (!response.success) {
                    // Revert UI changes if the action was not successful
                    $countSpan.text(currentCount);
                    $this.find('i').toggleClass('fa-solid fa-regular');
                    console.error('Error: ' + response.data.message);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Revert UI changes on AJAX error
                $countSpan.text(currentCount);
                $this.find('i').toggleClass('fa-solid fa-regular');
                console.error('AJAX error: ' + textStatus + ', ' + errorThrown);
            }
        });
    });
});
