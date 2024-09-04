jQuery(document).ready(function($) {
    $('#wp_surgeon_recalculate_points').on('click', function() {
        var userId = $(this).data('user-id');
        var nonce = wpSurgeonAdmin.nonce;
        var resultElement = $('#wp_surgeon_recalculate_points_result');

        resultElement.text('Recalculating...');

        $.ajax({
            type: 'POST',
            url: wpSurgeonAdmin.ajaxUrl,
            data: {
                action: 'wp_surgeon_recalculate_points',
                user_id: userId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    resultElement.text('Total Points: ' + response.data.total_points);
                } else {
                    resultElement.text('Error: ' + response.data);
                }
            },
            error: function() {
                resultElement.text('An error occurred.');
            }
        });
    });
});
