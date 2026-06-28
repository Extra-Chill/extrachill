<?php
/**
 * Living Design System Page
 *
 * Registers a public (unlisted, noindexed) route at /design-system/ that
 * renders a living style guide for the Extra Chill theme. Every specimen on
 * the page consumes live CSS custom properties from assets/css/root.css, and a
 * client-side tweak panel lets visitors edit tokens and watch the page shift.
 *
 * The route is wired with a dedicated rewrite rule + query var so it works
 * regardless of any page or post that might otherwise claim the slug. The
 * template is swapped via template_include at priority 5 — ahead of the main
 * router at priority 10 — and the request is forced to a 200 (is_404 = false).
 *
 * Persistence for tweaks is client-side only (URL hash + clipboard export);
 * nothing is ever written to the server. The source of truth for tokens
 * remains the @extrachill/tokens package.
 *
 * @package ExtraChill
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query var that flags a request as the design-system route.
 */
const EXTRACHILL_DESIGN_SYSTEM_QUERY_VAR = 'extrachill_design_system';

/**
 * Option flag used to flush rewrite rules exactly once after this feature ships.
 */
const EXTRACHILL_DESIGN_SYSTEM_FLUSH_FLAG = 'extrachill_design_system_rewrite_flushed';

/**
 * Register the rewrite rule mapping /design-system/ to the query var.
 */
function extrachill_design_system_rewrite_rule() {
	add_rewrite_rule(
		'^design-system/?$',
		'index.php?' . EXTRACHILL_DESIGN_SYSTEM_QUERY_VAR . '=1',
		'top'
	);
}
add_action( 'init', 'extrachill_design_system_rewrite_rule' );

/**
 * Whitelist the design-system query var so WordPress preserves it.
 *
 * @param array $vars Registered public query vars.
 * @return array
 */
function extrachill_design_system_query_var( $vars ) {
	$vars[] = EXTRACHILL_DESIGN_SYSTEM_QUERY_VAR;
	return $vars;
}
add_filter( 'query_vars', 'extrachill_design_system_query_var' );

/**
 * Flush rewrite rules exactly once so the new rule resolves after deploy.
 *
 * Runs on init (after the rule is registered) and self-disables via an option
 * flag, so it never repeats on subsequent requests.
 */
function extrachill_design_system_maybe_flush_rewrite() {
	if ( get_option( EXTRACHILL_DESIGN_SYSTEM_FLUSH_FLAG ) ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( EXTRACHILL_DESIGN_SYSTEM_FLUSH_FLAG, 1 );
}
add_action( 'init', 'extrachill_design_system_maybe_flush_rewrite', 20 );

/**
 * Whether the current request is the design-system route.
 *
 * @return bool
 */
function extrachill_is_design_system() {
	return (bool) get_query_var( EXTRACHILL_DESIGN_SYSTEM_QUERY_VAR );
}

/**
 * Force a 200 status for the design-system route.
 *
 * Without this, WordPress would resolve the unknown URL to a 404 since no post
 * or page backs it.
 */
function extrachill_design_system_force_200() {
	if ( ! extrachill_is_design_system() ) {
		return;
	}

	global $wp_query;
	$wp_query->is_404 = false;
	status_header( 200 );
}
add_action( 'template_redirect', 'extrachill_design_system_force_200' );

/**
 * Swap in the design-system template, overriding the main template router.
 *
 * `template_include` is a filter, so the LAST callback to run wins. The theme's
 * main router (inc/core/template-router.php) hooks at priority 10 and — because
 * the /design-system/ main query has no post/page and is flagged
 * is_front_page() — would otherwise return front-page.php. Registering at
 * priority 20 (after the router) lets this filter have the final say.
 *
 * @param string $template Default template path.
 * @return string
 */
function extrachill_design_system_template( $template ) {
	if ( ! extrachill_is_design_system() ) {
		return $template;
	}

	$candidate = get_template_directory() . '/inc/core/templates/design-system.php';
	if ( file_exists( $candidate ) ) {
		return $candidate;
	}

	return $template;
}
add_filter( 'template_include', 'extrachill_design_system_template', 20 );

/**
 * Emit a noindex meta tag so the unlisted page stays out of search engines.
 */
function extrachill_design_system_noindex() {
	if ( ! extrachill_is_design_system() ) {
		return;
	}

	echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
}
add_action( 'wp_head', 'extrachill_design_system_noindex', 1 );

/**
 * Enqueue the design-system assets only on the design-system route.
 *
 * root.css (extrachill-root), taxonomy-badges.css and style.css are already
 * enqueued globally by inc/core/assets.php, but taxonomy-badges.css is normally
 * deferred (media="print" swap) — here we ensure it loads eagerly so the badge
 * specimens render correctly on first paint. The guide's own layout CSS and the
 * tweak-panel JS are enqueued conditionally with filemtime() versioning.
 */
function extrachill_design_system_assets() {
	if ( ! extrachill_is_design_system() ) {
		return;
	}

	// Ensure root tokens are present (dependency for everything below).
	$root_css_path = get_stylesheet_directory() . '/assets/css/root.css';
	if ( file_exists( $root_css_path ) && ! wp_style_is( 'extrachill-root', 'enqueued' ) ) {
		wp_enqueue_style(
			'extrachill-root',
			get_stylesheet_directory_uri() . '/assets/css/root.css',
			array(),
			(string) filemtime( $root_css_path )
		);
	}

	// Eager copy of the badge colors under a dedicated handle so the live badge
	// specimens are correct on first paint (the global handle is deferred).
	$badges_css_path = get_stylesheet_directory() . '/assets/css/taxonomy-badges.css';
	if ( file_exists( $badges_css_path ) ) {
		wp_enqueue_style(
			'extrachill-design-system-badges',
			get_stylesheet_directory_uri() . '/assets/css/taxonomy-badges.css',
			array( 'extrachill-root' ),
			(string) filemtime( $badges_css_path )
		);
	}

	// Guide layout/chrome CSS.
	$guide_css_path = get_stylesheet_directory() . '/assets/css/design-system.css';
	if ( file_exists( $guide_css_path ) ) {
		wp_enqueue_style(
			'extrachill-design-system',
			get_stylesheet_directory_uri() . '/assets/css/design-system.css',
			array( 'extrachill-root', 'extrachill-style' ),
			(string) filemtime( $guide_css_path )
		);
	}

	// Tweak-panel logic.
	$guide_js_path = get_stylesheet_directory() . '/assets/js/design-system.js';
	if ( file_exists( $guide_js_path ) ) {
		wp_enqueue_script(
			'extrachill-design-system',
			get_stylesheet_directory_uri() . '/assets/js/design-system.js',
			array(),
			(string) filemtime( $guide_js_path ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_design_system_assets', 30 );
