( function () {
	var config = window.ecViewTracking;
	if ( ! config || ! config.postId || ! config.endpoint ) {
		return;
	}

	var payload = { post_id: config.postId };

	// Echo the server-minted first-party visitor id when present. Empty/absent
	// when the visitor opted out via GPC/DNT — the pageview is still recorded,
	// just without an id.
	if ( config.visitorId ) {
		payload.visitor_id = config.visitorId;
	}

	// Capture the TRUE referrer client-side. This beacon fires after page load,
	// so the request's own HTTP Referer header is this article page itself —
	// document.referrer is the only place the page the reader navigated FROM is
	// available. The server normalizes it to a host-only `referrer_host` (no
	// query strings, no PII) and drops direct/same-host referrers. Empty for
	// direct traffic.
	if ( document.referrer ) {
		payload.referrer = document.referrer;
	}

	var data = JSON.stringify( payload );

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
} )();
