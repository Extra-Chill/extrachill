<?php
/**
 * Router Pages
 *
 * Two virtual pages that turn the events homepage into a router:
 *
 *   /all     — the full all-cities calendar firehose (the calendar block that
 *              used to live on the homepage), for power users.
 *   /cities  — a directory of every city with upcoming events, grouped by
 *              region. Region sections are driven by the location taxonomy
 *              hierarchy and appear only when they have events.
 *
 * Implemented with rewrite rules + the extrachill_template_archive override,
 * mirroring the discovery-pages pattern. No physical WP pages required.
 *
 * @package ExtraChillEvents
 * @since 0.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the router-page query var and rewrite rules.
 *
 * @hook init
 */
function extrachill_events_router_rewrite_rules() {
	if ( ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_tag( '%ec_events_router%', '(all|cities)' );

	add_rewrite_rule( '^all/?$', 'index.php?ec_events_router=all', 'top' );
	add_rewrite_rule( '^cities/?$', 'index.php?ec_events_router=cities', 'top' );
}
add_action( 'init', 'extrachill_events_router_rewrite_rules' );

/**
 * Get the current router page slug, or empty string.
 *
 * @return string 'all', 'cities', or ''.
 */
function extrachill_events_get_router_page(): string {
	$page = get_query_var( 'ec_events_router', '' );
	return in_array( $page, array( 'all', 'cities' ), true ) ? (string) $page : '';
}

/**
 * Whether the current request is a router page.
 *
 * @return bool
 */
function extrachill_events_is_router_page(): bool {
	return ec_is_events_site() && '' !== extrachill_events_get_router_page();
}

/**
 * Route the request to the matching router-page template.
 *
 * Runs at priority 20 (after the events archive override at 10 and the
 * discovery override at 15) so it wins when ec_events_router is set.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string Router-page template path or original.
 */
function extrachill_events_router_template( string $template ): string {
	$page = extrachill_events_get_router_page();
	if ( '' === $page ) {
		return $template;
	}

	if ( 'all' === $page ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/all-events.php';
	}

	return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/cities.php';
}
add_filter( 'extrachill_template_archive', 'extrachill_events_router_template', 20 );

/**
 * Force the router pages to resolve as a successful (200) archive request.
 *
 * Without a matched post/term, WordPress would 404 the virtual URL before
 * the template filter runs. Mark it as an archive and clear the 404 flag.
 *
 * @hook parse_query
 * @param \WP_Query $query Main query.
 */
function extrachill_events_router_query_flags( $query ) {
	if ( ! $query->is_main_query() || is_admin() ) {
		return;
	}
	if ( '' === extrachill_events_get_router_page() ) {
		return;
	}

	$query->is_404     = false;
	$query->is_archive = true;
	$query->is_home    = false;
	status_header( 200 );
}
add_action( 'parse_query', 'extrachill_events_router_query_flags' );

/**
 * Document title for router pages.
 *
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_router_title( array $parts ): array {
	$page = extrachill_events_get_router_page();
	if ( '' === $page ) {
		return $parts;
	}

	$parts['title'] = ( 'all' === $page )
		? __( 'All Live Music Events', 'extrachill-events' )
		: __( 'Browse Events by City', 'extrachill-events' );

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_router_title', 1000 );
