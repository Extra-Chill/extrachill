/**
 * Cloudflare Turnstile explicit-render bootstrap.
 *
 * Loaded as the `onload` target of api.js in EXPLICIT mode
 * (`?render=explicit&onload=ecTurnstileBoot`). Instead of letting Cloudflare's
 * implicit auto-render scan and batch-render every `.cf-turnstile` element in a
 * single pass — where one widget's bad config aborts the whole batch and takes
 * out every sibling widget on the page — this boot renders EACH widget in its
 * own `turnstile.render()` call wrapped in try/catch. A single broken widget
 * can then only break itself; its siblings still render and produce tokens.
 *
 * Generic by design: this reads only `.cf-turnstile` + `data-*` attributes. It
 * never knows or cares which consumer (newsletter, events, contact, auth) a
 * widget belongs to. No fetch, no AJAX — pure client bootstrap for a 3rd-party
 * widget. This file is intentionally dependency-free.
 *
 * @package ExtraChill\Network
 */
( function () {
	'use strict';

	/**
	 * Resolve a global callback function by name, but only if it actually
	 * exists. A `data-callback` naming an undefined global must be skipped
	 * silently — never let a missing callback abort the render. That dangling
	 * callback is the exact bug class this hardening exists to kill.
	 *
	 * @param {string|null} name Global function name from a data-* attribute.
	 * @return {Function|undefined} The resolved function, or undefined.
	 */
	function resolveCallback( name ) {
		if ( ! name ) {
			return undefined;
		}
		var fn = window[ name ];
		return ( typeof fn === 'function' ) ? fn : undefined;
	}

	/**
	 * Build the render() options object for a single widget from its declared
	 * data-* attributes. Only attributes the consumer actually set are mapped;
	 * Cloudflare applies its own defaults for anything omitted.
	 *
	 * @param {Element} el The .cf-turnstile element.
	 * @return {Object} Options for window.turnstile.render().
	 */
	function buildOptions( el ) {
		var opts = {};

		var sitekey = el.getAttribute( 'data-sitekey' );
		if ( sitekey ) {
			opts.sitekey = sitekey;
		}

		var simple = {
			'data-size': 'size',
			'data-theme': 'theme',
			'data-appearance': 'appearance',
			'data-action': 'action',
			'data-cdata': 'cData',
			'data-language': 'language',
			'data-retry': 'retry',
			'data-execution': 'execution'
		};
		Object.keys( simple ).forEach( function ( attr ) {
			var value = el.getAttribute( attr );
			if ( value !== null && value !== '' ) {
				opts[ simple[ attr ] ] = value;
			}
		} );

		// Callbacks: map data-callback / data-expired-callback /
		// data-error-callback to the named global ONLY when that global is a
		// real function. A dangling name is dropped silently.
		var callbacks = {
			'data-callback': 'callback',
			'data-expired-callback': 'expired-callback',
			'data-error-callback': 'error-callback',
			'data-timeout-callback': 'timeout-callback'
		};
		Object.keys( callbacks ).forEach( function ( attr ) {
			var fn = resolveCallback( el.getAttribute( attr ) );
			if ( fn ) {
				opts[ callbacks[ attr ] ] = fn;
			}
		} );

		return opts;
	}

	/**
	 * Has this element already been rendered? Cloudflare throws if you call
	 * render() twice on the same element, so the loop must be idempotent. We
	 * mark each successfully-rendered element with data-ec-turnstile-rendered
	 * and also treat any element that already contains an injected child
	 * (iframe/widget) as rendered.
	 *
	 * @param {Element} el The .cf-turnstile element.
	 * @return {boolean} True if already rendered.
	 */
	function isRendered( el ) {
		return el.getAttribute( 'data-ec-turnstile-rendered' ) === '1'
			|| el.querySelector( 'iframe' ) !== null;
	}

	/**
	 * Render every .cf-turnstile widget on the page, each in isolation.
	 * One widget throwing is caught and logged; the loop continues.
	 */
	function renderAll() {
		if ( ! window.turnstile || typeof window.turnstile.render !== 'function' ) {
			return;
		}

		var widgets = document.querySelectorAll( '.cf-turnstile' );
		for ( var i = 0; i < widgets.length; i++ ) {
			var el = widgets[ i ];
			if ( isRendered( el ) ) {
				continue;
			}
			try {
				window.turnstile.render( el, buildOptions( el ) );
				el.setAttribute( 'data-ec-turnstile-rendered', '1' );
			} catch ( err ) {
				// Isolation guarantee: a bad widget breaks only itself.
				if ( window.console && window.console.warn ) {
					window.console.warn( 'ec-turnstile: failed to render a widget; siblings unaffected.', el, err );
				}
			}
		}
	}

	// api.js calls window.ecTurnstileBoot once it has loaded (onload param).
	window.ecTurnstileBoot = renderAll;

	// Defensive: if this script somehow evaluates after turnstile is already
	// present (e.g. cached api.js, re-enqueue), render immediately too. The
	// idempotency guard makes a double-invocation safe.
	if ( window.turnstile && typeof window.turnstile.render === 'function' ) {
		renderAll();
	}
} )();
