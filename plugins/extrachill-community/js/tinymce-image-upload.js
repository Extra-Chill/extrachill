(function waitForTinyMCE() {
    var maxRetries = 5; // Maximum number of retries
    var retryCount = 0; // Current retry attempt

    function initPlugin() {
        console.log("Initializing TinyMCE plugin");
        if (window.tinymce) {
            tinymce.PluginManager.add('local_upload_plugin', function(editor) {
                console.log("Replacing default image button in TinyMCE");

                editor.addButton('image', {
                    title: 'Upload Image',
                    icon: 'image',
                    onclick: function() {
                        triggerFileInput();
                    },
                    onPostRender: function() {
                        var btn = this.getEl();
                        btn.ontouchend = function() {
                            triggerFileInput(); // Call the same function for touchend event
                        };
                    }
                });

                function triggerFileInput() {
                    console.log("Custom image upload button triggered");
                    let input = document.createElement('input');
                    input.setAttribute('type', 'file');
                    input.setAttribute('accept', 'image/*');
                    input.style.display = 'none';

                    document.body.appendChild(input);

                    input.onchange = function(e) {
                        var file = e.target.files[0];
                        if (file) {
                            console.log("File selected:", file.name);
                            uploadImage(file, editor);
                        }
                        document.body.removeChild(input);
                    };

                    input.click();
                }

                function uploadImage(file, editor) {
                    console.log("Preparing to upload");
                    var formData = new FormData();
                    formData.append('image', file);
                    formData.append('nonce', customTinymcePlugin.nonce);
                    formData.append('action', 'handle_tinymce_image_upload');

                    jQuery.ajax({
                        url: customTinymcePlugin.ajaxUrl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success) {
                                console.log("Image uploaded successfully:", response.data.url);
                                editor.insertContent('<img src="' + response.data.url + '" style="max-width:100%;" />');
                            } else {
                                console.error("Upload failed:", response.data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX error:", status, error);
                        }
                    });
                }
            });

            tinymce.init({
                selector: 'textarea',
            });
        } else {
            console.log("TinyMCE not detected, retrying...");
            retryCount++;
            if (retryCount < maxRetries) {
                setTimeout(initPlugin, 1000); // Retry with a delay
            } else {
                console.error("Failed to initialize TinyMCE after " + maxRetries + " retries.");
            }
        }
    }

    initPlugin();
})();
