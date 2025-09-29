/**
 * Upvote System AJAX Handler
 *
 * Handles upvoting/downvoting of forum topics and replies with optimistic UI updates
 * and server-side validation.
 */

jQuery(document).ready(function($) {
    $('body').on('click', '.upvote-icon', function(e) {
        e.preventDefault();
        e.stopPropagation();
        

        var $this = $(this);
        var post_id = $this.data('post-id'); 
        var post_type = $this.data('type');
        var nonce = extrachill_ajax.nonce;
        var community_user_id = extrachill_ajax.user_id;

        if (!post_id || !nonce || !post_type) {
            console.error('Post ID, nonce, or post type is missing.');
            console.log('Debug info:', {post_id: post_id, nonce: nonce, post_type: post_type});
            return;
        }

        var isUpvoted = $this.find('i').hasClass('fa-solid');
        var action = isUpvoted ? 'remove_upvote' : 'upvote';
        var $countSpan = $this.closest('.upvote').find('.upvote-count');
        var currentCount = parseInt($countSpan.text(), 10) || 0;

        var updatedCount = isUpvoted ? currentCount - 1 : currentCount + 1;
        $countSpan.text(updatedCount);
        $this.find('i').toggleClass('fa-solid fa-regular');

        var ajaxUrl = extrachill_ajax.ajaxurl;
        var ajaxData = {
            action: 'handle_upvote',
            post_id: post_id,
            type: post_type,
            nonce: nonce,
            community_user_id: community_user_id
        };

        $.ajax({
            url: ajaxUrl,
            type: 'post',
            data: ajaxData,
            success: function(response) {
                if (!response.success) {
                    $countSpan.text(currentCount);
                    $this.find('i').toggleClass('fa-solid fa-regular');
                    console.error('Error: ' + response.data.message);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $countSpan.text(currentCount);
                $this.find('i').toggleClass('fa-solid fa-regular');
                console.error('AJAX error: ' + textStatus + ', ' + errorThrown);
            }
        });
    });
});
