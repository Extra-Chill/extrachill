/**
 * Article feedback. Yes/No clicks are still recorded by the delegated CTA tracker.
 */
(function () {
	'use strict';

	const block = document.querySelector('[data-docs-feedback-block]');
	if (!block) {
		return;
	}

	const form = block.querySelector('[data-docs-feedback-form]');
	const actions = block.querySelector('[data-docs-feedback-actions]');

	actions.addEventListener('click', function (event) {
		const button = event.target.closest('[data-docs-feedback]');
		if (!button) {
			return;
		}

		// Leave the clicked button in place until the analytics handler has run.
		window.setTimeout(function () {
			if (actions.hidden) {
				return;
			}
			const answer = button.dataset.docsFeedback;
			const reply = block.querySelector('[data-docs-feedback-reply="' + answer + '"]');
			actions.hidden = true;
			reply.hidden = false;

			if (answer === 'no' && form) {
				const template = form.querySelector('template');
				template.replaceWith(template.content.cloneNode(true));
				// The network bootstrap also runs when the API finishes loading.
				if (window.ecTurnstileBoot) {
					window.ecTurnstileBoot();
				}
				form.elements.message.focus();
			} else {
				reply.setAttribute('role', 'status');
			}
		}, 0);
	});

	if (!form) {
		return;
	}

	const send = form.querySelector('[type="submit"]');
	const error = form.querySelector('[data-docs-feedback-error]');
	const showError = function (message) {
		error.hidden = false;
		error.textContent = message;
	};

	form.addEventListener('submit', async function (event) {
		event.preventDefault();
		if (send.disabled) {
			return;
		}
		error.hidden = true;
		error.textContent = '';

		const fields = new FormData(form);
		const token = fields.get('cf-turnstile-response');
		if (!token) {
			showError(form.dataset.challengeError);
			return;
		}

		const input = {
			post_id: Number(form.dataset.postId),
			message: fields.get('message'),
			turnstile_response: token,
		};
		if (fields.has('email')) {
			input.email = fields.get('email');
		}
		const headers = { 'Content-Type': 'application/json' };
		if (form.dataset.nonce) {
			headers['X-WP-Nonce'] = form.dataset.nonce;
		}

		send.disabled = true;
		try {
			const response = await fetch(form.dataset.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify({ input }),
			});
			const result = await response.json();
			if (!response.ok || result.success !== true) {
				showError(result.message || form.dataset.error);
			} else {
				form.remove();
				const success = block.querySelector('[data-docs-feedback-success]');
				success.hidden = false;
				success.focus();
				return;
			}
		} catch {
			showError(form.dataset.error);
		} finally {
			send.disabled = false;
		}

		if (window.turnstile) {
			window.turnstile.reset(form.querySelector('#docs-feedback-turnstile'));
		}
	});
})();
