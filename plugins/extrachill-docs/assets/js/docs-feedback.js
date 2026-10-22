/**
 * "Did this help?" on docs articles.
 *
 * The click itself is recorded by extrachill-analytics' delegated CTA tracker
 * (data-ec-track). This only swaps the buttons for a reply, one tick later so
 * the tracker still sees the clicked button in place.
 */
(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		const button = event.target.closest
			? event.target.closest('[data-docs-feedback]')
			: null;
		if (!button) {
			return;
		}

		const block = button.closest('.docs-feedback');
		const answer = button.getAttribute('data-docs-feedback');

		window.setTimeout(function () {
			const actions = block.querySelector('.docs-feedback__actions');
			const reply = block.querySelector(
				'[data-docs-feedback-reply="' + answer + '"]'
			);
			if (actions) {
				actions.hidden = true;
			}
			if (reply) {
				reply.hidden = false;
				reply.setAttribute('role', 'status');
			}
		}, 0);
	});
})();
