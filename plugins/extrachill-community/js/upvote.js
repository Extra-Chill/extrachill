jQuery(document).ready(function($) {
    // Attach the event listener to a static parent element.
    // Using 'body' for broad compatibility, but you can choose a closer parent container.
    $('body').on('click', '.upvote-icon', function() {
        if (!extrachill_ajax.is_user_logged_in) {
            window.location.href = '/login'; // Redirect to the login page
            return; // Stop further execution
        }

        var $this = $(this);
        var post_id = $this.data('post-id');
        var post_type = $this.data('type'); // 'topic' or 'reply'
        var nonce = $this.data('nonce');

        if (!post_id || !nonce || !post_type) {
            console.error('Post ID, nonce, or post type is missing.');
            return;
        }

        var isUpvoted = $this.find('i').hasClass('fa-solid');
        var $countSpan = $this.closest('.upvote').find('.upvote-count');
        var currentCount = parseInt($countSpan.text(), 10) || 0;
        var newCount = isUpvoted ? currentCount - 1 : currentCount + 1;
        $countSpan.text(newCount);

        $.ajax({
            url: extrachill_ajax.ajaxurl,
            type: 'post',
            data: {
                action: 'handle_upvote',
                post_id: post_id,
                type: post_type,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $countSpan.text(response.data.new_count);
                    $this.find('i').toggleClass('fa-solid fa-regular');
                } else {
                    console.error('Error: ' + response.data.message);
                    $countSpan.text(currentCount); // Revert to original count on failure
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error: ' + textStatus + ', ' + errorThrown);
                $countSpan.text(currentCount); // Revert to original count on AJAX error
            }
        });
    });
});
