document.addEventListener('DOMContentLoaded', function() {
    var addImageButtons = document.querySelectorAll('.reply-toolbar .add-image');
    if (!addImageButtons) {
        return;
    }

    addImageButtons.forEach(function(addImageButton) {
        var hiddenFileInput = document.createElement('input');
        hiddenFileInput.type = 'file';
        hiddenFileInput.accept = 'image/*';
        hiddenFileInput.style.display = 'none';
        document.body.appendChild(hiddenFileInput);

        addImageButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            hiddenFileInput.click();

            // Store the current text area associated with this button
            hiddenFileInput.currentTextArea = addImageButton.closest('.reply-topic-form-container').querySelector('.bbp-the-content');
        });

        hiddenFileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var formData = new FormData();
                formData.append('action', 'handle_forum_image_upload');
                formData.append('forum_image', this.files[0]);
                formData.append('_ajax_nonce', extrachillImageUpload.nonce);

                fetch(extrachillImageUpload.ajaxurl, {
                    method: 'POST',
                    body: formData,
                })
                .then(response => response.json())
                .then(data => {
                    if (data.attachment_id && data.url && this.currentTextArea) {
                        insertImageHTML(this.currentTextArea, data.url);
                    } else {
                        console.error('Error uploading image:', data.message);
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        });
    });

    function insertImageHTML(textElement, imageUrl) {
        var htmlImage = `<img src="${imageUrl}" alt="Uploaded Image">\n`;
        textElement.value += htmlImage;
    }
});
