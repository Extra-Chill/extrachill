jQuery(document).ready(function($) {
    $('#custom-avatar-upload').change(function(e) {
        var formData = new FormData();
        formData.append('custom_avatar', $(this)[0].files[0]);
        formData.append('action', 'custom_avatar_upload');

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
            },
            error: function(response) {
                $('#custom-avatar-upload-message').html('<p>There was an error uploading the avatar.</p>');
            }
        });
    });
});

