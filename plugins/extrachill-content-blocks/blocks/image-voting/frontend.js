jQuery(document).ready(function($) {
    // Handle vote button clicks
    $('.extrachill-blocks-image-voting-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest('.extrachill-blocks-image-voting-container');
        const $form = $container.find('.extrachill-blocks-image-voting-form');

        // Show email form
        $form.show();
        $button.hide();
    });

    // Handle vote submission
    $('.extrachill-blocks-submit-vote').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest('.extrachill-blocks-image-voting-container');
        const $emailInput = $container.find('.extrachill-blocks-email-input');
        const $voteCount = $container.find('.vote-number');
        const $form = $container.find('.extrachill-blocks-image-voting-form');

        const email = $emailInput.val().trim();
        const instanceId = $container.data('block-instance-id');
        const postId = $container.data('post-id');

        if (!email) {
            alert('Please enter your email address.');
            return;
        }

        if (!isValidEmail(email)) {
            alert('Please enter a valid email address.');
            return;
        }

        if (!instanceId || !postId) {
            alert('Error: Missing block or post information.');
            return;
        }

        // Disable button during submission
        $button.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: extraChillBlocksImageVoting.ajaxurl,
            type: 'POST',
            data: {
                action: 'extrachill_blocks_image_vote',
                post_id: postId,
                instance_id: instanceId,
                email_address: email,
                nonce: extraChillBlocksImageVoting.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update vote count
                    $voteCount.text(response.data.vote_count);

                    // Hide form and show thank you message
                    $form.hide();
                    $container.append('<p class="extrachill-blocks-vote-success">Thank you for voting!</p>');
                } else {
                    alert('Error: ' + response.data.message);
                    $button.prop('disabled', false).text('Submit Vote');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false).text('Submit Vote');
            }
        });
    });

    // Email validation function
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Handle Enter key in email input
    $('.extrachill-blocks-email-input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).siblings('.extrachill-blocks-submit-vote').click();
        }
    });
});