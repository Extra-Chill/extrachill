(function () {
	function initMediaPicker(container) {
		if (!container || typeof wp === 'undefined' || !wp.media) {
			return;
		}

		var input = container.querySelector(container.dataset.targetInput);
		var preview = container.querySelector(container.dataset.targetPreview);
		var idLabel = container.querySelector(container.dataset.targetId);
		var selectButton = container.querySelector('[data-action="select"]');
		var removeButton = container.querySelector('[data-action="remove"]');
		var frame;

		if (!input || !preview || !idLabel || !selectButton || !removeButton) {
			return;
		}

		selectButton.addEventListener('click', function () {
			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select Default OG Image',
				button: { text: 'Use this image' },
				multiple: false
			});

			frame.on('select', function () {
				var selection = frame.state().get('selection');
				var attachment = selection.first();
				if (!attachment) {
					return;
				}

				var data = attachment.toJSON();
				var url = (data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url) ? data.sizes.thumbnail.url : data.url;

				input.value = data.id;
				preview.src = url;
				preview.style.display = '';
				idLabel.textContent = 'Attachment ID: ' + data.id;
				idLabel.style.display = '';
				removeButton.disabled = false;
			});

			frame.open();
		});

		removeButton.addEventListener('click', function () {
			input.value = '';
			preview.src = '';
			preview.style.display = 'none';
			idLabel.textContent = '';
			idLabel.style.display = 'none';
			removeButton.disabled = true;
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var containers = document.querySelectorAll('.extrachill-seo-media-picker');
		containers.forEach(initMediaPicker);
	});
})();
