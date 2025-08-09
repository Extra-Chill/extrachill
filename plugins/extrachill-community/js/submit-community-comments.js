jQuery(document).ready(function($) {
    $(document).on('submit', '#community-comment-form form', function(e) {
        e.preventDefault(); // Prevent default form submission

        var formData = {
            'post_id': $('#community-comment-form').data('post-id'), // Assuming post ID is stored in data attribute
            'author': $('#community-comment-form').data('username'), // Assuming username is stored in data attribute
            'email': $('#community-comment-form').data('email'), // Assuming email is stored in data attribute
            'comment': $('#comment').val(), // The actual comment text
            'comment_nonce': $('#comment_nonce').val() // Security nonce
        };

        // AJAX request to submit the comment
        $.ajax({
            url: 'https://staging.extrachill.com/wp-json/extrachill/v1/community-comment', // Adjust if necessary
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                // Display a success message below the submit button
                $('#community-comment-form form').find('.comment-submission-message').remove(); // Remove any existing message
                $('<div class="comment-submission-message" style="color: green;">Comment submitted successfully!</div>').insertAfter('#community-comment-form form button[type="submit"]');
                $('#community-comment-form form')[0].reset(); // Clear the form
            },
            error: function(xhr, status, error) {
                // Display an error message below the submit button
                $('#community-comment-form form').find('.comment-submission-message').remove(); // Remove any existing message
                $('<div class="comment-submission-message" style="color: red;">Comment submission failed. Please try again later.</div>').insertAfter('#community-comment-form form button[type="submit"]');
                console.error('Comment submission error:', error);
            }
        });
    });
});
