function updateThumbnail(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();

        reader.onload = function(e) {
            var imgContainer = document.querySelector('#current-image');

            if (!imgContainer) {
                console.error('Image container not found.');
                return;
            }

            var imgElement = imgContainer.querySelector('img');
            if (!imgElement) {
                imgElement = document.createElement('img');
                imgElement.style.maxWidth = '100px';
                imgElement.style.maxHeight = '100px';
                imgContainer.prepend(imgElement);
            }

            imgElement.src = e.target.result;

            var filenameSpan = imgContainer.querySelector('span');
            if (!filenameSpan) {
                filenameSpan = document.createElement('span');
                imgContainer.appendChild(filenameSpan);
            }

            filenameSpan.textContent = input.files[0].name;
        };

        reader.onerror = function(e) {
            console.error('Error reading file', e);
        };

        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('fan-profile-form');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);

        var fileInput = document.getElementById('profile_image');
        if (fileInput.files[0]) {
            formData.append('profile_image', fileInput.files[0]);
        }

        var apiRoute = extrachillData.apiRoute;
        fetch(apiRoute, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': formData.get('_wpnonce')
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Handle the response data here
            // For example:
            if (data.post_id) {
                document.getElementById('form-success-message').innerHTML = data.message + ' <a href="' + data.profile_url + '">View profile.</a>';
            } else {
                document.getElementById('form-success-message').innerHTML = 'Error: ' + data.message;
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    });
});
