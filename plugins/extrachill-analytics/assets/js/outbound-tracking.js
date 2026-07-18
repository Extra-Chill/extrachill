( function () {
	var config = window.ecOutboundTracking;
	if ( ! config || ! config.endpoint ) {
		return;
	}

	// Hosts that belong to the Extra Chill network — a click to any of these is
	// an INTERNAL hop (already measured by the conversion map), never an
	// outbound exit. Lower-cased for case-insensitive comparison.
	var networkHosts = ( config.networkHosts || [] ).map( function ( h ) {
		return String( h ).toLowerCase();
	} );

	function isNetworkHost( host ) {
		host = String( host ).toLowerCase();
		for ( var i = 0; i < networkHosts.length; i++ ) {
			var nh = networkHosts[ i ];
			// Exact match or a subdomain of a network host.
			if ( host === nh || host.endsWith( '.' + nh ) ) {
				return true;
			}
		}
		return false;
	}

	function send( destUrl, destHost ) {
		var payload = {
			click_type: 'outbound',
			source_url: window.location.origin + window.location.pathname,
			destination_url: destUrl,
			dest_host: destHost,
		};

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
	}

	// Delegated capture: one listener on the document catches clicks on any
	// anchor, including ones added after load. Capture phase so we record the
	// intent before any navigation handler can swallow it.
	document.addEventListener(
		'click',
		function ( event ) {
			// Resolve the anchor the click landed on (could be a child element).
			var el = event.target;
			while ( el && el.nodeName !== 'A' ) {
				el = el.parentElement;
			}
			if ( ! el || ! el.href ) {
				return;
			}

			// Only http(s) links resolve to a destination host worth tracking.
			var url;
			try {
				url = new URL( el.href, window.location.href );
			} catch ( e ) {
				return;
			}
			if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
				return;
			}

			var host = url.hostname;
			if ( ! host || isNetworkHost( host ) ) {
				return;
			}

			send( url.origin + url.pathname, host );
		},
		true
	);
} )();
