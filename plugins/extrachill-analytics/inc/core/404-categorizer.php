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

	// Secret / credential / VCS file probes — scanners hunting for leaked
	// secrets and source control metadata. These are unambiguously hostile;
	// no legitimate visitor requests an .env, .git internals, or cloud creds.
	// Matched before the generic content catch-all so the scanner storm is
	// counted instead of being buried in 'content'.
	if ( preg_match(
		'#(?:^|/)(?:\.env|\.env\.|\.git/|\.gitignore$|\.svn/|\.hg/|\.bzr/|\.aws/|\.ssh/|\.npmrc$|\.htpasswd$|\.htaccess$|\.vscode/|\.idea/|\.well-known/[^/]*\.bak)#i',
		$url
	) ) {
		return 'secret-probe';
	}

	// Config / backup / dump file probes — looking for credentials.json,
	// config.json, wp-config backups, database dumps, archive backups, log
	// files, and PHP info/debug endpoints.
	if ( preg_match(
		'#(?:^|/)(?:wp-config\.php\.[a-z0-9]+|credentials\.[a-z]+|config\.(?:json|php\.bak|yml|yaml)|secrets?\.[a-z]+|backup\.(?:zip|sql|tar|tar\.gz|tgz)|db\.sql|database\.sql|dump\.sql|.*\.sql\.gz|robots\.txt\.bak|sftp\.json|laravel\.log|telescope/|actuator/|phpinfo\.php|info\.php|server\.php)#i',
		$url
	) ) {
		return 'config-probe';
	}

	// REST API user enumeration — /wp-json/wp/v2/users harvesting.
	if ( preg_match( '#/wp-json/wp/v2/users#i', $url ) ) {
		return 'wpjson-user-enum';
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
 * Check if a 404 category represents scanner / attack traffic.
 *
 * These categories indicate automated probing for vulnerabilities, leaked
 * secrets, admin endpoints, or injection points — never legitimate visitor
 * navigation. Used by the scanner-404 counter to partition the attack-shaped
 * slice of the 404 storm away from benign content 404s, giving a trustworthy
 * attack-volume signal for scoping a WAF rule.
 *
 * This is the complement of the on-site search_attack classifier: that one
 * measures injection attempts submitted through the SITE SEARCH form, while
 * this one measures the URL/PATH scanner storm that 404s without ever touching
 * the search box. Two distinct attack surfaces, two distinct counters.
 *
 * @param string $category The category name from extrachill_analytics_categorize_404_url().
 * @return bool True if the category is scanner/attack traffic.
 */
function extrachill_analytics_is_scanner_404_category( $category ) {
	$scanner = array(
		'sql-injection',
		'secret-probe',
		'config-probe',
		'wpjson-user-enum',
		'php-probe',
		'plugin-probe',
		'wp-includes-probe',
		'bot-probe',
		'author-enum',
		'ad-sponsor-probe',
	);

	/**
	 * Filter the set of 404 categories treated as scanner / attack traffic.
	 *
	 * @param string[] $scanner  Category names considered scanner traffic.
	 * @param string   $category The category being checked.
	 */
	$scanner = apply_filters( 'extrachill_analytics_scanner_404_categories', $scanner, $category );

	return in_array( $category, $scanner, true );
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
 * Exclude 404 rows whose URL already has an active redirect rule.
 *
 * The 404 read-side reports aggregate raw 404 event rows in a rolling window
 * with no awareness of the redirects table. A URL fixed with a 301 keeps
 * showing as a top offender until its pre-fix rows age out. This helper drops
 * those already-solved URLs by asking extrachill-seo (the redirects owner)
 * which of the candidate URLs currently match an active rule.
 *
 * Dependency direction: analytics asks seo, never the reverse. The call is
 * guarded by function_exists() so analytics never hard-depends on seo — if the
 * helper is absent (seo inactive/older), the rows are returned unchanged,
 * preserving the previous behavior. A single bulk call is used rather than
 * N+1 per-URL lookups.
 *
 * @param array $rows Array of row objects each exposing a ->url property.
 * @return array The input rows minus any whose URL has an active redirect.
 */
function extrachill_analytics_exclude_redirected_404_rows( $rows ) {
	if ( empty( $rows ) || ! function_exists( 'extrachill_seo_filter_redirected_urls' ) ) {
		return $rows;
	}

	// Collect candidate URLs (skip null/empty), preserving them for the lookup.
	$urls = array();
	foreach ( $rows as $row ) {
		if ( isset( $row->url ) && '' !== $row->url ) {
			$urls[] = $row->url;
		}
	}

	if ( empty( $urls ) ) {
		return $rows;
	}

	$redirected = extrachill_seo_filter_redirected_urls( $urls );

	if ( empty( $redirected ) ) {
		return $rows;
	}

	$redirected_lookup = array_fill_keys( $redirected, true );

	$filtered = array();
	foreach ( $rows as $row ) {
		if ( isset( $row->url ) && isset( $redirected_lookup[ $row->url ] ) ) {
			continue;
		}
		$filtered[] = $row;
	}

	return $filtered;
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
