/**
 * Android app prompt (extrachill#1416).
 *
 * Reveals the server-rendered, hidden `[data-ec-app-prompt]` notice only when:
 * - the user agent is Android;
 * - it has not been dismissed in the last 30 days;
 * - this is not a logged-out visitor's first pageview landing on an article.
 *
 * Pages are full-page cached, so every gate lives here, not in PHP.
 *
 * @param {Object} root Global object (window in browsers, globalThis in Node tests).
 */
( function ( root ) {
	const DISMISS_KEY = 'ec_app_prompt_dismissed';
	const VIEWS_KEY = 'ec_app_prompt_views';
	const DISMISS_TTL_MS = 30 * 24 * 60 * 60 * 1000;

	/**
	 * Pure gating decision, exported for tests.
	 *
	 * @param {Object}  state             Gating inputs.
	 * @param {string}  state.userAgent   navigator.userAgent.
	 * @param {number}  state.dismissedAt Dismissal timestamp in ms (0 if never).
	 * @param {number}  state.now         Current timestamp in ms.
	 * @param {boolean} state.isArticle   Current page is a single article.
	 * @param {boolean} state.isLoggedIn  Visitor is logged in.
	 * @param {number}  state.priorViews  Pageviews recorded before this one.
	 * @return {boolean} Whether to show the prompt.
	 */
	function shouldShow( state ) {
		if ( ! /Android/i.test( state.userAgent || '' ) ) {
			return false;
		}

		const dismissedAt = Number( state.dismissedAt ) || 0;
		if ( dismissedAt > 0 && state.now - dismissedAt < DISMISS_TTL_MS ) {
			return false;
		}

		if ( state.isArticle && ! state.isLoggedIn && ( Number( state.priorViews ) || 0 ) < 1 ) {
			return false;
		}

		return true;
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { shouldShow, DISMISS_TTL_MS };
		return;
	}

	const doc = root.document;
	const prompt = doc && doc.querySelector( '[data-ec-app-prompt]' );
	if ( ! prompt ) {
		return;
	}

	function storageGet( key ) {
		try {
			return root.localStorage.getItem( key );
		} catch {
			return null;
		}
	}

	function storageSet( key, value ) {
		try {
			root.localStorage.setItem( key, String( value ) );
		} catch {}
	}

	function cookieGet( name ) {
		const match = doc.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	function readDismissedAt() {
		return Math.max( Number( storageGet( DISMISS_KEY ) ) || 0, Number( cookieGet( DISMISS_KEY ) ) || 0 );
	}

	const priorViews = Number( storageGet( VIEWS_KEY ) ) || 0;
	storageSet( VIEWS_KEY, priorViews + 1 );

	const show = shouldShow( {
		userAgent: root.navigator.userAgent,
		dismissedAt: readDismissedAt(),
		now: Date.now(),
		isArticle: prompt.getAttribute( 'data-article' ) === '1',
		isLoggedIn: doc.body.classList.contains( 'logged-in' ),
		priorViews,
	} );

	if ( ! show ) {
		return;
	}

	prompt.hidden = false;

	const dismiss = prompt.querySelector( '[data-ec-app-prompt-dismiss]' );
	if ( dismiss ) {
		dismiss.addEventListener( 'click', function () {
			const now = Date.now();
			const domain = prompt.getAttribute( 'data-cookie-domain' );
			storageSet( DISMISS_KEY, now );
			doc.cookie =
				DISMISS_KEY +
				'=' +
				now +
				'; max-age=' +
				DISMISS_TTL_MS / 1000 +
				'; path=/; SameSite=Lax' +
				( domain ? '; domain=' + domain : '' ) +
				( root.location.protocol === 'https:' ? '; Secure' : '' );
			prompt.hidden = true;
		} );
	}
} )( typeof window !== 'undefined' ? window : globalThis );
