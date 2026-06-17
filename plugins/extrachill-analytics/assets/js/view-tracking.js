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
