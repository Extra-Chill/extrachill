document.addEventListener('DOMContentLoaded', function() {
	const uploadInput = document.getElementById('custom-avatar-upload');
	const messageContainer = document.getElementById('custom-avatar-upload-message');
	const avatarThumbnail = document.getElementById('avatar-thumbnail');

	if (!uploadInput) {
		return;
	}

	const spriteUrl = (typeof ecAvatarUpload !== 'undefined') ? ecAvatarUpload.spriteUrl : '';

	uploadInput.addEventListener('change', function(e) {
		const file = this.files[0];
		if (!file) {
			return;
		}

		const formData = new FormData();
		formData.append('file', file);

		uploadInput.disabled = true;
		messageContainer.innerHTML = '<p style="text-align: center;"><svg class="ec-icon ec-icon-spin" style="width: 2em; height: 2em;"><use href="' + spriteUrl + '#spinner"></use></svg> Uploading avatar, please wait...</p>';

		fetch('/wp-json/extrachill/v1/users/avatar', {
			method: 'POST',
			headers: {
				'X-WP-Nonce': wpApiSettings.nonce
			},
			body: formData
		})
		.then(response => {
			if (!response.ok) {
				return response.json().then(err => Promise.reject(err));
			}
			return response.json();
		})
		.then(data => {
			if (data.url) {
				messageContainer.innerHTML = '<p>Avatar uploaded successfully!</p>';
				avatarThumbnail.innerHTML = '<h4>Current Avatar</h4><p>This is the avatar you currently have set. Upload a new image to change it.</p><img src="' + data.url + '" alt="Avatar" style="max-width: 100px; max-height: 100px;" />';
			} else {
				messageContainer.innerHTML = '<p>There was an error uploading the avatar.</p>';
			}
			uploadInput.disabled = false;
		})
		.catch(error => {
			const errorMessage = error.message || 'There was an error uploading the avatar.';
			messageContainer.innerHTML = '<p>' + errorMessage + '</p>';
			uploadInput.disabled = false;
		});
	});
});
