( function () {
	const SELECTOR = '.ec-event-submission';

	const setStatus = ( el, message, isError = false ) => {
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.classList.toggle( 'is-error', Boolean( isError ) );
	};

	const resetTurnstile = ( form ) => {
		if (
			window.turnstile &&
			typeof window.turnstile.reset === 'function'
		) {
			try {
				window.turnstile.reset();
			} catch {
				// no-op if reset fails
			}
		}

		const tokenInput = form.querySelector(
			'input[name="cf-turnstile-response"]'
		);
		if ( tokenInput ) {
			tokenInput.value = '';
		}
	};

	const handleSubmit = async ( event ) => {
		event.preventDefault();

		const form = event.currentTarget;
		if ( form.dataset.submitting === 'true' ) {
			return;
		}

		const container = form.closest( SELECTOR );
		if ( ! container ) {
			return;
		}

		const statusEl = form.querySelector( '.ec-event-submission__status' );
		const endpoint = container.dataset.endpoint;
		const systemPrompt = container.dataset.systemPrompt;
		const successMessage =
			container.dataset.successMessage || 'Thanks for sending this in!';

		if ( ! endpoint ) {
			setStatus(
				statusEl,
				'Submission endpoint is not configured.',
				true
			);
			return;
		}

		const turnstileInput = form.querySelector(
			'input[name="cf-turnstile-response"]'
		);
		const turnstileResponse = turnstileInput
			? turnstileInput.value.trim()
			: '';
		if ( ! turnstileResponse ) {
			setStatus(
				statusEl,
				'Complete the security check before submitting.',
				true
			);
			return;
		}

		form.dataset.submitting = 'true';
		form.classList.add( 'is-loading' );
		setStatus( statusEl, 'Sending…' );

		const formData = new FormData( form );
		if ( systemPrompt ) {
			formData.set( 'system_prompt', systemPrompt );
		}
		formData.set( 'turnstile_response', turnstileResponse );

		try {
			const response = await fetch( endpoint, {
				method: 'POST',
				body: formData,
			} );

			const payload = await response.json().catch( () => ( {} ) );

			if ( ! response.ok ) {
				const message =
					payload?.message ||
					'We could not process your submission. Please try again.';
				throw new Error( message );
			}

			form.reset();
			resetTurnstile( form );
			setStatus( statusEl, successMessage, false );
		} catch ( error ) {
			setStatus(
				statusEl,
				error.message ||
					'Something went wrong. Please try again later.',
				true
			);
			resetTurnstile( form );
		} finally {
			delete form.dataset.submitting;
			form.classList.remove( 'is-loading' );
		}
	};

	// ────────────────────────────────────────────────────────────────────
	// Qualified event-source import (extrachill-events#628)
	//
	// Sits on top of the manual form. When the user types a URL into the
	// import field, we probe it via `extrachill/v1/event-source/preview`
	// and, on success, swap the manual form for a confirmation panel
	// (events found + Submit for review). The manual form keeps working
	// byte-identically when no URL is provided.
	// ────────────────────────────────────────────────────────────────────

	// Qualification may test a discovered calendar plus several bounded
	// candidates. Keep the request finite without racing the server's work.
	const urlImportTimeoutMs = 60000;

	const fetchJson = async ( url, body, nonce ) => {
		const controller = new AbortController();
		const timer = setTimeout(
			() => controller.abort(),
			urlImportTimeoutMs
		);
		let response;
		try {
			response = await fetch( url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( body || {} ),
				signal: controller.signal,
			} );
		} finally {
			clearTimeout( timer );
		}
		const payload = await response.json().catch( () => ( {} ) );
		return { ok: response.ok, status: response.status, payload };
	};

	const renderConfirmationEvents = ( listEl, events ) => {
		listEl.innerHTML = '';
		( events || [] ).slice( 0, 5 ).forEach( ( evt ) => {
			const li = document.createElement( 'li' );
			const date = evt.startDate || '';
			const time = evt.startTime ? ` · ${ evt.startTime }` : '';
			const venue = evt.venue ? ` @ ${ evt.venue }` : '';
			li.textContent = `${ date }${ time }${ venue } — ${
				evt.title || ''
			}`.trim();
			listEl.appendChild( li );
		} );
	};

	const showManualForm = ( container ) => {
		const form = container.querySelector(
			'form.ec-event-submission__form'
		);
		if ( form ) {
			form.hidden = false;
		}
	};

	const hideManualForm = ( container ) => {
		const form = container.querySelector(
			'form.ec-event-submission__form'
		);
		if ( form ) {
			form.hidden = true;
		}
	};

	const initUrlImport = ( container ) => {
		const wrap = container.querySelector(
			'.ec-event-submission__url-import'
		);
		if ( ! wrap ) {
			return; // anonymous user, no URL import block rendered
		}

		const input = wrap.querySelector(
			'.ec-event-submission__url-import-input'
		);
		const tryBtn = wrap.querySelector(
			'.ec-event-submission__url-import-try'
		);
		const statusEl = wrap.querySelector(
			'.ec-event-submission__url-import-status'
		);
		const confirmPanel = wrap.querySelector(
			'.ec-event-submission__url-import-confirm'
		);
		const summaryEl = confirmPanel.querySelector(
			'.ec-event-submission__url-import-summary'
		);
		const eventsListEl = confirmPanel.querySelector(
			'.ec-event-submission__url-import-events'
		);
		const submitBtn = confirmPanel.querySelector(
			'.ec-event-submission__url-import-submit'
		);
		const cancelBtn = confirmPanel.querySelector(
			'.ec-event-submission__url-import-cancel'
		);

		const previewUrl = container.dataset.eventSourcePreview;
		const submitUrl = container.dataset.eventSourceSubmit;
		const nonce = container.dataset.restNonce;

		const setState = ( state ) => {
			wrap.dataset.state = state;
		};

		const setUrlStatus = ( msg, isError = false ) => {
			statusEl.textContent = msg || '';
			statusEl.classList.toggle( 'is-error', Boolean( isError ) );
		};

		const resetConfirmation = () => {
			confirmPanel.hidden = true;
			summaryEl.textContent = '';
			eventsListEl.innerHTML = '';
		};

		const runPreview = async () => {
			const url = ( input.value || '' ).trim();
			if ( ! url ) {
				setUrlStatus( 'Enter a URL to try.', true );
				return;
			}

			setState( 'loading' );
			setUrlStatus( 'Checking that URL…' );
			resetConfirmation();
			tryBtn.disabled = true;

			try {
				const { ok, payload } = await fetchJson(
					previewUrl,
					{ url },
					nonce
				);
				if ( ! ok ) {
					const code = payload?.code || 'preview_failed';
					const message =
						payload?.message || 'We could not check that URL.';
					if ( code === 'url_already_tracked' ) {
						setUrlStatus(
							'This URL is already being tracked. Use the manual form below if you want to submit a single event.',
							true
						);
						setState( 'error' );
						showManualForm( container );
						return;
					}
					if ( code === 'no_events_found' ) {
						setUrlStatus(
							"We couldn't extract events from that page. Try the manual form below.",
							true
						);
						setState( 'error' );
						showManualForm( container );
						return;
					}
					setUrlStatus( message, true );
					setState( 'error' );
					showManualForm( container );
					return;
				}
				if ( payload?.existing_coverage?.covered ) {
					setUrlStatus(
						'This source is already covered by an existing import.',
						true
					);
					setState( 'error' );
					showManualForm( container );
					return;
				}
				if ( ! payload?.recurring_eligible ) {
					setUrlStatus(
						payload?.warnings?.[ 0 ] ||
							'This page is not eligible for a recurring import. Use the manual form below.',
						true
					);
					setState( 'error' );
					showManualForm( container );
					return;
				}

				const count = Number( payload?.events_found || 0 );
				const binding = payload?.recommended_binding || {};
				const sourceLabel =
					binding.name ||
					( payload?.source_kind === 'unknown'
						? 'an unclassified source'
						: `this ${ payload?.source_kind || 'source' }` );
				summaryEl.textContent = `Found ${ count } event${
					count === 1 ? '' : 's'
				} from ${ sourceLabel }. Submit for review?`;
				renderConfirmationEvents(
					eventsListEl,
					payload?.events_preview || []
				);
				confirmPanel.hidden = false;
				setUrlStatus( '' );
				setState( 'preview' );
				hideManualForm( container );
			} catch ( err ) {
				if ( err && err.name === 'AbortError' ) {
					setUrlStatus(
						'That URL took too long to respond. Try again or use the manual form below.',
						true
					);
				} else {
					setUrlStatus(
						'Something went wrong checking that URL. Try the manual form below.',
						true
					);
				}
				setState( 'error' );
				showManualForm( container );
			} finally {
				tryBtn.disabled = false;
			}
		};

		const runSubmit = async () => {
			const url = ( input.value || '' ).trim();
			if ( ! url ) {
				return;
			}
			submitBtn.disabled = true;
			setUrlStatus( 'Submitting…' );

			try {
				const { ok, payload } = await fetchJson(
					submitUrl,
					{ url },
					nonce
				);
				if ( ! ok || ! payload?.success ) {
					const code = payload?.code || 'submit_failed';
					if ( code === 'url_already_tracked' ) {
						setUrlStatus(
							'This URL is already being tracked.',
							true
						);
					} else {
						setUrlStatus(
							payload?.message ||
								'Submission failed. Try the manual form below.',
							true
						);
					}
					setState( 'error' );
					showManualForm( container );
					return;
				}
				setUrlStatus(
					payload?.message ||
						"Submitted for review. We'll set up automatic imports if approved.",
					false
				);
				setState( 'submitted' );
				confirmPanel.hidden = true;
				input.disabled = true;
				tryBtn.disabled = true;
			} catch {
				setUrlStatus(
					'Something went wrong submitting that URL. Try the manual form below.',
					true
				);
				setState( 'error' );
				showManualForm( container );
			} finally {
				submitBtn.disabled = false;
			}
		};

		const cancelImport = () => {
			resetConfirmation();
			setState( 'idle' );
			setUrlStatus( '' );
			showManualForm( container );
		};

		tryBtn.addEventListener( 'click', runPreview );
		input.addEventListener( 'blur', () => {
			// Only auto-probe if there's a value and we haven't already
			// previewed/submitted.
			if ( wrap.dataset.state === 'idle' && input.value.trim() !== '' ) {
				runPreview();
			}
		} );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				runPreview();
			}
		} );
		submitBtn.addEventListener( 'click', runSubmit );
		cancelBtn.addEventListener( 'click', cancelImport );
	};

	// ────────────────────────────────────────────────────────────────────
	// Venue typeahead (extrachill-events#965)
	//
	// Suggests existing venues from the core `wp/v2/venue` term search as
	// the submitter types. Picking one records its term ID in the hidden
	// `venue_term_id` input, which the submission ability validates and the
	// upsert assigns directly, so a typo can't create a new venue term.
	// Editing the text after a pick clears the ID: free text still works
	// for venues we don't know yet.
	// ────────────────────────────────────────────────────────────────────
	const VENUE_MIN_CHARS = 2;
	const VENUE_DEBOUNCE_MS = 250;
	const VENUE_MAX_RESULTS = 8;

	const decodeEntities = ( value ) => {
		const el = document.createElement( 'textarea' );
		el.innerHTML = value || '';
		return el.value;
	};

	const venuePlace = ( venue ) =>
		[ venue?.meta?._venue_city, venue?.meta?._venue_state ]
			.filter( Boolean )
			.join( ', ' );

	// Venues in the city the submitter typed come first.
	const rankVenues = ( venues, city ) => {
		const wanted = ( city || '' ).trim().toLowerCase();
		if ( ! wanted ) {
			return venues;
		}
		const inCity = ( venue ) =>
			( venue?.meta?._venue_city || '' ).toLowerCase() === wanted;
		return [
			...venues.filter( inCity ),
			...venues.filter( ( venue ) => ! inCity( venue ) ),
		];
	};

	const initVenueTypeahead = ( container ) => {
		const searchUrl = container.dataset.venueSearch;
		const input = container.querySelector( 'input[name="venue_name"]' );
		const idInput = container.querySelector(
			'input[name="venue_term_id"]'
		);
		const list = container.querySelector(
			'.ec-event-submission__venue-suggestions'
		);
		if ( ! searchUrl || ! input || ! idInput || ! list ) {
			return;
		}
		const cityInput = container.querySelector( 'input[name="event_city"]' );

		let venues = [];
		let activeIndex = -1;
		let timer = null;
		let requestSeq = 0;

		const close = () => {
			list.hidden = true;
			list.innerHTML = '';
			venues = [];
			activeIndex = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		};

		const setActive = ( index ) => {
			const items = list.querySelectorAll( 'li' );
			items.forEach( ( item, i ) => {
				const active = i === index;
				item.classList.toggle( 'is-active', active );
				item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
			activeIndex = index;
			if ( index >= 0 && items[ index ] ) {
				input.setAttribute(
					'aria-activedescendant',
					items[ index ].id
				);
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		};

		const choose = ( venue ) => {
			input.value = decodeEntities( venue.name );
			idInput.value = String( venue.id );
			const city = venue?.meta?._venue_city;
			if ( cityInput && city && ! cityInput.value.trim() ) {
				cityInput.value = city;
			}
			close();
		};

		const render = () => {
			list.innerHTML = '';
			if ( ! venues.length ) {
				close();
				return;
			}
			venues.forEach( ( venue, index ) => {
				const item = document.createElement( 'li' );
				item.id = `${ list.id }-${ index }`;
				item.className = 'ec-event-submission__venue-suggestion';
				item.setAttribute( 'role', 'option' );
				item.setAttribute( 'aria-selected', 'false' );
				item.dataset.venueId = String( venue.id );

				const name = document.createElement( 'span' );
				name.textContent = decodeEntities( venue.name );
				item.appendChild( name );

				const place = venuePlace( venue );
				if ( place ) {
					const placeEl = document.createElement( 'span' );
					placeEl.className =
						'ec-event-submission__venue-suggestion-place';
					placeEl.textContent = place;
					item.appendChild( placeEl );
				}

				// mousedown fires before the input's blur closes the list.
				item.addEventListener( 'mousedown', ( e ) => {
					e.preventDefault();
					choose( venue );
				} );
				list.appendChild( item );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			activeIndex = -1;
		};

		const search = async ( term ) => {
			const seq = ++requestSeq;
			const url = new URL( searchUrl, window.location.href );
			url.searchParams.set( 'search', term );
			url.searchParams.set( 'per_page', String( VENUE_MAX_RESULTS ) );
			url.searchParams.set( '_fields', 'id,name,meta' );
			try {
				const response = await fetch( url.toString(), {
					credentials: 'same-origin',
				} );
				const payload = response.ok ? await response.json() : [];
				if ( seq !== requestSeq ) {
					return;
				}
				venues = rankVenues(
					Array.isArray( payload ) ? payload : [],
					cityInput ? cityInput.value : ''
				);
				render();
			} catch {
				// Suggestions are a convenience; free text still submits.
				if ( seq === requestSeq ) {
					close();
				}
			}
		};

		input.addEventListener( 'input', () => {
			// Any edit after a pick means this is no longer that venue.
			idInput.value = '';
			window.clearTimeout( timer );
			const term = input.value.trim();
			if ( term.length < VENUE_MIN_CHARS ) {
				requestSeq++;
				close();
				return;
			}
			timer = window.setTimeout(
				() => search( term ),
				VENUE_DEBOUNCE_MS
			);
		} );

		input.addEventListener( 'keydown', ( e ) => {
			if ( list.hidden || ! venues.length ) {
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActive( ( activeIndex + 1 ) % venues.length );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActive(
					activeIndex <= 0 ? venues.length - 1 : activeIndex - 1
				);
			} else if ( e.key === 'Enter' && activeIndex >= 0 ) {
				e.preventDefault();
				choose( venues[ activeIndex ] );
			} else if ( e.key === 'Escape' ) {
				close();
			}
		} );

		input.addEventListener( 'blur', () => {
			window.clearTimeout( timer );
			requestSeq++;
			close();
		} );

		const form = input.closest( 'form' );
		if ( form ) {
			// A hidden input's value *is* its default, so form.reset() after
			// a successful submit would keep the last pick. Clear it here.
			form.addEventListener( 'reset', () => {
				idInput.value = '';
				close();
			} );
		}
	};

	const initForm = ( container ) => {
		const form = container.querySelector( 'form' );
		if ( ! form || form.dataset.ecSubmissionBound === 'true' ) {
			return;
		}

		form.dataset.ecSubmissionBound = 'true';
		form.addEventListener( 'submit', handleSubmit );
	};

	const init = () => {
		document.querySelectorAll( SELECTOR ).forEach( ( container ) => {
			initForm( container );
			initUrlImport( container );
			initVenueTypeahead( container );
		} );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
