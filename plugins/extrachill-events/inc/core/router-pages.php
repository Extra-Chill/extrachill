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
	add_rewrite_rule( '^all/?$', 'index.php?ec_events_router=all', 'top' );
	add_rewrite_rule( '^all/page/([0-9]{1,})/?$', 'index.php?ec_events_router=all&paged=$matches[1]', 'top' );

	if ( extrachill_events_location_directory_enabled() ) {
		add_rewrite_tag( '%ec_events_location_index%', '(1)' );
		add_rewrite_rule( '^location/?$', 'index.php?ec_events_location_index=1', 'top' );
	}
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
 * Whether the /location directory page is enabled.
 *
 * @return bool
 * @since 0.25.0
 */
function extrachill_events_location_directory_enabled(): bool {
	return (bool) apply_filters( 'extrachill_events_enable_location_directory', true );
}

/**
 * Flush rewrites once when the public location directory route is introduced.
 */
function extrachill_events_maybe_flush_router_rewrites(): void {
	$rewrite_version = '3';
	if ( get_option( 'extrachill_events_router_rewrite_version' ) === $rewrite_version ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( 'extrachill_events_router_rewrite_version', $rewrite_version, false );
}
add_action( 'init', 'extrachill_events_maybe_flush_router_rewrites', 100 );

/**
 * Validate and enrich upcoming-count rows as canonical selectable cities.
 *
 * @param array $rows Upcoming location count rows.
 * @return array Canonical city rows with taxonomy hierarchy metadata.
 */
function extrachill_events_prepare_location_rows( array $rows ): array {
	$locations = array();

	foreach ( $rows as $row ) {
		$term_id = absint( $row['term_id'] ?? 0 );
		$count   = absint( $row['count'] ?? 0 );
		$term    = $term_id ? get_term( $term_id, 'location' ) : null;
		if ( $count < 1 || ! $term instanceof WP_Term ) {
			continue;
		}

		$ancestor_ids = get_ancestors( $term_id, 'location', 'taxonomy' );
		if ( count( $ancestor_ids ) < 2 ) {
			continue;
		}

		$root  = get_term( (int) end( $ancestor_ids ), 'location' );
		$state = get_term( (int) $ancestor_ids[0], 'location' );
		$url   = get_term_link( $term );
		if ( ! $root instanceof WP_Term || ! $state instanceof WP_Term || is_wp_error( $url ) ) {
			continue;
		}

		$locations[] = array(
			'term_id'   => $term_id,
			'name'      => $term->name,
			'slug'      => $term->slug,
			'count'     => $count,
			'url'       => $url,
			'label'     => sprintf( '%s, %s', $term->name, $state->name ),
			'region_id' => (int) $root->term_id,
			'region'    => $root->name,
			'state_id'  => (int) $state->term_id,
			'state'     => $state->name,
		);
	}

	return $locations;
}

/**
 * Whether the current request is the /location/ directory page.
 *
 * @return bool
 */
function extrachill_events_is_location_index(): bool {
	return ec_is_events_site()
		&& extrachill_events_location_directory_enabled()
		&& '1' === (string) get_query_var( 'ec_events_location_index', '' );
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
 * Whether a query targets one of the registered virtual router pages.
 *
 * @param \WP_Query $query Query being parsed or handled.
 * @return bool
 */
function extrachill_events_query_is_router_page( $query ): bool {
	if ( ! ec_is_events_site() ) {
		return false;
	}

	if ( 'all' === $query->get( 'ec_events_router', '' ) ) {
		return true;
	}

	return extrachill_events_location_directory_enabled()
		&& '1' === (string) $query->get( 'ec_events_location_index', '' );
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
	if ( ! extrachill_events_query_is_router_page( $query ) ) {
		return;
	}

	$query->is_404     = false;
	$query->is_archive = true;
	$query->is_home    = false;
}
add_action( 'parse_query', 'extrachill_events_router_query_flags' );

/**
 * Prevent core from classifying an empty virtual-page query as a 404.
 *
 * @hook pre_handle_404
 * @param bool|null $preempt Existing 404 preemption result.
 * @param \WP_Query $query   Main query.
 * @return bool|null
 */
function extrachill_events_router_pre_handle_404( $preempt, $query ) {
	if ( ! extrachill_events_query_is_router_page( $query ) ) {
		return $preempt;
	}

	status_header( 200 );
	return true;
}
add_filter( 'pre_handle_404', 'extrachill_events_router_pre_handle_404', 10, 2 );

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
