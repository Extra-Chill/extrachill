<?php
/**
 * Router Pages
 *
 * Virtual pages that turn the events homepage into a router:
 *
 *   /all       — the full all-cities calendar firehose (the calendar block
 *                that used to live on the homepage), for power users.
 *   /location/ — the location taxonomy ROOT: a directory of every city with
 *                upcoming events, grouped by region. Region sections are
 *                driven by the location taxonomy hierarchy and appear only
 *                when they have events. This lives at the taxonomy's own base
 *                slug rather than an invented route.
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
 * Register the router-page query vars and rewrite rules.
 *
 * @hook init
 */
function extrachill_events_router_rewrite_rules() {
	if ( ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_tag( '%ec_events_router%', '(all)' );
	add_rewrite_tag( '%ec_events_location_index%', '(1)' );

	add_rewrite_rule( '^all/?$', 'index.php?ec_events_router=all', 'top' );

	// The bare location taxonomy base (no term) renders the directory. The
	// catch-all location/(.+?) rule requires a term, so /location/ would 404
	// without this. 'top' priority matches before that catch-all.
	add_rewrite_rule( '^location/?$', 'index.php?ec_events_location_index=1', 'top' );
}
add_action( 'init', 'extrachill_events_router_rewrite_rules' );

/**
 * Whether the current request is the /all firehose page.
 *
 * @return bool
 */
function extrachill_events_is_all_events_page(): bool {
	return ec_is_events_site() && 'all' === get_query_var( 'ec_events_router', '' );
}

/**
 * Whether the current request is the /location/ directory page.
 *
 * @return bool
 */
function extrachill_events_is_location_index(): bool {
	return ec_is_events_site() && '1' === (string) get_query_var( 'ec_events_location_index', '' );
}

/**
 * Whether the current request is a router/virtual page (/all or /location/).
 *
 * @return bool
 */
function extrachill_events_is_router_page(): bool {
	return extrachill_events_is_all_events_page() || extrachill_events_is_location_index();
}

/**
 * Route the request to the matching virtual-page template.
 *
 * Runs at priority 20 (after the events archive override at 10 and the
 * discovery override at 15) so it wins for these virtual pages.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string Virtual-page template path or original.
 */
function extrachill_events_router_template( string $template ): string {
	if ( extrachill_events_is_all_events_page() ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/all-events.php';
	}

	if ( extrachill_events_is_location_index() ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/location-directory.php';
	}

	return $template;
}
add_filter( 'extrachill_template_archive', 'extrachill_events_router_template', 20 );

/**
 * Force the virtual pages to resolve as a successful (200) archive request.
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
	if ( ! extrachill_events_is_router_page() ) {
		return;
	}

	$query->is_404     = false;
	$query->is_archive = true;
	$query->is_home    = false;
	status_header( 200 );
}
add_action( 'parse_query', 'extrachill_events_router_query_flags' );

/**
 * Document title for the virtual pages.
 *
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_router_title( array $parts ): array {
	if ( extrachill_events_is_all_events_page() ) {
		$parts['title'] = __( 'All Live Music Events', 'extrachill-events' );
	} elseif ( extrachill_events_is_location_index() ) {
		$parts['title'] = __( 'Live Music by Location', 'extrachill-events' );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_router_title', 1000 );
