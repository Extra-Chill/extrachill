document.addEventListener('DOMContentLoaded', function() {
	const uploadInput = document.getElementById('custom-avatar-upload');
	const messageContainer = document.getElementById('custom-avatar-upload-message');
	const avatarThumbnail = document.getElementById('avatar-thumbnail');

	if (!uploadInput) {
		return;
	}

	uploadInput.addEventListener('change', function(e) {
		const file = this.files[0];
		if (!file) {
			return;
		}

		const formData = new FormData();
		formData.append('file', file);

		uploadInput.disabled = true;
		messageContainer.innerHTML = '<p style="text-align: center;"><i class="fa fa-spinner fa-spin fa-2x"></i> Uploading avatar, please wait...</p>';

		fetch('/wp-json/extrachill/v1/users/avatar', {
			method: 'POST',
			headers: {
				'X-WP-Nonce': wpApiSettings.nonce
			},
			body: formData
		})
		.then(response => response.json())
		.then(data => {
			if (data.success && data.url) {
				messageContainer.innerHTML = '<p>Avatar uploaded successfully!</p>';
				avatarThumbnail.innerHTML = '<h4>Current Avatar</h4><p>This is the avatar you currently have set. Upload a new image to change it.</p><img src="' + data.url + '" alt="Avatar" style="max-width: 100px; max-height: 100px;" />';
			} else {
				const errorMessage = data.message || 'There was an error uploading the avatar.';
				messageContainer.innerHTML = '<p>' + errorMessage + '</p>';
			}
			uploadInput.disabled = false;
		})
		.catch(error => {
			messageContainer.innerHTML = '<p>There was an error uploading the avatar.</p>';
			uploadInput.disabled = false;
		});
	});
});
