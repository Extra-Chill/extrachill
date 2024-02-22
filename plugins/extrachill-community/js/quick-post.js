jQuery(document).ready(function($) {
    $('#quick-post-topic').submit(function(e) {
        var errors = [];

        // Clear previous errors
        $('#form-errors').empty(); 

        // Title validation
        if ($('#bbp_topic_title').val().trim() === '') {
            errors.push('Title is required.');
        }

        // Content validation
        if ($('#bbp_topic_content').val().trim() === '') {
            errors.push('Content is required.');
        }

        // Forum selection validation
        if ($('#bbp_forum_id').val() === '') {
            errors.push('Please select a forum.');
        }

        // Check for errors, prevent form submission and display errors
        if (errors.length > 0) {
            e.preventDefault(); // Prevent form submission
            
            // Join errors into a single string with line breaks and display
            $('#form-errors').html(errors.join('<br>'));
        }
    });

    // Toggle for the quick post form
    $('#quick-post-toggle').click(function() {
        $('#quick-post-form').slideToggle('fast');
    });
});
