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
		resetTurnstile(form);
	} finally {
			delete form.dataset.submitting;
			form.classList.remove('is-loading');
		}
	};

	// ────────────────────────────────────────────────────────────────────
	// Artist URL import (extrachill-events#320)
	//
	// Sits on top of the manual form. When the user types a URL into the
	// import field, we probe it via `datamachine/v1/artist-url/preview`
	// and, on success, swap the manual form for a confirmation panel
	// (events found + Submit for review). The manual form keeps working
	// byte-identically when no URL is provided.
	// ────────────────────────────────────────────────────────────────────

	const urlImportTimeoutMs = 8000;

	const fetchJson = async (url, body, nonce) => {
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), urlImportTimeoutMs);
		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify(body || {}),
				signal: controller.signal,
			});
			const payload = await response.json().catch(() => ({}));
			return { ok: response.ok, status: response.status, payload };
		} finally {
			clearTimeout(timer);
		}
	};

	const renderConfirmationEvents = (listEl, events) => {
		listEl.innerHTML = '';
		(events || []).slice(0, 5).forEach((evt) => {
			const li = document.createElement('li');
			const date = evt.startDate || '';
			const time = evt.startTime ? ` · ${evt.startTime}` : '';
			const venue = evt.venue ? ` @ ${evt.venue}` : '';
			li.textContent = `${date}${time}${venue} — ${evt.title || ''}`.trim();
			listEl.appendChild(li);
		});
	};

	const showManualForm = (container) => {
		const form = container.querySelector('form.ec-event-submission__form');
		if (form) {
			form.hidden = false;
		}
	};

	const hideManualForm = (container) => {
		const form = container.querySelector('form.ec-event-submission__form');
		if (form) {
			form.hidden = true;
		}
	};

	const initUrlImport = (container) => {
		const wrap = container.querySelector('.ec-event-submission__url-import');
		if (!wrap) {
			return; // anonymous user, no URL import block rendered
		}

		const input = wrap.querySelector('.ec-event-submission__url-import-input');
		const tryBtn = wrap.querySelector('.ec-event-submission__url-import-try');
		const statusEl = wrap.querySelector('.ec-event-submission__url-import-status');
		const confirmPanel = wrap.querySelector('.ec-event-submission__url-import-confirm');
		const summaryEl = confirmPanel.querySelector('.ec-event-submission__url-import-summary');
		const eventsListEl = confirmPanel.querySelector('.ec-event-submission__url-import-events');
		const submitBtn = confirmPanel.querySelector('.ec-event-submission__url-import-submit');
		const cancelBtn = confirmPanel.querySelector('.ec-event-submission__url-import-cancel');

		const previewUrl = container.dataset.artistUrlPreview;
		const submitUrl = container.dataset.artistUrlSubmit;
		const nonce = container.dataset.restNonce;

		let currentPreview = null;

		const setState = (state) => {
			wrap.dataset.state = state;
		};

		const setUrlStatus = (msg, isError = false) => {
			statusEl.textContent = msg || '';
			statusEl.classList.toggle('is-error', Boolean(isError));
		};

		const resetConfirmation = () => {
			confirmPanel.hidden = true;
			summaryEl.textContent = '';
			eventsListEl.innerHTML = '';
			currentPreview = null;
		};

		const runPreview = async () => {
			const url = (input.value || '').trim();
			if (!url) {
				setUrlStatus('Enter a URL to try.', true);
				return;
			}

			setState('loading');
			setUrlStatus('Checking that URL…');
			resetConfirmation();
			tryBtn.disabled = true;

			try {
				const { ok, payload } = await fetchJson(previewUrl, { url }, nonce);
				if (!ok) {
					const code = payload?.code || 'preview_failed';
					const message = payload?.message || 'We could not check that URL.';
					if (code === 'url_already_tracked') {
						setUrlStatus('This URL is already being tracked. Use the manual form below if you want to submit a single event.', true);
						setState('error');
						showManualForm(container);
						return;
					}
					if (code === 'no_events_found') {
						setUrlStatus("We couldn't extract events from that page. Try the manual form below.", true);
						setState('error');
						showManualForm(container);
						return;
					}
					setUrlStatus(message, true);
					setState('error');
					showManualForm(container);
					return;
				}

				currentPreview = payload;
				const count = Number(payload?.events_found || 0);
				const artist = payload?.suggested_artist_name || 'this artist';
				summaryEl.textContent = `Found ${count} event${count === 1 ? '' : 's'} from ${artist}. Submit for review?`;
				renderConfirmationEvents(eventsListEl, payload?.events_preview || []);
				confirmPanel.hidden = false;
				setUrlStatus('');
				setState('preview');
				hideManualForm(container);
			} catch (err) {
				if (err && err.name === 'AbortError') {
					setUrlStatus('That URL took too long to respond. Try again or use the manual form below.', true);
				} else {
					setUrlStatus('Something went wrong checking that URL. Try the manual form below.', true);
				}
				setState('error');
				showManualForm(container);
			} finally {
				tryBtn.disabled = false;
			}
		};

		const runSubmit = async () => {
			const url = (input.value || '').trim();
			if (!url) {
				return;
			}
			submitBtn.disabled = true;
			setUrlStatus('Submitting…');

			try {
				const { ok, payload } = await fetchJson(submitUrl, { url }, nonce);
				if (!ok || !payload?.success) {
					const code = payload?.code || 'submit_failed';
					if (code === 'url_already_tracked') {
						setUrlStatus('This URL is already being tracked.', true);
					} else {
						setUrlStatus(payload?.message || 'Submission failed. Try the manual form below.', true);
					}
					setState('error');
					showManualForm(container);
					return;
				}
				setUrlStatus(payload?.message || "Submitted for review. We'll set up automatic imports if approved.", false);
				setState('submitted');
				confirmPanel.hidden = true;
				input.disabled = true;
				tryBtn.disabled = true;
			} catch (err) {
				setUrlStatus('Something went wrong submitting that URL. Try the manual form below.', true);
				setState('error');
				showManualForm(container);
			} finally {
				submitBtn.disabled = false;
			}
		};

		const cancelImport = () => {
			resetConfirmation();
			setState('idle');
			setUrlStatus('');
			showManualForm(container);
		};

		tryBtn.addEventListener('click', runPreview);
		input.addEventListener('blur', () => {
			// Only auto-probe if there's a value and we haven't already
			// previewed/submitted.
			if (wrap.dataset.state === 'idle' && input.value.trim() !== '') {
				runPreview();
			}
		});
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				runPreview();
			}
		});
		submitBtn.addEventListener('click', runSubmit);
		cancelBtn.addEventListener('click', cancelImport);
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
		document.querySelectorAll(SELECTOR).forEach((container) => {
			initForm(container);
			initUrlImport(container);
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
