jQuery(document).ready(function($) {
    // Handler for change event on sorting select boxes
    $('.bbp-sorting-form select').change(function(e) {
        e.preventDefault(); // Prevent the default form submission

        var form = $(this).closest('form'); // Find the closest form element
        var sort = $('select[name="sort"]', form).val(); // Get the selected sort value
        var time_range = $('select[name="time_range"]', form).val(); // Get the selected time range

        // Perform the AJAX request
        $.ajax({
            url: bbpAjax.url, // You need to define `bbpAjax.url` in your PHP when enqueuing this script
            type: 'POST',
            data: {
                action: 'bbp_sort_topics', // The action name for wp_ajax_ and wp_ajax_nopriv_
                sort: sort,
                time_range: time_range,
                nonce: bbpAjax.nonce // Pass a nonce for security, defined when enqueuing the script
            },
            success: function(response) {
                // Replace the topics list with the response
                $('#bbp-forum-<?php echo bbp_get_forum_id(); ?> .bbp-body').html(response);
            }
        });
    });
});
