/** Mount the owner adapter using the local WordPress cookie session. */
( function () {
	'use strict';

	const settings = window.ecLinkPageEditShell || {};

	function showStatus( message ) {
		const el = document.getElementById( 'ec-link-page-edit-status' );
		el.querySelector( 'p' ).textContent = message;
		return el.querySelector( 'p' );
	}

	function loadScript( script ) {
		return new Promise( function ( resolve, reject ) {
			const el = document.createElement( 'script' );
			el.src = script.src;
			el.onload = resolve;
			el.onerror = reject;
			document.body.appendChild( el );
		} );
	}

	async function start() {
		try {
			const apiFetch = window.wp.apiFetch;
			const url = new URL( settings.configurationUrl );
			if ( url.origin !== window.location.origin ) {
				throw new Error( 'Expected a same-origin configuration endpoint' );
			}
			const query = new URLSearchParams( window.location.search );
			url.searchParams.set( 'link_page_id', parseInt( query.get( 'link_page' ), 10 ) || 0 );
			const configuration = await apiFetch( {
				url: url.href,
				headers: { 'X-WP-Nonce': settings.nonce },
				credentials: 'same-origin',
			} );
			if ( ! configuration.available ) {
				const status = showStatus( 'Your account does not manage a link page yet. ' );
				const join = document.createElement( 'a' );
				join.href = settings.joinUrl;
				join.textContent = 'Get your link page';
				status.appendChild( join );
				return;
			}
			// Core wp-api-fetch already installs the local REST root and nonce
			// middleware for the adapter's subsequent requests.
			for ( const script of configuration.scripts || [] ) {
				await loadScript( script );
			}
			window.ExtraChillLinkPageEditor.mount(
				document.getElementById( 'ec-link-page-edit-root' ),
				configuration
			);
			document.getElementById( 'ec-link-page-edit-status' ).hidden = true;
		} catch {
			showStatus( 'The editor could not load. Please reload the page.' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
