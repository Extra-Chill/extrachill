<?php
/**
 * Focused tests for the cached Twitter oEmbed content filter.
 *
 * This repo has no Composer/phpunit harness, so this is a standalone,
 * dependency-free script: it stubs the WordPress primitives the filter relies
 * on, loads the real content-filters.php, and asserts behavior with simple
 * pass/fail reporting.
 *
 * Run: php tests/test-content-filters.php
 */

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['_test_transients']      = array();
$GLOBALS['_test_transient_ttls']  = array();
$GLOBALS['_test_remote_calls']    = 0;
$GLOBALS['_test_remote_response'] = null;

class WP_Error_Test {}

function add_filter( $tag, $cb, $prio = 10 ) {
	$GLOBALS['_test_filters'][ $tag ][] = $cb;
}
function add_action( $tag, $cb, $prio = 10 ) {
	$GLOBALS['_test_actions'][ $tag ][] = $cb;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['_test_transients'] ) ? $GLOBALS['_test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['_test_transients'][ $key ]     = $value;
	$GLOBALS['_test_transient_ttls'][ $key ] = $ttl;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['_test_remote_calls']++;
	$GLOBALS['_test_remote_args'] = $args;
	if ( $GLOBALS['_test_remote_response'] instanceof WP_Error_Test ) {
		return $GLOBALS['_test_remote_response'];
	}
	return is_array( $GLOBALS['_test_remote_response'] )
		? $GLOBALS['_test_remote_response']
		: array( 'body' => $GLOBALS['_test_remote_response'] );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error_Test;
}

function _test_reset_state() {
	$GLOBALS['_test_transients']      = array();
	$GLOBALS['_test_transient_ttls']  = array();
	$GLOBALS['_test_remote_calls']    = 0;
	$GLOBALS['_test_remote_response'] = null;

	// Reset the static memo inside embed_tweets by reflecting on a fresh load
	// is not possible; instead we drive each scenario with unique content.
}

require __DIR__ . '/../inc/content/content-filters.php';

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$failures++;
	}
}

/* -----------------------------------------------------------------
 * 1. Filters are still wired to all three content hooks exactly once.
 * ---------------------------------------------------------------- */
check( 'embed_tweets registered on the_content',   in_array( 'embed_tweets', $GLOBALS['_test_filters']['the_content'], true ) );
check( 'embed_tweets registered on topic content', in_array( 'embed_tweets', $GLOBALS['_test_filters']['bbp_get_topic_content'], true ) );
check( 'embed_tweets registered on reply content', in_array( 'embed_tweets', $GLOBALS['_test_filters']['bbp_get_reply_content'], true ) );

/* -----------------------------------------------------------------
 * 2. Content with no tweet URL is untouched and makes no HTTP call.
 * ---------------------------------------------------------------- */
_test_reset_state();
$out = embed_tweets( '<p>Just a normal post about music.</p>' );
check( 'no-tweet content unchanged', '<p>Just a normal post about music.</p>' === $out );
check( 'no-tweet content makes no HTTP call', 0 === $GLOBALS['_test_remote_calls'] );

/* -----------------------------------------------------------------
 * 3. Successful fetch wraps the oEmbed HTML and is cached long-term.
 *    Each unique body is resolved once even across overlapping filters
 *    (call embed_tweets twice with identical content).
 * ---------------------------------------------------------------- */
_test_reset_state();
$GLOBALS['_test_remote_response'] = '{"html":"<blockquote>Tweet!<\/blockquote>"}';
$content = '<p>see https://twitter.com/ec/status/123</p>';
$first   = embed_tweets( $content );
$second  = embed_tweets( $content ); // same body again -> memo hit, no extra work.

check( 'successful fetch wraps embed html', false !== strpos( $first, '<div class="twitter-embed"><blockquote>Tweet!</blockquote></div>' ) );
check( 'identical body fetched once per request (dedupe)', 1 === $GLOBALS['_test_remote_calls'] );
check( 'second call returns memoized result', $first === $second );

// Transient was written with the success TTL, not the fail TTL.
$cache_key = 'ec_tweet_oembed_' . md5( 'https://twitter.com/ec/status/123' );
check( 'success cached with long TTL (30d)', 30 * DAY_IN_SECONDS === $GLOBALS['_test_transient_ttls'][ $cache_key ] );

/* -----------------------------------------------------------------
 * 4. A different body referencing the SAME tweet reuses the transient
 *    cache -> no second HTTP call (cross-body cache reuse).
 * ---------------------------------------------------------------- */
_test_reset_state();
$GLOBALS['_test_remote_response'] = '{"html":"<blockquote>Hi<\/blockquote>"}';
embed_tweets( '<p>one https://twitter.com/ec/status/999</p>' );
embed_tweets( '<p>two https://twitter.com/ec/status/999</p>' );
check( 'same tweet across bodies is one HTTP call', 1 === $GLOBALS['_test_remote_calls'] );

/* -----------------------------------------------------------------
 * 5. Failed fetch falls back to the raw URL and caches the failure
 *    with the short TTL, so the next render does not hit Twitter.
 * ---------------------------------------------------------------- */
_test_reset_state();
$GLOBALS['_test_remote_response'] = new WP_Error_Test();
$url   = 'https://x.com/EC/status/555';
$out   = embed_tweets( '<p>' . $url . '</p>' );
check( 'failed fetch leaves URL in place', false !== strpos( $out, $url ) );

$fail_key = 'ec_tweet_oembed_' . md5( $url );
check( 'failure cached with short TTL (5m)', EXTRACHILL_COMMUNITY_TWEET_OEMBED_FAIL_TTL === $GLOBALS['_test_transient_ttls'][ $fail_key ] );
check( 'failure sentinel stored as empty string', '' === $GLOBALS['_test_transients'][ $fail_key ] );

// Second body, same tweet: transient serves the cached failure, no HTTP.
$before = $GLOBALS['_test_remote_calls'];
embed_tweets( '<p>again ' . $url . '</p>' );
check( 'cached failure makes no HTTP call', $before === $GLOBALS['_test_remote_calls'] );

/* -----------------------------------------------------------------
 * 6. Malformed oEmbed body (no html key) is treated as a failure.
 * ---------------------------------------------------------------- */
_test_reset_state();
$GLOBALS['_test_remote_response'] = '{"not_html":"nope"}';
$out = embed_tweets( '<p>see https://twitter.com/x/status/1</p>' );
check( 'malformed oEmbed falls back to URL', false !== strpos( $out, 'twitter.com/x/status/1' ) );

/* -----------------------------------------------------------------
 * 7. Bounded timeout is passed to wp_remote_get.
 * ---------------------------------------------------------------- */
_test_reset_state();
$GLOBALS['_test_remote_response'] = '{"html":"<blockquote>x<\/blockquote>"}';
embed_tweets( '<p>https://twitter.com/t/status/2</p>' );
check( 'wp_remote_get receives a 5s timeout', 5 === $GLOBALS['_test_remote_args']['timeout'] );

echo "\n";
if ( $failures > 0 ) {
	echo "$failures test(s) FAILED.\n";
	exit( 1 );
}
echo "All content-filter tests passed.\n";
exit( 0 );
