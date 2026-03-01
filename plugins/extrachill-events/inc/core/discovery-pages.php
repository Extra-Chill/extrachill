<?php
/**
 * Discovery Pages
 *
 * Programmatic SEO landing pages for time-scoped event queries like
 * "live music in austin tonight" and "concerts in charleston this weekend".
 *
 * Registers rewrite rules that append a scope segment to existing location
 * taxonomy URLs:
 *   /location/usa/texas/austin/tonight
 *   /location/usa/south-carolina/charleston/this-weekend
 *
 * One rewrite rule covers all cities × all scopes. The scope segment maps
 * to the %event_scope% query var, which the discovery template reads to
 * render a scoped calendar block.
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Constants ---

/**
 * Valid scope slugs and their human-readable labels.
 *
 * @var array<string, string>
 */
define( 'EXTRACHILL_EVENTS_DISCOVERY_SCOPES', array(
	'today'        => 'Today',
	'tonight'      => 'Tonight',
	'this-weekend' => 'This Weekend',
	'this-week'    => 'This Week',
) );

// --- Rewrite Rules ---

/**
 * Register the event_scope rewrite tag and discovery page rewrite rule.
 *
 * The rewrite tag auto-registers the query var. The rule uses 'top' priority
 * to match before the existing location catch-all rule.
 *
 * @hook init
 */
function extrachill_events_discovery_rewrite_rules() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	$scope_pattern = implode( '|', array_keys( EXTRACHILL_EVENTS_DISCOVERY_SCOPES ) );

	add_rewrite_tag( '%event_scope%', '(' . $scope_pattern . ')' );

	add_rewrite_rule(
		'location/(.+?)/(' . $scope_pattern . ')/?$',
		'index.php?location=$matches[1]&event_scope=$matches[2]',
		'top'
	);
}
add_action( 'init', 'extrachill_events_discovery_rewrite_rules' );

// --- Detection ---

/**
 * Check if the current request is a discovery page.
 *
 * @return bool
 */
function extrachill_events_is_discovery_page(): bool {
	$scope = get_query_var( 'event_scope', '' );
	if ( empty( $scope ) ) {
		return false;
	}

	if ( ! is_tax( 'location' ) ) {
		return false;
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return false;
	}

	return isset( EXTRACHILL_EVENTS_DISCOVERY_SCOPES[ $scope ] );
}

/**
 * Get the current scope slug, or empty string if not a discovery page.
 *
 * @return string
 */
function extrachill_events_get_current_scope(): string {
	$scope = get_query_var( 'event_scope', '' );
	return isset( EXTRACHILL_EVENTS_DISCOVERY_SCOPES[ $scope ] ) ? $scope : '';
}

/**
 * Get the human-readable label for a scope.
 *
 * @param string $scope Scope slug.
 * @return string Label or empty string.
 */
function extrachill_events_get_scope_label( string $scope ): string {
	return EXTRACHILL_EVENTS_DISCOVERY_SCOPES[ $scope ] ?? '';
}

// --- Template Override ---

/**
 * Override the archive template for discovery pages.
 *
 * Runs on the same filter as the standard archive override but at a lower
 * priority (15 vs default 10) so it runs after and takes precedence when event_scope is set.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string Discovery template path or original.
 */
function extrachill_events_discovery_template( string $template ): string {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $template;
	}

	return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/discovery.php';
}
add_filter( 'extrachill_template_archive', 'extrachill_events_discovery_template', 15 );

// --- SEO: Title ---

/**
 * Set the document title for discovery pages.
 *
 * Patterns:
 *   Tonight:      "Live Music in {City} Tonight"
 *   Today:        "Live Music in {City} Today"
 *   This Weekend: "Live Music in {City} This Weekend"
 *   This Week:    "Live Music in {City} This Week"
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array Modified title parts.
 */
function extrachill_events_discovery_title( array $title_parts ): array {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $title_parts;
	}

	$term_name   = single_term_title( '', false );
	$scope_label = extrachill_events_get_scope_label( extrachill_events_get_current_scope() );

	$title_parts['title'] = sprintf( 'Live Music in %s %s', $term_name, $scope_label );

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_discovery_title', 1000 );

// --- SEO: Meta Description ---

/**
 * Output meta description for discovery pages.
 *
 * Dynamic description with event count, venue names, and scope context.
 * Runs at priority 4 (before extrachill-seo at priority 5).
 *
 * @hook wp_head
 */
function extrachill_events_discovery_meta_description() {
	if ( ! extrachill_events_is_discovery_page() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	$city_name   = $term->name;
	$scope       = extrachill_events_get_current_scope();
	$scope_label = strtolower( extrachill_events_get_scope_label( $scope ) );

	// Count upcoming events for this location.
	$event_count = extrachill_events_get_upcoming_event_count( $term->term_id );

	// Get venue data.
	$venues      = extrachill_events_get_location_venues( $term->term_id );
	$venue_count = count( $venues );

	// Build scope-specific description.
	$description = sprintf( 'Find live music in %s %s.', $city_name, $scope_label );

	if ( $event_count > 0 && $venue_count > 0 ) {
		$description .= sprintf(
			' %d upcoming shows across %d venues',
			$event_count,
			$venue_count
		);

		$venue_names = array_column( $venues, 'name' );
		if ( count( $venue_names ) >= 2 ) {
			$description .= sprintf(
				' including %s, %s, and more.',
				$venue_names[0],
				$venue_names[1]
			);
		} elseif ( count( $venue_names ) === 1 ) {
			$description .= sprintf( ' including %s.', $venue_names[0] );
		} else {
			$description .= '.';
		}
	} else {
		$description .= sprintf(
			' Browse the full %s live music calendar on Extra Chill.',
			$city_name
		);
	}

	// Truncate to 160 chars at word boundary.
	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$last_space  = strrpos( $description, ' ' );
		if ( false !== $last_space ) {
			$description = substr( $description, 0, $last_space );
		}
		$description .= '...';
	}

	printf(
		'<meta name="description" content="%s" />' . "\n",
		esc_attr( $description )
	);
	printf(
		'<meta property="og:description" content="%s" />' . "\n",
		esc_attr( $description )
	);
}
add_action( 'wp_head', 'extrachill_events_discovery_meta_description', 4 );

// --- SEO: Canonical URL ---

/**
 * Output canonical URL for discovery pages.
 *
 * WordPress generates a canonical for the base location term. We need to
 * override it to include the scope segment.
 *
 * @hook wp_head
 */
function extrachill_events_discovery_canonical() {
	if ( ! extrachill_events_is_discovery_page() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term ) {
		return;
	}

	$term_link = get_term_link( $term );
	if ( is_wp_error( $term_link ) ) {
		return;
	}

	$canonical = trailingslashit( $term_link ) . extrachill_events_get_current_scope() . '/';

	printf(
		'<link rel="canonical" href="%s" />' . "\n",
		esc_url( $canonical )
	);
}
add_action( 'wp_head', 'extrachill_events_discovery_canonical', 1 );

/**
 * Remove WordPress default canonical on discovery pages to avoid duplicates.
 *
 * @hook wp_head
 */
function extrachill_events_discovery_remove_default_canonical() {
	if ( extrachill_events_is_discovery_page() ) {
		remove_action( 'wp_head', 'rel_canonical' );
	}
}
add_action( 'wp_head', 'extrachill_events_discovery_remove_default_canonical', 0 );

// --- SEO: Skip Duplicate Meta & Canonical ---

/**
 * Prevent extrachill-seo from outputting a duplicate meta description on discovery pages.
 *
 * @hook extrachill_seo_skip_meta_description
 * @param bool $skip Whether to skip.
 * @return bool
 */
function extrachill_events_discovery_skip_seo_description( bool $skip ): bool {
	if ( extrachill_events_is_discovery_page() ) {
		return true;
	}
	return $skip;
}
add_filter( 'extrachill_seo_skip_meta_description', 'extrachill_events_discovery_skip_seo_description' );

/**
 * Prevent extrachill-seo from outputting a duplicate canonical on discovery pages.
 *
 * Discovery pages output their own canonical with the scope segment at priority 1.
 *
 * @hook extrachill_seo_skip_canonical
 * @param bool $skip Whether to skip.
 * @return bool
 */
function extrachill_events_discovery_skip_seo_canonical( bool $skip ): bool {
	if ( extrachill_events_is_discovery_page() ) {
		return true;
	}
	return $skip;
}
add_filter( 'extrachill_seo_skip_canonical', 'extrachill_events_discovery_skip_seo_canonical' );

// --- SEO: Override OG Data ---

/**
 * Fix Open Graph data on discovery pages.
 *
 * Overrides og:url to include the scope segment and og:description
 * to use the scoped description instead of the generic one.
 *
 * @hook extrachill_seo_open_graph_data
 * @param array $og_data OG property => value pairs.
 * @return array Modified OG data.
 */
function extrachill_events_discovery_og_data( array $og_data ): array {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $og_data;
	}

	$term = get_queried_object();
	if ( ! $term ) {
		return $og_data;
	}

	$term_link = get_term_link( $term );
	if ( is_wp_error( $term_link ) ) {
		return $og_data;
	}

	$scope = extrachill_events_get_current_scope();

	// Fix og:url to include scope segment.
	$og_data['og:url'] = trailingslashit( $term_link ) . $scope . '/';

	// Fix og:title to include scope label.
	$scope_label         = extrachill_events_get_scope_label( $scope );
	$og_data['og:title'] = sprintf( 'Live Music in %s %s', $term->name, $scope_label );

	return $og_data;
}
add_filter( 'extrachill_seo_open_graph_data', 'extrachill_events_discovery_og_data' );

// --- Breadcrumbs ---

/**
 * Override breadcrumb trail for discovery pages.
 *
 * Shows: Events > USA > Texas > Austin > Tonight
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string $trail Current breadcrumb trail.
 * @return string Custom trail or pass-through.
 */
function extrachill_events_discovery_breadcrumbs( string $trail ): string {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $trail;
	}

	$term = get_queried_object();
	if ( ! $term ) {
		return $trail;
	}

	$parts = array();

	// Build ancestor chain.
	$ancestors = get_ancestors( $term->term_id, 'location', 'taxonomy' );
	if ( ! empty( $ancestors ) ) {
		$ancestors = array_reverse( $ancestors );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'location' );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$parts[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_term_link( $ancestor ) ),
					esc_html( $ancestor->name )
				);
			}
		}
	}

	// City link (back to unscoped location archive).
	$parts[] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( get_term_link( $term ) ),
		esc_html( $term->name )
	);

	// Current scope (no link — current page).
	$scope_label = extrachill_events_get_scope_label( extrachill_events_get_current_scope() );
	$parts[]     = '<span>' . esc_html( $scope_label ) . '</span>';

	return implode( ' › ', $parts );
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_events_discovery_breadcrumbs' );

// --- Assets ---

/**
 * Enqueue discovery page CSS.
 *
 * @hook wp_enqueue_scripts
 */
function extrachill_events_discovery_scripts() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	// Load on discovery pages AND location archives (scope tabs appear on both).
	if ( ! extrachill_events_is_discovery_page() && ! is_tax( 'location' ) ) {
		return;
	}

	wp_enqueue_style(
		'extrachill-events-discovery',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/discovery.css',
		array(),
		EXTRACHILL_EVENTS_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_discovery_scripts' );

// --- Scope Navigation ---

/**
 * Render scope navigation tabs.
 *
 * Shows tabs for Tonight | This Weekend | This Week | All Shows.
 * Highlights the current scope. Links to sibling scopes and the
 * base location archive.
 *
 * @param \WP_Term $term    Location term object.
 * @param string   $current Current scope slug.
 */
function extrachill_events_render_scope_nav( \WP_Term $term, string $current ) {
	$term_link = get_term_link( $term );
	if ( is_wp_error( $term_link ) ) {
		return;
	}

	$tabs = array(
		'tonight'      => 'Tonight',
		'this-weekend' => 'This Weekend',
		'this-week'    => 'This Week',
		''             => 'All Shows',
	);

	echo '<nav class="discovery-scope-nav" aria-label="Time scope navigation">';
	echo '<ul>';

	foreach ( $tabs as $scope_slug => $label ) {
		$is_active = ( $scope_slug === $current );
		$class     = $is_active ? ' class="active"' : '';

		if ( '' === $scope_slug ) {
			$url = $term_link;
		} else {
			$url = trailingslashit( $term_link ) . $scope_slug . '/';
		}

		printf(
			'<li%s><a href="%s"%s>%s</a></li>',
			$class,
			esc_url( $url ),
			$is_active ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}

	echo '</ul>';
	echo '</nav>';
}

// --- Prevent Location SEO From Running on Discovery Pages ---

/**
 * Short-circuit the location-seo title on discovery pages.
 *
 * location-seo.php runs at the same priority (1000) — our discovery title
 * also runs at 1000. Because discovery-pages.php is loaded after location-seo.php,
 * our filter runs second and wins. But to be explicit, we also suppress the
 * location-seo meta description from firing on discovery pages by detecting
 * the scope in its own callback.
 *
 * No action needed — the filter chain handles this naturally since our
 * title callback overwrites the title_parts['title'] set by location-seo.
 */
