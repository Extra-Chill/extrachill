/**
 * Link Page /edit shell.
 *
 * The public Link Page host does not share the owner sites' auth cookies, so
 * this shell authenticates with a bearer token from the host site's handoff
 * (returned in the URL fragment, never sent to a server), fetches the editor
 * configuration for that user, points wp.apiFetch at the host API with the
 * token, loads the owner adapter scripts the configuration lists, and mounts
 * the shared Link Page editor. Owner-neutral: every owner-specific value
 * comes from the configuration.
 */
( function () {
	'use strict';

	var TOKEN_KEY = 'ecLinkEditToken';
	var REAUTH_KEY = 'ecLinkEditShellReauth';
	var settings = window.ecLinkPageEditShell || {};

	function statusEl() {
		return document.getElementById( 'ec-link-page-edit-status' );
	}

	function setStatus( kind, html ) {
		var el = statusEl();
		if ( ! el ) {
			return;
		}
		el.className = 'notice notice-' + kind;
		el.hidden = false;
		el.innerHTML = '<p>' + html + '</p>';
	}

	function hideStatus() {
		var el = statusEl();
		if ( el ) {
			el.hidden = true;
		}
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function readToken() {
		try {
			var parsed = JSON.parse( window.localStorage.getItem( TOKEN_KEY ) || 'null' );
			if ( ! parsed || ! parsed.token || ! parsed.expiresAt || Date.now() >= parsed.expiresAt - 5000 ) {
				window.localStorage.removeItem( TOKEN_KEY );
				return null;
			}
			return parsed;
		} catch ( e ) {
			return null;
		}
	}

	function storeToken( token, expiresAtSeconds ) {
		var record = {
			token: token,
			expiresAt: expiresAtSeconds ? expiresAtSeconds * 1000 : Date.now() + 15 * 60 * 1000,
		};
		try {
			window.localStorage.setItem( TOKEN_KEY, JSON.stringify( record ) );
		} catch ( e ) {}
		return record;
	}

	function clearToken() {
		try {
			window.localStorage.removeItem( TOKEN_KEY );
		} catch ( e ) {}
	}

	/** Take a token from the handoff fragment and scrub it from the URL. */
	function consumeFragment() {
		var hash = window.location.hash || '';
		if ( hash.length < 2 ) {
			return { attempted: false, record: null };
		}
		var params = new URLSearchParams( hash.substring( 1 ) );
		var result = { attempted: false, record: null };
		if ( params.has( 'ec_link_token' ) ) {
			result.attempted = true;
			result.record = storeToken( params.get( 'ec_link_token' ), parseInt( params.get( 'ec_link_token_expires' ) || '0', 10 ) );
		} else if ( params.has( 'ec_link_token_none' ) ) {
			result.attempted = true;
		}
		[ 'ec_link_token', 'ec_link_token_none', 'ec_link_token_expires' ].forEach( function ( key ) {
			params.delete( key );
		} );
		var rest = params.toString();
		try {
			window.history.replaceState( null, '', window.location.pathname + window.location.search + ( rest ? '#' + rest : '' ) );
		} catch ( e ) {}
		return result;
	}

	function returnUrl() {
		return window.location.href.split( '#' )[ 0 ];
	}

	function handoffUrl() {
		var base = settings.handoffUrl || '';
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'return=' + encodeURIComponent( returnUrl() );
	}

	function goToHandoff() {
		window.location.assign( handoffUrl() );
	}

	function showSignIn() {
		var login = settings.loginUrl
			? settings.loginUrl + ( settings.loginUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'redirect_to=' + encodeURIComponent( handoffUrl() )
			: '';
		setStatus(
			'info',
			'Sign in to edit your link page.' +
				( login ? ' <a class="button-1 button-medium" href="' + escapeHtml( login ) + '">Sign in</a>' : '' )
		);
	}

	function showNoPage() {
		setStatus(
			'info',
			'Your account does not manage a link page yet.' +
				( settings.joinUrl ? ' <a class="button-1 button-medium" href="' + escapeHtml( settings.joinUrl ) + '">Get your link page</a>' : '' )
		);
	}

	/** Route every wp.apiFetch call to the host API with the bearer token. */
	function configureApiFetch( apiRoot, token ) {
		var root = String( apiRoot || '' ).replace( /\/+$/, '' );
		window.wp.apiFetch.use( function ( options, next ) {
			var request = Object.assign( {}, options );
			if ( typeof request.path === 'string' && ! request.url ) {
				request.url = root + '/' + request.path.replace( /^\/+/, '' );
			}
			delete request.path;
			request.credentials = 'omit';
			request.headers = Object.assign( {}, request.headers, { Authorization: 'Bearer ' + token } );
			return next( request );
		} );
	}

	function loadScripts( scripts ) {
		return ( scripts || [] ).reduce( function ( chain, script ) {
			return chain.then( function () {
				return new Promise( function ( resolve, reject ) {
					var el = document.createElement( 'script' );
					el.src = script.src;
					el.onload = resolve;
					el.onerror = function () {
						reject( new Error( 'script' ) );
					};
					document.body.appendChild( el );
				} );
			} );
		}, Promise.resolve() );
	}

	function warnBeforeExpiry( record ) {
		var warnAt = record.expiresAt - 5 * 60 * 1000 - Date.now();
		if ( warnAt > 0 && warnAt < 2147483647 ) {
			window.setTimeout( function () {
				setStatus( 'info', 'Your editing session ends in 5 minutes. Save your changes, then reload this page to keep editing.' );
			}, warnAt );
		}
	}

	function start() {
		if ( ! settings.configurationUrl || ! window.wp || ! window.wp.apiFetch || ! window.ExtraChillLinkPageEditor ) {
			setStatus( 'error', 'Link Page editing is unavailable right now.' );
			return;
		}
		var fragment = consumeFragment();
		var record = fragment.record || readToken();
		if ( ! record ) {
			if ( fragment.attempted ) {
				showSignIn();
			} else {
				goToHandoff();
			}
			return;
		}

		var query = new URLSearchParams( window.location.search );
		var linkPageId = parseInt( query.get( 'link_page' ) || '0', 10 ) || 0;
		var url = settings.configurationUrl + ( settings.configurationUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'link_page_id=' + linkPageId;

		window
			.fetch( url, { headers: { Authorization: 'Bearer ' + record.token }, credentials: 'omit' } )
			.then( function ( response ) {
				if ( 401 === response.status || 403 === response.status ) {
					clearToken();
					var retried = false;
					try {
						retried = !! window.sessionStorage.getItem( REAUTH_KEY );
						window.sessionStorage.setItem( REAUTH_KEY, '1' );
					} catch ( e ) {
						retried = true;
					}
					if ( retried ) {
						showSignIn();
					} else {
						goToHandoff();
					}
					return null;
				}
				if ( ! response.ok ) {
					throw new Error( 'configuration' );
				}
				return response.json();
			} )
			.then( function ( configuration ) {
				if ( ! configuration ) {
					return;
				}
				try {
					window.sessionStorage.removeItem( REAUTH_KEY );
				} catch ( e ) {}
				if ( ! configuration.available ) {
					showNoPage();
					return;
				}
				configureApiFetch( configuration.apiRoot, record.token );
				return loadScripts( configuration.scripts ).then( function () {
					hideStatus();
					window.ExtraChillLinkPageEditor.mount( document.getElementById( 'ec-link-page-edit-root' ), configuration );
					warnBeforeExpiry( record );
				} );
			} )
			.catch( function () {
				setStatus( 'error', 'The editor could not load. Please reload the page.' );
			} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
