<?php
/**
 * 404 URL Categorizer
 *
 * Shared helper functions for categorizing and analyzing 404 URLs.
 * Extracted from CLI command logic into reusable functions that
 * abilities, CLI, and REST endpoints can all consume.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Categorize a 404 URL into a named pattern category.
 *
 * Order matters — specific patterns are checked before general ones.
 *
 * @param string $url The requested URL path.
 * @return string Category name.
 */
function extrachill_analytics_categorize_404_url( $url ) {
	$url_lower = strtolower( $url );

	// SQL injection attempts.
	if ( preg_match( '/select\(|sleep\(|union\+select|1\'"/i', $url ) ) {
		return 'sql-injection';
	}

	// Ad/sponsor/media-kit probe — multilingual about/contact/advertising pages.
	$ad_sponsor_paths = array(
		'/mediakit',
		'/media-kit',
		'/publicite',
		'/publicidad',
		'/werbung',
		'/rate-card',
		'/advertise',
		'/advertise-with-us',
		'/sponsors',
		'/sponsorship',
		'/partnerships',
		'/partner-with-us',
		'/about-us',
		'/aboutus',
		'/our-team',
		'/team',
		'/kontakt',
		'/impressum',
		'/chi-siamo',
		'/over-ons',
		'/ueber-uns',
		'/uber-uns',
		'/get-in-touch',
		'/contactus',
		'/reach-us',
		'/inquiry',
		'/a-propos',
		'/sobre',
		'/sobre-nosotros',
		'/contato',
		'/anuncie',
		'/pubblicita',
		'/mediadaten',
		'/contatti',
		'/fale-conosco',
		'/neem-contact-op',
		'/expediente',
		'/ficha-tecnica',
		'/institucional',
		'/instituicao',
		'/nosotros',
		'/contacto',
		'/nous-contacter',
		'/qui-sommes-nous',
		'/enquiry',
		'/publicidade',
	);

	$path_no_trailing = rtrim( $url_lower, '/' );
	foreach ( $ad_sponsor_paths as $ad_path ) {
		if ( $path_no_trailing === $ad_path ) {
			return 'ad-sponsor-probe';
		}
	}

	// Legacy HTML pages.
	if ( false !== strpos( $url_lower, '.html' ) ) {
		return 'legacy-html';
	}

	// Missing uploads.
	if ( 0 === strpos( $url, '/wp-content/uploads/' ) ) {
		return 'missing-upload';
	}

	// Plugin probes.
	if ( 0 === strpos( $url, '/wp-content/plugins/' ) ) {
		return 'plugin-probe';
	}

	// wp-includes probes.
	if ( 0 === strpos( $url, '/wp-includes/' ) ) {
		return 'wp-includes-probe';
	}

	// PHP file probes (case-insensitive, optional digit suffix).
	if ( preg_match( '/\.ph[pP]\d?/i', $url ) ) {
		return 'php-probe';
	}

	// Ad/txt standard files.
	$ad_txt_paths = array( '/ads.txt', '/app-ads.txt', '/sellers.json', '/security.txt' );
	if ( in_array( $url_lower, $ad_txt_paths, true ) ) {
		return 'ad-txt';
	}

	// Bot probes — common attack/scan paths.
	$bot_paths = array( '/login/', '/admin/', '/cgi-bin/', '/getcmd/', '/ip', '/xmlrpc/' );
	if ( in_array( $url_lower, $bot_paths, true ) ) {
		return 'bot-probe';
	}

	// Author enumeration.
	if ( preg_match( '#^/?\?author=#', $url ) ) {
		return 'author-enum';
	}

	// Old sitemap URLs.
	if ( 0 === strpos( $url_lower, '/sitemap' ) ) {
		return 'old-sitemap';
	}

	// Community thread URLs.
	if ( 0 === strpos( $url, '/t/' ) ) {
		return 'community-thread';
	}

	// Events URLs.
	if ( 0 === strpos( $url, '/events/' ) ) {
		return 'events';
	}

	// Festival URLs.
	if ( 0 === strpos( $url_lower, '/festival' ) ) {
		return 'festival';
	}

	// Date-prefixed content (e.g. /2023/04/post-slug).
	if ( preg_match( '#^/\d{4}/\d{2}/#', $url ) ) {
		return 'date-prefix';
	}

	// Join page.
	if ( '/join' === $path_no_trailing ) {
		return 'join-page';
	}

	// Everything else is content.
	return 'content';
}

/**
 * Check if a 404 category is actionable (could potentially be fixed with redirects, etc.).
 *
 * @param string $category The category name from extrachill_analytics_categorize_404_url().
 * @return bool True if the category is actionable.
 */
function extrachill_analytics_is_actionable_404_category( $category ) {
	$actionable = array(
		'legacy-html',
		'content',
		'date-prefix',
		'missing-upload',
		'ad-txt',
		'community-thread',
		'events',
		'festival',
		'old-sitemap',
		'join-page',
	);

	return in_array( $category, $actionable, true );
}

/**
 * Extract a post slug from a 404 URL.
 *
 * Strips query strings, .html suffixes, date prefixes (/YYYY/MM/),
 * and takes the last path segment.
 *
 * @param string $url The requested URL path.
 * @return string Sanitized slug.
 */
function extrachill_analytics_extract_404_slug( $url ) {
	// Remove query string.
	$path = strtok( $url, '?' );

	// Remove .html suffix.
	$path = preg_replace( '/\.html$/i', '', $path );

	// Remove date prefix /YYYY/MM/.
	$path = preg_replace( '#^/\d{4}/\d{2}/#', '/', $path );

	// Take the last non-empty segment.
	$segments = array_filter( explode( '/', $path ) );
	$slug     = ! empty( $segments ) ? end( $segments ) : '';

	return sanitize_title( $slug );
}

/**
 * Find a published post by slug.
 *
 * @param string $slug The post slug to search for.
 * @return int|false Post ID on success, false if not found.
 */
function extrachill_analytics_find_post_by_slug( $slug ) {
	if ( empty( $slug ) ) {
		return false;
	}

	$posts = get_posts(
		array(
			'name'           => $slug,
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	return ! empty( $posts ) ? $posts[0] : false;
}
