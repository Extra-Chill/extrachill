<?php
/**
 * Extra Chill Events — procedural bootstrap.
 *
 * Top-level functions + hook wiring kept out of the main plugin file so
 * that file holds only the ExtraChillEvents class (WPCS
 * Universal.Files.SeparateFunctionsFromOO).
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_events() {
	return ExtraChillEvents::get_instance();
}

extrachill_events();

/**
 * Check if the current site is the events site.
 *
 * @return bool True if on events.extrachill.com, false otherwise.
 * @since 0.9.1
 */
function ec_is_events_site() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	return $events_blog_id && (int) get_current_blog_id() === (int) $events_blog_id;
}

/**
 * Register event-submission block from build directory
 *
 * @hook init
 * @return void
 * @since 0.1.5
 */
function extrachill_events_register_blocks() {
	if ( ! ec_is_events_site() ) {
		return;
	}
	register_block_type( EXTRACHILL_EVENTS_PLUGIN_DIR . 'build/event-submission' );

	$concert_stats_dir = EXTRACHILL_EVENTS_PLUGIN_DIR . 'build/concert-stats';
	if ( file_exists( $concert_stats_dir . '/block.json' ) ) {
		register_block_type( $concert_stats_dir );
	}
}
add_action( 'init', 'extrachill_events_register_blocks' );

/**
 * Render homepage content for events.extrachill.com
 *
 * Hooked via extrachill_homepage_content action.
 *
 * @since 0.1.0
 */
function ec_events_render_homepage() {
	if ( ! ec_is_events_site() ) {
		return;
	}
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
add_action( 'extrachill_homepage_content', 'ec_events_render_homepage' );

/**
 * Override archive template on events.extrachill.com
 *
 * Unified archive template renders data-machine-events calendar block with automatic
 * taxonomy filtering based on archive context. Applies to all archive types
 * including taxonomy, post type, date, and author archives.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook extrachill_template_archive
 * @param string $template Default template path from theme
 * @return string Plugin template path for events site, theme template otherwise
 * @since 0.1.0
 */
function ec_events_override_archive_template( $template ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( $events_blog_id && get_current_blog_id() === $events_blog_id ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/archive.php';
	}
	return $template;
}
add_filter( 'extrachill_template_archive', 'ec_events_override_archive_template' );

/**
 * Redirect /events/ post type archive to homepage for SEO consolidation
 *
 * Homepage serves as canonical events URL on events.extrachill.com.
 * 301 redirect consolidates link equity and prevents duplicate content.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook template_redirect
 * @return void
 * @since 0.1.0
 */
function ec_events_redirect_post_type_archive() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	if ( is_post_type_archive( 'data_machine_events' ) ) {
		wp_safe_redirect( home_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'ec_events_redirect_post_type_archive' );

/**
 * Contribute approved artist tour-URL submissions to the community rank engine.
 *
 * Hooks `ec_points_sources` (owned by extrachill-users). Each approved
 * submission is worth 10 points — the same weight as a main-site post. The
 * unit is the approved submission, not the imported events, so one approved
 * URL that ingests many events still contributes exactly 10 points (avoiding
 * a gameable reward for high-volume imports). No-ops if the table class is
 * not loaded.
 *
 * @param array $sources Source-id => points map.
 * @param int   $user_id WordPress user ID.
 * @return array
 */
add_filter(
	'ec_points_sources',
	function ( $sources, $user_id ) {
		if ( ! class_exists( '\\ExtraChillEvents\\Core\\ArtistUrlSubmissionsTable' ) ) {
			return $sources;
		}

		$n = \ExtraChillEvents\Core\ArtistUrlSubmissionsTable::count_approved_by_user( (int) $user_id );

		$sources['events_contributed'] = (float) $n * 10;
		return $sources;
	},
	10,
	2
);
