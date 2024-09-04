jQuery(document).ready(function($) {
    $('body').on('click', '.upvote-icon', function() {
        var isLoggedIn = extrachillUpvote1494.is_user_logged_in;

        if (!isLoggedIn) {
            window.location.href = '/login';
            return;
        }

        var $this = $(this);
        var post_id = $this.data('post-id');
        var post_type = $this.data('type');
        var nonce = $this.data('nonce');
        var community_user_id = extrachillUpvote1494.user_id;
        var main_site_post_id = null;

        // Check if the post type is a reply or topic
        if (post_type === 'reply') {
            main_site_post_id = post_id; // For replies, use post_id as main_site_post_id
        } else {
            main_site_post_id = $this.data('main-site-post-id'); // For topics, use the provided main_site_post_id
        }

        // Validate required data
        if (!post_id || !nonce || !post_type || (post_type !== 'reply' && !main_site_post_id)) {
            console.error('Post ID, nonce, post type, or main site post ID is missing.');
            return;
        }

        var isUpvoted = $this.find('i').hasClass('fa-solid');
        var upvote_action = isUpvoted ? 'remove_upvote' : 'upvote'; // This will convey the user's actual intention
        var $countSpan = $this.closest('.upvote').find('.upvote-count');
        var currentCount = parseInt($countSpan.text(), 10) || 0;

        // Optimistically update UI
        var updatedCount = isUpvoted ? currentCount - 1 : currentCount + 1;
        $countSpan.text(updatedCount);
        $this.find('i').toggleClass('fa-solid fa-regular');

        var ajaxUrl = extrachillUpvote1494.ajaxurl;

        var ajaxData = {
            action: 'handle_forum_1494_upvote', // WordPress action hook
            upvote_action: upvote_action, // Conveying the user's intention
            post_id: post_id,
            main_site_post_id: main_site_post_id,
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
