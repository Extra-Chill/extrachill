jQuery(document).ready(function($) {
    $('#quick-post-topic').submit(function(e) {
        var errors = [];

        // Ensure TinyMCE updates the textarea content
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor !== null) {
            tinyMCE.triggerSave();
        }

        // Clear previous errors
        $('#form-errors').empty(); 

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


// Request artist/pro status function (unchanged)
function requestStatusChange(type) {
    fetch('/wp-admin/admin-ajax.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=request_status_change&type=' + type
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`${type.charAt(0).toUpperCase() + type.slice(1)} status requested. Please allow the admin up to 3 days to review your request.`);
            location.reload(); // Reload the page to update the link visibility
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('There was an error processing your request. Please try again.');
    });
}
