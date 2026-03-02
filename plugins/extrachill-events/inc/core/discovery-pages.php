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
 * SEO data is provided via extrachill-seo filters — the SEO plugin is
 * the single rendering engine for all meta tags.
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
 * Runs at priority 15 (after the events archive override at priority 10)
 * so it takes precedence when event_scope is set.
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

// --- SEO: Meta Description (via extrachill-seo filter) ---

/**
 * Provide meta description for discovery pages via extrachill-seo filter.
 *
 * Uses the shared helper from location-seo.php to build a scoped description.
 *
 * @hook extrachill_seo_meta_description
 * @param string $description Default description from extrachill-seo.
 * @return string Scoped description or pass-through.
 */
function extrachill_events_discovery_description( string $description ): string {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $description;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return $description;
	}

	$scope       = extrachill_events_get_current_scope();
	$scope_label = strtolower( extrachill_events_get_scope_label( $scope ) );

	return extrachill_events_build_location_description( $term->name, $term->term_id, $scope_label );
}
add_filter( 'extrachill_seo_meta_description', 'extrachill_events_discovery_description' );

// --- SEO: Canonical URL (via extrachill-seo filter) ---

/**
 * Override canonical URL for discovery pages via extrachill-seo filter.
 *
 * Appends the scope segment to the base location term URL.
 *
 * @hook extrachill_seo_canonical_url
 * @param string $canonical Default canonical from extrachill-seo.
 * @return string Discovery canonical or pass-through.
 */
function extrachill_events_discovery_canonical( string $canonical ): string {
	if ( ! extrachill_events_is_discovery_page() ) {
		return $canonical;
	}

	$term = get_queried_object();
	if ( ! $term ) {
		return $canonical;
	}

	$term_link = get_term_link( $term );
	if ( is_wp_error( $term_link ) ) {
		return $canonical;
	}

	return trailingslashit( $term_link ) . extrachill_events_get_current_scope() . '/';
}
add_filter( 'extrachill_seo_canonical_url', 'extrachill_events_discovery_canonical' );

// --- SEO: Override OG Data ---

/**
 * Fix Open Graph data on discovery pages.
 *
 * The og:url and og:title are derived from canonical and document title
 * by extrachill-seo, but we override via filter for explicit control.
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

	// og:title — include scope label.
	$scope_label         = extrachill_events_get_scope_label( extrachill_events_get_current_scope() );
	$og_data['og:title'] = sprintf( 'Live Music in %s %s', $term->name, $scope_label );

	return $og_data;
}
add_filter( 'extrachill_seo_open_graph_data', 'extrachill_events_discovery_og_data' );

// --- Sitemap: Register Discovery URLs ---

/**
 * Register discovery page URLs in the sitemap.
 *
 * Generates URLs for all city × scope combinations.
 *
 * @hook extrachill_seo_sitemap_urls
 * @param array $urls Existing sitemap URLs.
 * @return array URLs with discovery pages appended.
 */
function extrachill_events_discovery_sitemap_urls( array $urls ): array {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return $urls;
	}

	// Get all location terms that are cities (leaf nodes with events).
	$locations = get_terms( array(
		'taxonomy'   => 'location',
		'hide_empty' => true,
		'childless'  => true,
	) );

	if ( is_wp_error( $locations ) || empty( $locations ) ) {
		return $urls;
	}

	// Scopes to include in sitemap (exclude "today" — same as "tonight" for SEO).
	$sitemap_scopes = array( 'tonight', 'this-weekend', 'this-week' );

	foreach ( $locations as $location ) {
		$term_link = get_term_link( $location );
		if ( is_wp_error( $term_link ) ) {
			continue;
		}

		foreach ( $sitemap_scopes as $scope ) {
			$urls[] = array(
				'loc' => trailingslashit( $term_link ) . $scope . '/',
			);
		}
	}

	return $urls;
}
add_filter( 'extrachill_seo_sitemap_urls', 'extrachill_events_discovery_sitemap_urls' );

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
 * Enqueue discovery page CSS and JS.
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

	wp_enqueue_script(
		'extrachill-events-discovery',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/discovery.js',
		array(),
		EXTRACHILL_EVENTS_VERSION,
		true
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

	printf(
		'<nav class="discovery-scope-nav" aria-label="Time scope navigation" data-term-id="%d" data-term-name="%s" data-term-link="%s">',
		$term->term_id,
		esc_attr( $term->name ),
		esc_url( $term_link )
	);
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
			'<li%s><a href="%s" data-scope="%s"%s>%s</a></li>',
			$class,
			esc_url( $url ),
			esc_attr( $scope_slug ),
			$is_active ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}

	echo '</ul>';
	echo '</nav>';
}
