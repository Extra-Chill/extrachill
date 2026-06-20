<?php
/**
 * Canonical Visitor / Request Classifier
 *
 * THE single source of truth for "is this request a human or a bot?" across
 * every analytics instrument (404 tracking, pageview, search demand, retention,
 * event-write stamping). Before this file existed, five instruments each
 * re-litigated the question with their own ad-hoc rule and the rules disagreed
 * (issue #57) — the weakest one (the old `search` rule) silently corrupted the
 * search-gaps demand report (issue #51).
 *
 * Design (per the #57 decision): ONE classifier, ONE default verdict consumed
 * everywhere, but the verdict ships ALONGSIDE inspectable evidence signals so
 * the single boolean is not a one-way door. If a specific instrument later
 * proves it needs a different threshold, it reads the evidence rather than
 * re-collecting the signals a sixth way.
 *
 * "Human" is fundamentally probabilistic; there is no perfect answer. The
 * default policy leans on the strongest available signals in this order:
 *
 *   1. Request origin — programmatic/server-side context (WP-CLI, cron, an
 *      internal REST request) is NON-human regardless of UA. This is the fix
 *      for #51: a server-side band-name search carries a normal UA but no
 *      browser ever ran, so it must not count as human demand.
 *   2. User-Agent class — a declared crawler UA ("bot"/"crawl"/"curl"/...) or
 *      an empty UA is NON-human.
 *   3. Visitor cookie presence — a real JS browser that executed and minted an
 *      `ec_vid` cookie is the strongest positive human signal. A cookieless
 *      request that is otherwise an interactive web request is treated as
 *      NON-human (suspect) by the default verdict, because a human who reached
 *      a search box or deep page has loaded a page and minted the cookie.
 *
 * @package ExtraChill\Analytics
 * @since 0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classify the current request (or an explicit context) as human or bot, and
 * return the evidence signals the verdict was derived from.
 *
 * Verdict policy (the single canonical answer every instrument consumes):
 *   is_human = TRUE only when ALL of these hold —
 *     - request_origin === 'web'            (not cli/cron/rest-internal)
 *     - ua_class === 'browser'              (not 'bot' and not 'empty')
 *     - has_visitor_cookie === true         (a real browser minted ec_vid)
 *   Otherwise is_human = FALSE (is_bot = TRUE).
 *
 * In other words: programmatic context OR a crawler/empty UA OR a cookieless
 * request all classify as non-human. Present cookie + browser UA + web origin
 * is the strongest human signal and the only combination treated as human.
 *
 * @param array $context {
 *     Optional. Override the request-derived signals (useful for tests and for
 *     callers that already resolved a value to avoid re-reading globals).
 *
 *     @type string    $user_agent         User-agent string. Defaults to the
 *                                          current request UA.
 *     @type bool|null $has_visitor_cookie  Whether a valid ec_vid cookie is
 *                                          present. Defaults to reading the
 *                                          cookie via the read-only resolver.
 *     @type string    $request_origin      One of 'web'|'rest'|'cron'|'cli'.
 *                                          Defaults to auto-detection.
 * }
 * @return array{
 *     is_human: bool,
 *     is_bot: bool,
 *     signals: array{
 *         has_visitor_cookie: bool,
 *         ua_class: string,
 *         request_origin: string
 *     }
 * }
 */
function extrachill_analytics_classify_request( $context = array() ) {
	// --- Resolve evidence signals (from $context overrides or the request). ---

	$user_agent = array_key_exists( 'user_agent', $context )
		? (string) $context['user_agent']
		: ( function_exists( 'extrachill_analytics_get_user_agent' ) ? extrachill_analytics_get_user_agent() : '' );

	if ( array_key_exists( 'has_visitor_cookie', $context ) && null !== $context['has_visitor_cookie'] ) {
		$has_visitor_cookie = (bool) $context['has_visitor_cookie'];
	} else {
		$has_visitor_cookie = function_exists( 'extrachill_analytics_read_visitor_id' )
			&& '' !== extrachill_analytics_read_visitor_id();
	}

	$request_origin = array_key_exists( 'request_origin', $context ) && '' !== $context['request_origin']
		? (string) $context['request_origin']
		: extrachill_analytics_detect_request_origin();

	$ua_class = extrachill_analytics_classify_user_agent( $user_agent );

	$signals = array(
		'has_visitor_cookie' => $has_visitor_cookie,
		'ua_class'           => $ua_class,
		'request_origin'     => $request_origin,
	);

	// --- Apply the single canonical verdict policy. ---
	$is_human = (
		'web' === $request_origin
		&& 'browser' === $ua_class
		&& $has_visitor_cookie
	);

	return array(
		'is_human' => $is_human,
		'is_bot'   => ! $is_human,
		'signals'  => $signals,
	);
}

/**
 * Classify a User-Agent string into one of three buckets.
 *
 * This owns the ONE bot user-agent pattern list for the whole plugin (moved
 * here from the former extrachill_analytics_is_bot). The list is filterable so
 * the patterns are not behaviour-changing magic literals buried in code.
 *
 * @param string $user_agent The user agent string.
 * @return string 'empty' (no UA), 'bot' (matches a crawler pattern), or 'browser'.
 */
function extrachill_analytics_classify_user_agent( $user_agent ) {
	$user_agent = (string) $user_agent;

	if ( '' === trim( $user_agent ) ) {
		return 'empty';
	}

	$default_patterns = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'mediapartners',
		'lighthouse',
		'pagespeed',
		'pingdom',
		'uptimerobot',
		'headlesschrome',
		'python-requests',
		'curl/',
		'wget/',
		'go-http-client',
		'apache-httpclient',
	);

	/**
	 * Filter the User-Agent substring patterns treated as bot/crawler markers.
	 *
	 * @param string[] $patterns   Lower-case substrings; a match marks the UA as a bot.
	 * @param string   $user_agent The user agent being classified.
	 */
	$patterns = apply_filters( 'extrachill_analytics_bot_ua_patterns', $default_patterns, $user_agent );

	$ua_lower = strtolower( $user_agent );

	foreach ( (array) $patterns as $pattern ) {
		if ( '' !== $pattern && false !== strpos( $ua_lower, (string) $pattern ) ) {
			return 'bot';
		}
	}

	return 'browser';
}

/**
 * Detect the origin of the current request.
 *
 * Programmatic origins (cli/cron/rest) never represent an interactive human
 * browser session and are classified non-human regardless of UA.
 *
 * @return string One of 'cli'|'cron'|'rest'|'web'.
 */
function extrachill_analytics_detect_request_origin() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return 'cli';
	}

	if ( function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return 'cron';
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return 'rest';
	}

	return 'web';
}

/**
 * Convenience: is the current request (or explicit context) a human?
 *
 * @param array $context Optional signal overrides; see extrachill_analytics_classify_request().
 * @return bool
 */
function extrachill_analytics_request_is_human( $context = array() ) {
	$verdict = extrachill_analytics_classify_request( $context );
	return (bool) $verdict['is_human'];
}

/**
 * Convenience: is the current request (or explicit context) a bot/non-human?
 *
 * @param array $context Optional signal overrides; see extrachill_analytics_classify_request().
 * @return bool
 */
function extrachill_analytics_request_is_bot( $context = array() ) {
	$verdict = extrachill_analytics_classify_request( $context );
	return (bool) $verdict['is_bot'];
}

/**
 * Backward-compatible UA-only bot test.
 *
 * Thin shim retained so any external/legacy caller of the old helper keeps
 * working. Internally everything routes through the canonical classifier; this
 * delegates to the UA-class signal only (an empty or crawler UA is a bot),
 * matching the old function's contract (true on empty UA). New code should call
 * extrachill_analytics_classify_request() / _request_is_bot() instead so the
 * cookie + request-origin signals are also considered.
 *
 * @param string $user_agent The user agent string.
 * @return bool True if the user agent is empty or a known bot.
 */
function extrachill_analytics_is_bot( $user_agent ) {
	return 'browser' !== extrachill_analytics_classify_user_agent( $user_agent );
}
