/**
 * Cross-Site Bridge Instrumentation
 *
 * Fires two sibling analytics events for the shared cross-site link engine:
 *
 *   - impression: one per bridge link that reaches 50% viewport visibility,
 *                 deduped by element within the page load.
 *   - click:      the first click on that same element within the page load.
 *
 * Both ship via navigator.sendBeacon (no AJAX, fire-and-forget, survives the
 * navigation a click triggers) to the canonical extrachill-api analytics
 * routes — clicks to /analytics/click (click_type=bridge), impressions to
 * /analytics/impression (impression_type=bridge). Requiring page JavaScript to
 * execute filters non-JS crawlers and prefetch requests that do not execute the
 * page. It does not establish human identity or exclude JS-capable automation.
 *
 * Destination context (dest_site, term) is read from the link's existing UTM
 * params, so no new markup contract is introduced — the bridge consumers
 * already UTM-tag every outbound URL. Each element is one client-side
 * opportunity, but the two events persist through independent best-effort
 * requests. Rows may be missing under asymmetric loss or duplicated when a
 * retry follows an ambiguous failure, so their stored ratio is not
 * mathematically bounded.
 */
( function () {
	var config = window.ecBridgeInstrumentation;
	if (
		! config ||
		! config.clickEndpoint ||
		! config.impressionEndpoint ||
		! config.linkClass
	) {
		return;
	}

	/**
	 * Send an event via sendBeacon, falling back to keepalive fetch when the
	 * browser rejects beacon queueing. A failed fetch is retried once.
	 *
	 * @param {string} endpoint Target REST endpoint.
	 * @param {Object} payload  Event payload.
	 */
	function send( endpoint, payload ) {
		var data = JSON.stringify( payload );
		var blob = new Blob( [ data ], { type: 'application/json' } );
		var beaconAccepted = false;

		if ( navigator.sendBeacon ) {
			try {
				beaconAccepted = navigator.sendBeacon( endpoint, blob );
			} catch ( error ) {
				beaconAccepted = false;
			}
		}

		if ( beaconAccepted || ! window.fetch ) {
			return;
		}

		function sendWithFetch( retriesRemaining ) {
			var request;
			try {
				request = window.fetch( endpoint, {
					method: 'POST',
					body: data,
					headers: { 'Content-Type': 'application/json' },
					keepalive: true,
				} );
			} catch ( error ) {
				if ( retriesRemaining > 0 ) {
					sendWithFetch( retriesRemaining - 1 );
				}
				return;
			}

			request
				.then( function ( response ) {
					if ( ! response.ok && retriesRemaining > 0 ) {
						sendWithFetch( retriesRemaining - 1 );
					}
				} )
				.catch( function () {
					if ( retriesRemaining > 0 ) {
						sendWithFetch( retriesRemaining - 1 );
					}
				} );
		}

		sendWithFetch( 1 );
	}

	/**
	 * Read a query param from a URL string, returning '' when absent.
	 *
	 * @param {string} url   URL to parse.
	 * @param {string} param Param name.
	 * @return {string} Param value or ''.
	 */
	function param( url, param ) {
		try {
			return new URL( url, window.location.origin ).searchParams.get( param ) || '';
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Whether a bridge link is unavailable to the visitor.
	 *
	 * Hidden experiment candidates may already exist in cache-neutral markup.
	 * They become measurable only after their owning consumer activates them.
	 *
	 * @param {Element} link Bridge link element.
	 * @return {boolean} Whether the link is hidden or inert.
	 */
	function isUnavailable( link ) {
		return !! (
			link.closest &&
			link.closest( '[hidden], [inert], [aria-hidden="true"]' )
		);
	}

	var matchingLinks = document.getElementsByClassName( config.linkClass );
	var links = [];
	for ( var linkIndex = 0; linkIndex < matchingLinks.length; linkIndex++ ) {
		links.push( matchingLinks[ linkIndex ] );
	}
	var exposedLinks = [];
	var clickedLinks = [];
	var exposureThreshold = 0.5;
	var fallbackDuration = 30000;

	/**
	 * Record one viewport exposure for a bridge element.
	 *
	 * @param {Element} link Bridge link element.
	 */
	function expose( link ) {
		if ( exposedLinks.indexOf( link ) !== -1 ) {
			return true;
		}
		if ( isUnavailable( link ) ) {
			return false;
		}

		exposedLinks.push( link );

		var href = link.getAttribute( 'href' ) || '';
		send( config.impressionEndpoint, {
			impression_type: 'bridge',
			source_url: window.location.href,
			source_post: config.sourcePost || 0,
			source_site: config.sourceSite || '',
			dest_site: param( href, 'utm_campaign' ),
			term: link.textContent ? link.textContent.trim() : '',
		} );

		return true;
	}

	/**
	 * Check whether at least half of an element is inside the viewport.
	 *
	 * @param {Element} link Bridge link element.
	 * @return {boolean} Whether the element meets the exposure threshold.
	 */
	function isVisible( link ) {
		if ( isUnavailable( link ) ) {
			return false;
		}

		var rect = link.getBoundingClientRect();
		var width = Math.max(
			0,
			Math.min( rect.right, window.innerWidth ) - Math.max( rect.left, 0 )
		);
		var height = Math.max(
			0,
			Math.min( rect.bottom, window.innerHeight ) - Math.max( rect.top, 0 )
		);
		var area = Math.max( 0, rect.width * rect.height );

		return area > 0 && ( width * height ) / area >= exposureThreshold;
	}

	/**
	 * Observe exposures without leaving permanent scroll handlers in browsers
	 * that do not support IntersectionObserver.
	 */
	function observeExposures() {
		if ( ! links || links.length === 0 ) {
			return;
		}

		if ( 'IntersectionObserver' in window ) {
			var observer = new window.IntersectionObserver(
				function ( entries ) {
					for ( var i = 0; i < entries.length; i++ ) {
						if (
							entries[ i ].isIntersecting &&
							entries[ i ].intersectionRatio >= exposureThreshold
						) {
							if ( expose( entries[ i ].target ) ) {
								observer.unobserve( entries[ i ].target );
							}
						}
					}
				},
				{ threshold: [ exposureThreshold ] }
			);

			for ( var i = 0; i < links.length; i++ ) {
				observer.observe( links[ i ] );
			}
			return;
		}

		var stopped = false;
		var timeoutId;
		function stopFallback() {
			if ( stopped ) {
				return;
			}
			stopped = true;
			window.removeEventListener( 'scroll', checkFallback );
			window.removeEventListener( 'resize', checkFallback );
			window.clearTimeout( timeoutId );
		}

		function checkFallback() {
			for ( var i = 0; i < links.length; i++ ) {
				if (
					exposedLinks.indexOf( links[ i ] ) === -1 &&
					isVisible( links[ i ] )
				) {
					expose( links[ i ] );
				}
			}

			if ( exposedLinks.length >= links.length ) {
				stopFallback();
			}
		}

		window.addEventListener( 'scroll', checkFallback );
		window.addEventListener( 'resize', checkFallback );
		timeoutId = window.setTimeout( stopFallback, fallbackDuration );
		checkFallback();
	}

	observeExposures();

	// --- Click: one delegated listener instruments every bridge consumer. Only
	// links present when this deferred script initializes are eligible; dynamic
	// placements need a future explicit lifecycle contract. A click proves
	// exposure, so ensure its matching impression is attempted before the click.
	// Navigation is never intercepted, including for ineligible dynamic links.
	document.addEventListener(
		'click',
		function ( event ) {
			var target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}

			var link = target.closest( '.' + config.linkClass );
			if ( ! link || links.indexOf( link ) === -1 ) {
				return;
			}
			if ( clickedLinks.indexOf( link ) !== -1 || isUnavailable( link ) ) {
				return;
			}

			var href = link.getAttribute( 'href' ) || '';
			expose( link );
			clickedLinks.push( link );

			send( config.clickEndpoint, {
				click_type: 'bridge',
				source_url: window.location.href,
				source_post: config.sourcePost || 0,
				source_site: config.sourceSite || '',
				// Destination context is carried by the link's existing UTM params.
				dest_site: param( href, 'utm_campaign' ),
				term: link.textContent ? link.textContent.trim() : '',
			} );
		},
		true
	);
} )();
