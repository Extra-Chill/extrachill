( function () {
	const config = window.ecOutboundTracking;
	if ( ! config ) {
		return;
	}

	// Hosts that belong to the Extra Chill network — a click to any of these is
	// an INTERNAL hop (already measured by the conversion map), never an
	// outbound exit. Lower-cased for case-insensitive comparison.
	const networkHosts = ( config.networkHosts || [] ).map( function ( h ) {
		return String( h ).toLowerCase();
	} );

	function isNetworkHost( host ) {
		host = String( host ).toLowerCase();
		for ( let i = 0; i < networkHosts.length; i++ ) {
			const nh = networkHosts[ i ];
			// Exact match or a subdomain of a network host.
			if ( host === nh || host.endsWith( '.' + nh ) ) {
				return true;
			}
		}
		return false;
	}

	function sendOutboundClick( destUrl, destHost ) {
		const payload = {
			click_type: 'outbound',
			source_url: window.location.origin + window.location.pathname,
			destination_url: destUrl,
			dest_host: destHost,
		};

		const data = JSON.stringify( payload );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				config.endpoint,
				new Blob( [ data ], { type: 'application/json' } )
			);
		} else {
			fetch( config.endpoint, {
				method: 'POST',
				body: data,
				headers: { 'Content-Type': 'application/json' },
				keepalive: true,
			} );
		}
	}

	// Sends {"input": {...}} to a wp-abilities/v1 .../run endpoint — the same
	// shape view-tracking.js uses for extrachill/track-page-view. cta_click and
	// form_submit are REST-visible abilities (extrachill/track-cta-click,
	// extrachill/track-form-submit), not the extrachill-api /analytics/click
	// route the outbound click above uses.
	function sendAbility( endpoint, input ) {
		if ( ! endpoint ) {
			return;
		}

		const data = JSON.stringify( { input } );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				endpoint,
				new Blob( [ data ], { type: 'application/json' } )
			);
		} else {
			fetch( endpoint, {
				method: 'POST',
				body: data,
				headers: { 'Content-Type': 'application/json' },
				keepalive: true,
			} );
		}
	}

	// Any design-system button class, an explicitly-tracked element, or a
	// submit control — the last covers forms whose "button" has no design-
	// system class at all (still a real call to action). See RULES.md for the
	// canonical button classes.
	const CTA_SELECTOR =
		'.button-1, .button-2, .button-3, .button-danger, [data-ec-track], button[type="submit"], input[type="submit"]';
	const LANDMARK_TAGS = [ 'HEADER', 'MAIN', 'FOOTER', 'NAV', 'ASIDE' ];

	// wp-admin (including the block editor, which renders inside wp-admin) is
	// never a public CTA surface.
	function isExcludedContext() {
		return window.location.pathname.indexOf( '/wp-admin' ) === 0;
	}

	// A click/submit inside an editable region (post content editor, inline
	// rich-text field) is authoring activity, not a reader's call-to-action
	// click. Checks the `contenteditable` attribute directly rather than the
	// `isContentEditable` IDL property, which real browsers compute from it
	// but some DOM test environments do not.
	function isContentEditableContext( el ) {
		return !! (
			el.closest &&
			el.closest( '[contenteditable]:not([contenteditable="false"])' )
		);
	}

	// The nearest ancestor's explicit data-ec-track-placement wins over the
	// nearest HTML5 landmark tag.
	function resolvePlacement( el ) {
		let node = el;
		while ( node ) {
			if (
				node.hasAttribute &&
				node.hasAttribute( 'data-ec-track-placement' )
			) {
				const explicitPlacement = node
					.getAttribute( 'data-ec-track-placement' )
					.trim();
				if ( explicitPlacement ) {
					return explicitPlacement.slice( 0, 40 );
				}
			}
			node = node.parentElement;
		}

		node = el;
		while ( node ) {
			if ( LANDMARK_TAGS.indexOf( node.nodeName ) !== -1 ) {
				return node.nodeName.toLowerCase();
			}
			node = node.parentElement;
		}

		return 'other';
	}

	function resolveLabel( el ) {
		const label =
			el.tagName === 'INPUT'
				? el.value || el.getAttribute( 'value' ) || ''
				: el.textContent || '';

		return label.replace( /\s+/g, ' ' ).trim().slice( 0, 80 );
	}

	// host+path only — query string and fragment stripped. Falls back to the
	// current page when the element has no usable href (a <button> that
	// triggers JS, or a submit control with no separate destination).
	function resolveDest( el ) {
		const href = el.getAttribute && el.getAttribute( 'href' );
		if ( href ) {
			try {
				const url = new URL( href, window.location.href );
				if ( url.protocol === 'http:' || url.protocol === 'https:' ) {
					return ( url.host + url.pathname ).slice( 0, 512 );
				}
			} catch {
				// Fall through to the current-page destination below.
			}
		}
		return ( window.location.host + window.location.pathname ).slice(
			0,
			512
		);
	}

	function trackCtaClick( target ) {
		if ( ! config.ctaEndpoint || isExcludedContext() ) {
			return;
		}

		const el = target.closest ? target.closest( CTA_SELECTOR ) : null;
		if ( ! el || isContentEditableContext( el ) ) {
			return;
		}

		const trackAttr = el.getAttribute && el.getAttribute( 'data-ec-track' );
		if ( 'off' === trackAttr ) {
			return;
		}

		const input = {
			label: resolveLabel( el ),
			dest: resolveDest( el ),
			placement: resolvePlacement( el ),
			route: config.route || 'other',
			source_url: window.location.origin + window.location.pathname,
		};
		if ( trackAttr ) {
			input.cta_override = trackAttr.trim().slice( 0, 80 );
		}

		sendAbility( config.ctaEndpoint, input );
	}

	function trackFormSubmit( form ) {
		if ( ! config.formEndpoint || isExcludedContext() ) {
			return;
		}
		if ( ! form || form.nodeName !== 'FORM' ) {
			return;
		}
		if ( isContentEditableContext( form ) ) {
			return;
		}

		const trackAttr =
			form.getAttribute && form.getAttribute( 'data-ec-track' );
		if ( 'off' === trackAttr ) {
			return;
		}

		// Priority: explicit data-ec-track, then id, then name. A form with
		// none of the three has no stable identity worth reporting on — skip
		// it rather than write an anonymous, indistinguishable form_submit row.
		const name = (
			trackAttr ||
			form.id ||
			form.getAttribute( 'name' ) ||
			''
		).trim();
		if ( ! name ) {
			return;
		}

		sendAbility( config.formEndpoint, {
			form: name.slice( 0, 80 ),
			placement: resolvePlacement( form ),
			route: config.route || 'other',
			source_url: window.location.origin + window.location.pathname,
		} );
	}

	// Delegated capture: one listener on the document catches clicks on any
	// element, including ones added after load. Capture phase so we record the
	// intent before any navigation handler can swallow it. Outbound-click
	// detection (anchor to an off-network host) and cta_click detection (any
	// design-system button / explicitly-tracked element) run independently in
	// the SAME listener — an off-network design-system button can fire both
	// events, which is intended.
	document.addEventListener(
		'click',
		function ( event ) {
			if ( config.endpoint ) {
				// Resolve the anchor the click landed on (could be a child element).
				let anchorEl = event.target;
				while ( anchorEl && anchorEl.nodeName !== 'A' ) {
					anchorEl = anchorEl.parentElement;
				}
				if ( anchorEl && anchorEl.href ) {
					// Only http(s) links resolve to a destination host worth tracking.
					let url;
					try {
						url = new URL( anchorEl.href, window.location.href );
					} catch {
						url = null;
					}
					if (
						url &&
						( url.protocol === 'http:' || url.protocol === 'https:' )
					) {
						const host = url.hostname;
						if ( host && ! isNetworkHost( host ) ) {
							sendOutboundClick( url.origin + url.pathname, host );
						}
					}
				}
			}

			trackCtaClick( event.target );
		},
		true
	);

	// A second delegated listener, but for a DIFFERENT event type (submit, not
	// click) — form_submit has no click-based equivalent, so this cannot be
	// folded into the click listener above.
	document.addEventListener(
		'submit',
		function ( event ) {
			trackFormSubmit( event.target );
		},
		true
	);
} )();
