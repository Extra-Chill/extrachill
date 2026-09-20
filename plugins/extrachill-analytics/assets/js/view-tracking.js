( function () {
	const config = window.ecViewTracking;
	if ( ! config ) {
		return;
	}

	// RFC 4122 version 4 UUID from the platform CSPRNG. `crypto.randomUUID()`
	// covers all modern browsers; the `getRandomValues` fallback covers the
	// rest. Returns '' when no CSPRNG exists and the mint is skipped rather
	// than weakening the id.
	const generateUuidV4 = () => {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		if ( window.crypto && typeof window.crypto.getRandomValues === 'function' ) {
			const bytes = window.crypto.getRandomValues( new Uint8Array( 16 ) );
			bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
			bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
			const hex = Array.from( bytes, ( byte ) =>
				byte.toString( 16 ).padStart( 2, '0' )
			).join( '' );
			return (
				hex.slice( 0, 8 ) +
				'-' +
				hex.slice( 8, 12 ) +
				'-' +
				hex.slice( 12, 16 ) +
				'-' +
				hex.slice( 16, 20 ) +
				'-' +
				hex.slice( 20 )
			);
		}
		return '';
	};

	const hasVisitorCookie = () => {
		const pattern = new RegExp( '(?:^|;\\s*)' + config.cookieName + '=([^;]*)' );
		return pattern.test( document.cookie );
	};

	// Visitor identity is minted HERE, in the browser — never by the server.
	// A response carrying `Set-Cookie` is refused by the edge cache, so the
	// former server-side mint made almost every first-visit HTML response
	// uncacheable; the server now only READS this cookie. Attributes mirror
	// the old server cookie except HttpOnly, which JavaScript cannot set —
	// an accepted trade-off for an anonymous first-party UUID that is never
	// PII:
	// - `domain` is the network-root leading-dot domain computed server-side
	//   and localized below, so ONE id spans every subdomain and cross-site
	//   retention stays measurable.
	// - `Secure` + `SameSite=Lax` are preserved.
	// - Opt-outs are honored the same way the server honored them: GPC
	//   (navigator.globalPrivacyControl) and legacy DNT suppress the mint,
	//   and the server already passes an empty `cookieDomain` (never mint)
	//   for opted-out requests, custom-domain hosts, and non-template
	//   runtimes.
	// - Mint-once: an existing cookie is never re-minted.
	const ensureVisitorCookie = () => {
		if ( ! config.cookieName || ! config.cookieDomain || ! config.cookieMaxAge ) {
			return;
		}
		if (
			window.navigator.globalPrivacyControl === true ||
			window.navigator.doNotTrack === '1' ||
			window.doNotTrack === '1'
		) {
			return;
		}
		if ( hasVisitorCookie() ) {
			return;
		}
		const visitorId = generateUuidV4();
		if ( ! visitorId ) {
			return;
		}
		document.cookie =
			config.cookieName +
			'=' +
			visitorId +
			'; domain=' +
			config.cookieDomain +
			'; path=/' +
			'; max-age=' +
			config.cookieMaxAge +
			'; Secure; SameSite=Lax';
	};

	// Mint BEFORE the beacon fires: the beacon is same-origin, so the browser
	// attaches the freshly-minted cookie and the server-resolved pageview
	// event carries the visitor id even on the very first pageview.
	ensureVisitorCookie();

	if (
		! config.sourcePath ||
		! config.routeFamily ||
		! config.proof ||
		! config.endpoint
	) {
		return;
	}

	const input = {
		source_path: config.sourcePath,
		route_family: config.routeFamily,
		proof: config.proof,
	};
	// wp_localize_script() stringified scalar values in previously cached HTML.
	// Normalize those positive IDs while ensuring its truthy "0" never reaches
	// Core's integer schema as an invalid route-level post_id.
	const postId = Number.parseInt( config.postId, 10 );
	if ( Number.isInteger( postId ) && postId > 0 ) {
		input.post_id = postId;
	}

	// Capture the TRUE referrer client-side. This beacon fires after page load,
	// so the request's own HTTP Referer header is this article page itself —
	// document.referrer is the only place the page the reader navigated FROM is
	// available. The server normalizes it to a host-only `referrer_host` (no
	// query strings, no PII) and drops direct/same-host referrers. Empty for
	// direct traffic.
	if ( document.referrer ) {
		input.referrer = document.referrer;
	}

	const data = JSON.stringify( { input } );

	if ( window.navigator.sendBeacon ) {
		window.navigator.sendBeacon(
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
} )();
