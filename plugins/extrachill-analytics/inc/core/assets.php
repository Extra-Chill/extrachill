<?php
/**
 * Frontend Asset Management
 *
 * Handles enqueuing of analytics tracking scripts.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Name of the first-party anonymous visitor cookie.
 */
define( 'EXTRACHILL_ANALYTICS_VISITOR_COOKIE', 'ec_vid' );

/**
 * Detect whether the current request signals an opt-out of tracking.
 *
 * Honors Global Privacy Control (`Sec-GPC: 1`) and the legacy Do Not Track
 * header (`DNT: 1`). When either is present we neither mint the visitor cookie
 * nor attach a visitor_id to events — the pageview row is still written, just
 * anonymously, so aggregate volume counts stay accurate without any per-visitor
 * identifier.
 *
 * @return bool True if the visitor has opted out (no visitor_id should be set).
 */
function extrachill_analytics_visitor_opted_out() {
	if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) {
		return true;
	}

	if ( isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ) {
		return true;
	}

	return false;
}

/**
 * Validate a string as a canonical lowercase UUID v4.
 *
 * @param string $value Candidate value.
 * @return bool True when the value is a well-formed UUID v4.
 */
function extrachill_analytics_is_valid_visitor_id( $value ) {
	return is_string( $value ) && 1 === preg_match(
		'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
		$value
	);
}

/**
 * Read the existing first-party visitor id, or mint a new one server-side.
 *
 * The cookie value is a random UUID v4 only — never an IP, email, or
 * fingerprint. It is strictly first-party and used solely for our own
 * aggregate retention analytics; it is never shared with Mediavine or any
 * third party and never used for ad targeting.
 *
 * Respects Global Privacy Control / DNT: if the visitor has opted out we return
 * an empty string and set no cookie.
 *
 * Must be called before output starts (headers not yet sent) so setcookie()
 * works — the deferred beacon cannot reliably set a cookie itself.
 *
 * @return string The visitor UUID, or empty string if opted out / cannot mint.
 */
function extrachill_analytics_get_or_mint_visitor_id() {
	if ( extrachill_analytics_visitor_opted_out() ) {
		return '';
	}

	$cookie_name = EXTRACHILL_ANALYTICS_VISITOR_COOKIE;

	if ( isset( $_COOKIE[ $cookie_name ] ) ) {
		$existing = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		if ( extrachill_analytics_is_valid_visitor_id( $existing ) ) {
			return $existing;
		}
	}

	$visitor_id = wp_generate_uuid4();

	// Set the cookie for ~1 year. Secure + HttpOnly + SameSite=Lax: first-party
	// analytics only, not readable by JS, not sent on cross-site sub-requests.
	if ( ! headers_sent() ) {
		setcookie(
			$cookie_name,
			$visitor_id,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'domain'   => '',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// Make the freshly-minted id available within this same request.
		$_COOKIE[ $cookie_name ] = $visitor_id;
	}

	return $visitor_id;
}

/**
 * Enqueue view tracking script on singular pages.
 */
function extrachill_analytics_enqueue_view_tracking() {
	if ( ! is_singular() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/view-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	// Mint/read the first-party visitor id server-side so the deferred beacon
	// can echo it back. Empty string when the visitor opted out (GPC/DNT).
	$visitor_id = extrachill_analytics_get_or_mint_visitor_id();

	wp_enqueue_script(
		'extrachill-view-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/view-tracking.js',
		array(),
		filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'extrachill-view-tracking',
		'ecViewTracking',
		array(
			'postId'    => get_the_ID(),
			'endpoint'  => rest_url( 'extrachill/v1/analytics/view' ),
			'visitorId' => $visitor_id,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_view_tracking' );
