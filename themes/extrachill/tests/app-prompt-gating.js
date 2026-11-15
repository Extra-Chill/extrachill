/**
 * Client-side gating for the Android app prompt (extrachill#1416).
 *
 * Run: node tests/app-prompt-gating.js
 */
const assert = require( 'assert' );
const path = require( 'path' );
const { shouldShow, DISMISS_TTL_MS } = require( path.join( __dirname, '..', 'assets', 'js', 'app-prompt.js' ) );

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';
const NOW = Date.UTC( 2026, 8, 1 );

const base = { userAgent: ANDROID, dismissedAt: 0, now: NOW, isArticle: false, isLoggedIn: false, priorViews: 0 };
const check = ( overrides ) => shouldShow( Object.assign( {}, base, overrides ) );

// Android only.
assert.strictEqual( check( {} ), true, 'Android non-article first view shows' );
assert.strictEqual( check( { userAgent: IPHONE } ), false, 'iOS never shows (Smart App Banner covers it)' );
assert.strictEqual( check( { userAgent: DESKTOP } ), false, 'desktop never shows' );
assert.strictEqual( check( { userAgent: '' } ), false, 'missing UA never shows' );

// Dismissal remembered for 30 days.
assert.strictEqual( DISMISS_TTL_MS, 30 * 24 * 60 * 60 * 1000 );
assert.strictEqual( check( { dismissedAt: NOW - 1000 } ), false, 'just dismissed stays hidden' );
assert.strictEqual( check( { dismissedAt: NOW - DISMISS_TTL_MS + 60000 } ), false, 'day 29 stays hidden' );
assert.strictEqual( check( { dismissedAt: NOW - DISMISS_TTL_MS - 1 } ), true, 'after 30 days shows again' );
assert.strictEqual( check( { dismissedAt: 'garbage' } ), true, 'corrupt storage does not wedge the prompt' );

// No interstitial on a logged-out first pageview of an article.
assert.strictEqual( check( { isArticle: true } ), false, 'logged-out first article view hidden' );
assert.strictEqual( check( { isArticle: true, priorViews: 1 } ), true, 'logged-out later article view shows' );
assert.strictEqual( check( { isArticle: true, isLoggedIn: true } ), true, 'logged-in article view shows' );
assert.strictEqual( check( { isArticle: true, priorViews: 3, dismissedAt: NOW - 1000 } ), false, 'dismissal wins on articles too' );

process.stdout.write( 'App prompt gating: Android only, 30-day dismissal, no first-pageview article prompt.\n' );
