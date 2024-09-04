jQuery(document).ready(function($) {
    $('#custom-avatar-upload').change(function(e) {
        var formData = new FormData();
        formData.append('custom_avatar', $(this)[0].files[0]);
        formData.append('action', 'custom_avatar_upload');

        // Disable the input field to prevent navigation
        $(this).prop('disabled', true);

        // Add a loading indicator
        $('#custom-avatar-upload-message').html('<p style="text-align: center;"><i class="fa fa-spinner fa-spin fa-2x"></i> Uploading avatar, please wait...</p>');

        $.ajax({
            url: extrachill_ajax.ajaxurl, // Ensure this is defined correctly
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    $('#custom-avatar-upload-message').html('<p>Avatar uploaded successfully!</p>');
                    if (response.data && response.data.url) {
                        $('#avatar-thumbnail').html('<img src="' + response.data.url + '" alt="Avatar" style="max-width: 100px; max-height: 100px;" />');
                    }
                } else {
                    $('#custom-avatar-upload-message').html('<p>There was an error uploading the avatar.</p>');
                }
                // Re-enable the input field after upload
                $('#custom-avatar-upload').prop('disabled', false);
            },
            error: function(response) {
                $('#custom-avatar-upload-message').html('<p>There was an error uploading the avatar.</p>');
                // Re-enable the input field after upload
                $('#custom-avatar-upload').prop('disabled', false);
            }
        });
    });
});
