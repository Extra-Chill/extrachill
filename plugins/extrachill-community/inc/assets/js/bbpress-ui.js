/**
 * bbPress UI Handlers
 *
 * Jump-to-latest button, sort select auto-submit, and TinyMCE autosave setup.
 */

document.addEventListener('DOMContentLoaded', function() {
	var jumpButton = document.querySelector('#jump-to-latest');
	if (jumpButton) {
		jumpButton.addEventListener('click', function() {
			var latestReplyUrl = this.getAttribute('data-latest-reply-url');
			if (latestReplyUrl) {
				window.location.href = latestReplyUrl;
			}
		});
	}

	var sortSelect = document.getElementById('sortSelect');
	var sortingForm = document.getElementById('sortingForm');
	if (sortSelect && sortingForm) {
		sortSelect.addEventListener('change', function() {
			sortingForm.submit();
		});
	}
});

window.extrachillTinymceSetup = function(editor) {
	var debounceTimer;
	var saveDelay = 1500;

	editor.on('input keyup', function(e) {
		var nonTriggerKeys = [33, 34, 35, 36, 37, 38, 39, 40];
		if (e && e.keyCode && nonTriggerKeys.includes(e.keyCode)) {
			return;
		}

		if (editor.plugins.autosave && typeof editor.plugins.autosave.storeDraft === 'function') {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function() {
				if (!editor.removed) {
					try {
						editor.plugins.autosave.storeDraft();
					} catch (saveError) {
						console.error('TinyMCE autosave error:', saveError);
					}
				}
			}, saveDelay);
		}
	});

	var form = editor.getElement().closest('form');
	if (form) {
		form.addEventListener('submit', function() {
			if (editor.plugins.autosave && typeof editor.plugins.autosave.removeDraft === 'function') {
				if (!editor.removed) {
					try {
						editor.plugins.autosave.removeDraft(false);
					} catch (clearError) {
						console.error('TinyMCE clear draft error:', clearError);
					}
				}
			}
		}, false);
	}
};
