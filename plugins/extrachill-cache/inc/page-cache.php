<?php
/**
 * Page cache — WordPress-layer buffer capture (STORE path).
 *
 * The SERVE path lives in the advanced-cache.php drop-in (see
 * inc/dropin-template.php) because a full-page cache can only serve-and-exit
 * before WordPress loads. THIS file handles the other half: when a cacheable
 * anonymous front-end request misses the cache, we buffer the rendered output
 * and write it to disk so the next visitor gets a hit.
 *
 * Modeled on Breeze's breeze_cache() output-buffer callback
 * (inc/cache/execute-cache.php:291), minus every feature the platform doesn't
 * use (gzip, lazy-load, cross-origin rewriting, currency suffixes, Cloudways
 * headers). Kept deliberately small.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'extrachill_cache_maybe_start_buffer', 0 );

/**
 * Decide whether the current request is cacheable, and if so start buffering.
 *
 * The logged-in bypass here mirrors the drop-in's invariant: a logged-in user
 * is NEVER written to (or served from) the anonymous cache. This is the WP-side
 * belt to the drop-in's braces.
 *
 * @return void
 */
function extrachill_cache_maybe_start_buffer() {
	if ( ! extrachill_cache_request_is_cacheable() ) {
		return;
	}

	ob_start( 'extrachill_cache_output_buffer' );
}

/**
 * Determine cacheability of the current (WP-loaded) request.
 *
 * @return bool
 */
function extrachill_cache_request_is_cacheable() {
	// INVARIANT: never cache for authenticated users. Presence of a logged-in
	// session is sufficient — no secondary role cookie is consulted, ever.
	// This is the structural fix for extrachill-users#161.
	if ( is_user_logged_in() ) {
		return false;
	}

	// Only GET.
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return false;
	}

	// Never cache admin, ajax, cron, REST, feeds, search, previews, or
	// password-protected content. (Breeze excluded these too, scattered across
	// execute-cache.php and breeze_cache().)
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return false;
	}

	if ( is_feed() || is_search() || is_preview() || is_404() || post_password_required() ) {
		return false;
	}

	// Don't cache requests carrying query strings we can't safely key on.
	// Anonymous crawl/pagination URLs are fine; anything with a nonce/preview
	// arg is already excluded above. Comment-author cookies opt out below.
	if ( ! empty( $_COOKIE ) ) {
		foreach ( $_COOKIE as $name => $value ) {
			// Comment authors see their just-submitted (unmoderated) comment,
			// so don't cache for them. Breeze used breeze_commented_posts;
			// WP core sets comment_author_* cookies.
			if ( 0 === strpos( $name, 'comment_author_' ) ) {
				return false;
			}
		}
	}

	/**
	 * Allow other code to opt a request out of caching.
	 *
	 * @param bool $cacheable Whether the request is cacheable.
	 */
	return (bool) apply_filters( 'extrachill_cache_request_is_cacheable', true );
}

/**
 * Output-buffer callback: persist the rendered page, then return it unmodified.
 *
 * @param string $buffer Rendered HTML.
 * @return string The buffer, unchanged.
 */
function extrachill_cache_output_buffer( $buffer ) {
	// Only cache full, successful HTML documents.
	if ( strlen( $buffer ) < 255 ) {
		return $buffer;
	}

	if ( function_exists( 'http_response_code' ) && 200 !== http_response_code() ) {
		return $buffer;
	}

	// Re-check state at shutdown — is_404()/password can be set late.
	if ( is_404() || is_search() || post_password_required() ) {
		return $buffer;
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return $buffer;
	}

	// Belt-and-braces: never persist a page for a logged-in user.
	if ( is_user_logged_in() ) {
		return $buffer;
	}

	if ( ! preg_match( '#</html>#i', $buffer ) ) {
		return $buffer;
	}

	$identity = extrachill_cache_url_identity();
	if ( '' === $identity ) {
		return $buffer;
	}

	$device = extrachill_cache_is_mobile_request() ? 'mobile' : 'desktop';
	$key    = extrachill_cache_key( $identity, $device );

	$modified_time = time();
	$stamped       = $buffer . "\n<!-- Cached by Extra Chill Cache - " . gmdate( 'D, d M Y H:i:s', $modified_time ) . " GMT -->\n";

	$headers = array(
		array(
			'name'  => 'Content-Type',
			'value' => 'text/html; charset=UTF-8',
		),
		array(
			'name'  => 'Last-Modified',
			'value' => gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT',
		),
	);

	extrachill_cache_write( $key, $stamped, $headers, get_current_blog_id() );

	return $buffer;
}
