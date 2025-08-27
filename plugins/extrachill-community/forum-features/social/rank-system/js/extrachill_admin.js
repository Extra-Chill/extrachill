jQuery(document).ready(function($) {
    $('#extrachill_recalculate_points').on('click', function() {
        var userId = $(this).data('user-id');
        var nonce = extraChillAdmin.nonce;
        var resultElement = $('#extrachill_recalculate_points_result');

        resultElement.text('Recalculating...');

        $.ajax({
            type: 'POST',
            url: extraChillAdmin.ajaxUrl,
            data: {
                action: 'extrachill_recalculate_points',
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