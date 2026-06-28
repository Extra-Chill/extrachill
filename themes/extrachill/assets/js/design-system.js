/**
 * Living Design System — tweak panel.
 *
 * Lets a visitor edit core-palette colors, the type scale, and border radii
 * live. Every specimen on the page already consumes var(--token), so calling
 * document.documentElement.style.setProperty(token, value) updates the whole
 * page instantly.
 *
 * Persistence is client-side only:
 *   - URL hash encodes the override set, so a tweaked link is shareable.
 *   - "Copy values" copies a :root{} block to the clipboard.
 *   - "Reset" clears overrides back to the shipped tokens.
 *
 * Controls initialise from the REAL shipped values by reading
 * getComputedStyle(document.documentElement) on load, so they reflect root.css
 * (including dark mode) rather than hardcoded defaults.
 *
 * @package
 */

/* global getComputedStyle, history */

( function () {
	'use strict';

	const HASH_PREFIX = 'ds=';
	const root = document.documentElement;

	/**
	 * Read the live computed value of a token from :root.
	 *
	 * @param {string} token CSS custom property (e.g. --accent).
	 * @return {string} Trimmed computed value.
	 */
	function readToken( token ) {
		return getComputedStyle( root ).getPropertyValue( token ).trim();
	}

	/**
	 * Normalise a CSS color to a #rrggbb hex usable by <input type="color">.
	 * Falls back to the raw value's nearest hex via a canvas paint.
	 *
	 * @param {string} value Any CSS color string.
	 * @return {string} #rrggbb (lowercase) or '#000000' on failure.
	 */
	function toHex( value ) {
		if ( ! value ) {
			return '#000000';
		}

		const v = value.trim();
		// Already a 6-digit hex.
		if ( /^#[0-9a-fA-F]{6}$/.test( v ) ) {
			return v.toLowerCase();
		}
		// 3-digit hex -> 6-digit.
		if ( /^#[0-9a-fA-F]{3}$/.test( v ) ) {
			return ( '#' + v[ 1 ] + v[ 1 ] + v[ 2 ] + v[ 2 ] + v[ 3 ] + v[ 3 ] ).toLowerCase();
		}

		// Resolve any other color (rgb(), named, etc.) by painting to a canvas.
		try {
			const ctx = document.createElement( 'canvas' ).getContext( '2d' );
			ctx.fillStyle = '#000000';
			ctx.fillStyle = v;
			const resolved = ctx.fillStyle;
			if ( /^#[0-9a-fA-F]{6}$/.test( resolved ) ) {
				return resolved.toLowerCase();
			}
			// ctx may return rgb(...) — convert.
			const m = resolved.match( /rgba?\((\d+),\s*(\d+),\s*(\d+)/ );
			if ( m ) {
				return (
					'#' +
					[ m[ 1 ], m[ 2 ], m[ 3 ] ]
						.map( function ( n ) {
							const h = parseInt( n, 10 ).toString( 16 );
							return h.length === 1 ? '0' + h : h;
						} )
						.join( '' )
				);
			}
		} catch {
			// Ignore — fall through to default.
		}

		return '#000000';
	}

	/**
	 * Apply an override to :root and update any matching computed-value labels.
	 *
	 * @param {string} token CSS custom property.
	 * @param {string} value New value.
	 */
	function applyOverride( token, value ) {
		root.style.setProperty( token, value );
		refreshComputedLabels( token );
	}

	/**
	 * Collect the current override set (only tokens we actually changed).
	 *
	 * @return {Object} token -> value map.
	 */
	function collectOverrides() {
		const overrides = {};
		const controls = document.querySelectorAll( '[data-ds-token]' );
		controls.forEach( function ( el ) {
			const token = el.getAttribute( 'data-ds-token' );
			const inline = root.style.getPropertyValue( token ).trim();
			if ( inline ) {
				overrides[ token ] = inline;
			}
		} );
		return overrides;
	}

	/**
	 * Update the on-page computed-value labels (data-ds-computed) for a token,
	 * or all of them when no token is given.
	 *
	 * @param {string} [onlyToken] Limit refresh to a single token.
	 */
	function refreshComputedLabels( onlyToken ) {
		const labels = document.querySelectorAll( '[data-ds-computed]' );
		labels.forEach( function ( el ) {
			const token = el.getAttribute( 'data-ds-computed' );
			if ( onlyToken && token !== onlyToken ) {
				return;
			}
			el.textContent = readToken( token );
		} );
	}

	/**
	 * Initialise every control from the live computed value.
	 */
	function initControls() {
		document.querySelectorAll( '[data-ds-control]' ).forEach( function ( input ) {
			const token = input.getAttribute( 'data-ds-token' );
			const type = input.getAttribute( 'data-ds-control' );
			const current = readToken( token );

			if ( type === 'color' ) {
				input.value = toHex( current );
			} else {
				input.value = current;
			}

			input.addEventListener( 'input', function () {
				applyOverride( token, input.value );
				writeHash();
			} );
		} );
	}

	/**
	 * Serialize overrides into the URL hash (shareable as-is).
	 */
	function writeHash() {
		const overrides = collectOverrides();
		const keys = Object.keys( overrides );
		if ( ! keys.length ) {
			// Clear hash without adding a history entry.
			history.replaceState( null, '', window.location.pathname + window.location.search );
			return;
		}
		const encoded = encodeURIComponent( JSON.stringify( overrides ) );
		history.replaceState( null, '', '#' + HASH_PREFIX + encoded );
	}

	/**
	 * Read overrides from the URL hash and apply them on load.
	 */
	function applyHash() {
		const hash = window.location.hash.replace( /^#/, '' );
		if ( hash.indexOf( HASH_PREFIX ) !== 0 ) {
			return;
		}
		const raw = hash.slice( HASH_PREFIX.length );
		let overrides;
		try {
			overrides = JSON.parse( decodeURIComponent( raw ) );
		} catch {
			return;
		}
		if ( ! overrides || typeof overrides !== 'object' ) {
			return;
		}

		Object.keys( overrides ).forEach( function ( token ) {
			applyOverride( token, overrides[ token ] );
		} );

		// Sync the controls to the applied overrides.
		document.querySelectorAll( '[data-ds-control]' ).forEach( function ( input ) {
			const token = input.getAttribute( 'data-ds-token' );
			if ( ! ( token in overrides ) ) {
				return;
			}
			if ( input.getAttribute( 'data-ds-control' ) === 'color' ) {
				input.value = toHex( overrides[ token ] );
			} else {
				input.value = overrides[ token ];
			}
		} );
	}

	/**
	 * Build a CSS :root{} block from the current overrides.
	 *
	 * @return {string} CSS text, or a comment when there are no overrides.
	 */
	function buildCssBlock() {
		const overrides = collectOverrides();
		const keys = Object.keys( overrides );
		if ( ! keys.length ) {
			return '/* No token overrides — page reflects shipped @extrachill/tokens. */';
		}
		const lines = keys.map( function ( token ) {
			return '  ' + token + ': ' + overrides[ token ] + ';';
		} );
		return ':root {\n' + lines.join( '\n' ) + '\n}';
	}

	/**
	 * Copy text to the clipboard with a graceful fallback.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise} Resolves when the copy succeeds, rejects on failure.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				const ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
				resolve();
			} catch ( e ) {
				reject( e );
			}
		} );
	}

	/**
	 * Show a transient feedback message in the panel.
	 *
	 * @param {string} message Text to display.
	 */
	function feedback( message ) {
		const el = document.querySelector( '[data-ds-feedback]' );
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		window.clearTimeout( feedback._t );
		feedback._t = window.setTimeout( function () {
			el.textContent = '';
		}, 2500 );
	}

	/**
	 * Reset all overrides back to the shipped tokens.
	 */
	function resetOverrides() {
		document.querySelectorAll( '[data-ds-token]' ).forEach( function ( el ) {
			const token = el.getAttribute( 'data-ds-token' );
			root.style.removeProperty( token );
		} );
		initControls(); // Re-read shipped computed values into the controls.
		refreshComputedLabels();
		writeHash();
		feedback( 'Reset to shipped tokens.' );
	}

	/**
	 * Toggle the tweak panel open/closed.
	 */
	function togglePanel() {
		const panel = document.getElementById( 'ds-tweak-panel' );
		if ( ! panel ) {
			return;
		}
		const toggle = document.getElementById( 'ds-tweak-toggle' );
		const willShow = panel.hasAttribute( 'hidden' );
		if ( willShow ) {
			panel.removeAttribute( 'hidden' );
			document.body.classList.add( 'ds-tweak-open' );
		} else {
			panel.setAttribute( 'hidden', '' );
			document.body.classList.remove( 'ds-tweak-open' );
		}
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', String( willShow ) );
		}
		panel.querySelectorAll( '[data-ds-action="toggle"]' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-expanded', String( willShow ) );
		} );
	}

	/**
	 * Wire the action buttons (copy / share / reset / toggle).
	 */
	function initActions() {
		document.querySelectorAll( '[data-ds-action]' ).forEach( function ( btn ) {
			const action = btn.getAttribute( 'data-ds-action' );
			btn.addEventListener( 'click', function () {
				if ( action === 'copy' ) {
					copyText( buildCssBlock() ).then(
						function () {
							feedback( 'Copied token values.' );
						},
						function () {
							feedback( 'Copy failed — select and copy manually.' );
						}
					);
				} else if ( action === 'share' ) {
					writeHash();
					copyText( window.location.href ).then(
						function () {
							feedback( 'Copied shareable link.' );
						},
						function () {
							feedback( 'Copy failed — copy the URL manually.' );
						}
					);
				} else if ( action === 'reset' ) {
					resetOverrides();
				} else if ( action === 'toggle' ) {
					togglePanel();
				}
			} );
		} );
	}

	/**
	 * Reveal the panel + toggle (hidden by default so the page is usable
	 * without JS) and boot everything.
	 */
	function boot() {
		const panel = document.getElementById( 'ds-tweak-panel' );
		const toggle = document.getElementById( 'ds-tweak-toggle' );

		refreshComputedLabels();
		initControls();
		initActions();
		applyHash();
		refreshComputedLabels();

		// Reveal the controls now that JS is active.
		if ( toggle ) {
			toggle.removeAttribute( 'hidden' );
		}
		if ( panel ) {
			// Open the panel by default on wide viewports, collapsed on narrow.
			if ( window.matchMedia( '(min-width: 1024px)' ).matches ) {
				panel.removeAttribute( 'hidden' );
				document.body.classList.add( 'ds-tweak-open' );
				if ( toggle ) {
					toggle.setAttribute( 'aria-expanded', 'true' );
				}
			}
		}

		// Keep specimens in sync if the OS color scheme flips while open.
		if ( window.matchMedia ) {
			window
				.matchMedia( '(prefers-color-scheme: dark)' )
				.addEventListener( 'change', function () {
					// Only refresh controls/labels for tokens without overrides.
					const overrides = collectOverrides();
					document.querySelectorAll( '[data-ds-control]' ).forEach( function ( input ) {
						const token = input.getAttribute( 'data-ds-token' );
						if ( token in overrides ) {
							return;
						}
						const current = readToken( token );
						input.value =
							input.getAttribute( 'data-ds-control' ) === 'color'
								? toHex( current )
								: current;
					} );
					refreshComputedLabels();
				} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
