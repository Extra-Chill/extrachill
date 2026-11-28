/** Keep the public HTML cacheable; discover edit access via a local session. */
( function () {
	'use strict';

	const ATTEMPT = 'ecLinkEditHandoffAttempted';
	const KNOWN_EDITOR = 'ecLinkEditKnownEditor';

	function cameFromNetwork( networkUrl ) {
		if ( ! networkUrl || ! document.referrer ) {
			return false;
		}
		const network = new URL( networkUrl ).hostname;
		const from = new URL( document.referrer ).hostname;
		return from === network || from.endsWith( '.' + network );
	}

	function maybeSignIn( handoffUrl, networkUrl ) {
		if ( ! handoffUrl ) {
			return;
		}
		try {
			if ( window.sessionStorage.getItem( ATTEMPT ) ) {
				window.localStorage.removeItem( KNOWN_EDITOR );
				document.cookie = 'ec_link_editor=; Max-Age=0; Path=/; SameSite=Lax';
				return;
			}
			if (
				window.localStorage.getItem( KNOWN_EDITOR ) !== '1' &&
				! /(?:^|;\s*)ec_link_editor=1(?:;|$)/.test( document.cookie ) &&
				! cameFromNetwork( networkUrl )
			) {
				return;
			}
			window.sessionStorage.setItem( ATTEMPT, '1' );
			const url = new URL( handoffUrl );
			url.searchParams.set( 'return', window.location.origin + window.location.pathname );
			window.location.assign( url.href );
		} catch {
			// Without a working loop guard, don't attempt automatic sign-in.
		}
	}

	async function init() {
		const data = document.body.dataset;
		if ( ! data.extrchSessionUrl || ! data.extrchPermissionsApiUrl ) {
			return;
		}
		try {
			const sessionUrl = new URL( data.extrchSessionUrl );
			const permissionsUrl = new URL( data.extrchPermissionsApiUrl );
			if ( sessionUrl.origin !== window.location.origin || permissionsUrl.origin !== window.location.origin ) {
				return;
			}
			const session = await fetch( sessionUrl.href, { credentials: 'same-origin', cache: 'no-store' } );
			if ( session.status === 401 ) {
				maybeSignIn( data.extrchSessionHandoffUrl, data.extrchNetworkUrl );
				return;
			}
			if ( ! session.ok ) {
				return;
			}
			const { nonce } = await session.json();
			const response = await fetch( permissionsUrl.href, {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-WP-Nonce': nonce },
			} );
			if ( ! response.ok ) {
				return;
			}
			const permission = await response.json();
			if ( permission.can_edit !== true || ! permission.manage_url ) {
				return;
			}
			const editUrl = new URL( permission.manage_url );
			if ( editUrl.origin !== window.location.origin ) {
				return;
			}
			const button = document.createElement( 'a' );
			button.href = editUrl.href;
			button.className = 'extrch-link-page-edit-btn';
			button.setAttribute( 'aria-label', 'Edit link page' );
			button.innerHTML = '<i class="fas fa-pencil-alt"></i>';
			document.body.appendChild( button );
			window.sessionStorage.removeItem( ATTEMPT );
			window.localStorage.setItem( KNOWN_EDITOR, '1' );
		} catch {
			// Public Link Pages work even if editor discovery is unavailable.
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
