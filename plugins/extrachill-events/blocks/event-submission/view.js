(function () {
	const SELECTOR = '.ec-event-submission';

	const setStatus = (el, message, isError = false) => {
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.classList.toggle('is-error', Boolean(isError));
	};

	const resetTurnstile = (form) => {
		if (window.turnstile && typeof window.turnstile.reset === 'function') {
			try {
				window.turnstile.reset();
			} catch (err) {
				// no-op if reset fails
			}
		}

		const tokenInput = form.querySelector('input[name="cf-turnstile-response"]');
		if (tokenInput) {
			tokenInput.value = '';
		}
	};

	const handleSubmit = async (event) => {
		event.preventDefault();

		const form = event.currentTarget;
		if (form.dataset.submitting === 'true') {
			return;
		}

		const container = form.closest(SELECTOR);
		if (!container) {
			return;
		}

		const statusEl = form.querySelector('.ec-event-submission__status');
		const endpoint = container.dataset.endpoint;
		const flowId = container.dataset.flowId;
		const systemPrompt = container.dataset.systemPrompt;
		const successMessage = container.dataset.successMessage || 'Thanks for sending this in!';

		if (!endpoint) {
			setStatus(statusEl, 'Submission endpoint is not configured.', true);
			return;
		}


		const turnstileInput = form.querySelector('input[name="cf-turnstile-response"]');
		const turnstileResponse = turnstileInput ? turnstileInput.value.trim() : '';
		if (!turnstileResponse) {
			setStatus(statusEl, 'Complete the security check before submitting.', true);
			return;
		}

		form.dataset.submitting = 'true';
		form.classList.add('is-loading');
		setStatus(statusEl, 'Sending…');

		const formData = new FormData(form);
		if (flowId) {
			formData.set('flow_id', flowId);
		}
		if (systemPrompt) {
			formData.set('system_prompt', systemPrompt);
		}
		formData.set('turnstile_response', turnstileResponse);

		try {
			const response = await fetch(endpoint, {
				method: 'POST',
				body: formData,
			});

			const payload = await response.json().catch(() => ({}));

			if (!response.ok) {
				const message = payload?.message || 'We could not process your submission. Please try again.';
				throw new Error(message);
			}

			form.reset();
			resetTurnstile(form);
			setStatus(statusEl, successMessage, false);
		} catch (error) {
			setStatus(statusEl, error.message || 'Something went wrong. Please try again later.', true);
		} finally {
			delete form.dataset.submitting;
			form.classList.remove('is-loading');
		}
	};

	const initForm = (container) => {
		const form = container.querySelector('form');
		if (!form || form.dataset.ecSubmissionBound === 'true') {
			return;
		}

		form.dataset.ecSubmissionBound = 'true';
		form.addEventListener('submit', handleSubmit);
	};

	const init = () => {
		document.querySelectorAll(SELECTOR).forEach(initForm);
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
