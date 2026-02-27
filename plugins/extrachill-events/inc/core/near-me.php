<?php
/**
 * Near Me Page
 *
 * Hooks into the /near-me/ page to provide geolocation-based event discovery.
 * Uses browser Geolocation API to detect user location, then filters the
 * events-map and calendar blocks to show nearby venues and events.
 *
 * Flow:
 * 1. First visit (no params): loading state → JS requests geolocation
 * 2. Geolocation granted: redirects to ?lat=X&lng=Y&radius=25
 * 3. Page reloads with params: map + calendar render with geo-filtered results
 * 4. Geolocation denied: city grid fallback shown via JS
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the current page is the Near Me page on the events site.
 *
 * @return bool
 */
function extrachill_events_is_near_me_page(): bool {
	if ( ! is_page( 'near-me' ) ) {
		return false;
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	return (int) get_current_blog_id() === $events_blog_id;
}

/**
 * Get sanitized geo params from the URL.
 *
 * @return array{lat: float|null, lng: float|null, radius: int}
 */
function extrachill_events_get_geo_params(): array {
	$lat    = isset( $_GET['lat'] ) ? floatval( $_GET['lat'] ) : null;
	$lng    = isset( $_GET['lng'] ) ? floatval( $_GET['lng'] ) : null;
	$radius = isset( $_GET['radius'] ) ? absint( $_GET['radius'] ) : 25;

	// Validate coordinates.
	if ( null !== $lat && ( $lat < -90 || $lat > 90 ) ) {
		$lat = null;
	}
	if ( null !== $lng && ( $lng < -180 || $lng > 180 ) ) {
		$lng = null;
	}

	// Clamp radius.
	$radius = max( 5, min( 100, $radius ) );

	return array(
		'lat'    => $lat,
		'lng'    => $lng,
		'radius' => $radius,
	);
}

// --- SEO ---

/**
 * Custom title for the Near Me page.
 *
 * @hook document_title_parts
 */
function extrachill_events_near_me_title( array $title_parts ): array {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $title_parts;
	}

	$title_parts['title'] = 'Live Music Near Me — Find Concerts & Shows Tonight';

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_near_me_title', 1000 );

/**
 * Meta description for the Near Me page.
 *
 * @hook wp_head
 */
function extrachill_events_near_me_meta_description() {
	if ( ! extrachill_events_is_near_me_page() ) {
		return;
	}

	$description = 'Find live music near you tonight. Discover concerts, shows, and events at venues near your location. Browse by city or let us detect where you are.';

	printf(
		'<meta name="description" content="%s" />' . "\n",
		esc_attr( $description )
	);
	printf(
		'<meta property="og:description" content="%s" />' . "\n",
		esc_attr( $description )
	);
}
add_action( 'wp_head', 'extrachill_events_near_me_meta_description', 4 );

/**
 * Skip extrachill-seo meta description on Near Me page.
 *
 * @hook extrachill_seo_skip_meta_description
 */
function extrachill_events_near_me_skip_seo_description( bool $skip ): bool {
	if ( extrachill_events_is_near_me_page() ) {
		return true;
	}
	return $skip;
}
add_filter( 'extrachill_seo_skip_meta_description', 'extrachill_events_near_me_skip_seo_description' );

// --- Geolocation JS ---

/**
 * Enqueue geolocation script and styles on the Near Me page.
 *
 * @hook wp_enqueue_scripts
 */
function extrachill_events_near_me_scripts() {
	if ( ! extrachill_events_is_near_me_page() ) {
		return;
	}

	wp_enqueue_style(
		'extrachill-events-near-me',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/near-me.css',
		array(),
		EXTRACHILL_EVENTS_VERSION
	);

	wp_enqueue_script(
		'extrachill-events-near-me',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/near-me.js',
		array(),
		EXTRACHILL_EVENTS_VERSION,
		true
	);

	$geo = extrachill_events_get_geo_params();

	wp_localize_script( 'extrachill-events-near-me', 'ecNearMe', array(
		'hasLocation' => null !== $geo['lat'] && null !== $geo['lng'],
		'lat'         => $geo['lat'],
		'lng'         => $geo['lng'],
		'radius'      => $geo['radius'],
		'pageUrl'     => get_permalink(),
	) );
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_near_me_scripts' );

// --- Map Block Filter ---

/**
 * Filter map venues on the Near Me page to show venues near the user.
 *
 * @hook datamachine_events_map_venues
 */
function extrachill_events_near_me_map_venues( array $venues, array $context ): array {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $venues;
	}

	$geo = extrachill_events_get_geo_params();

	// No location yet — show nothing (JS will request geolocation).
	if ( null === $geo['lat'] || null === $geo['lng'] ) {
		return array();
	}

	// Filter to venues within radius.
	$nearby = array();
	foreach ( $venues as $venue ) {
		$distance = extrachill_events_haversine_distance(
			$geo['lat'],
			$geo['lng'],
			$venue['lat'],
			$venue['lon']
		);

		if ( $distance <= $geo['radius'] ) {
			$venue['distance'] = round( $distance, 1 );
			$nearby[]          = $venue;
		}
	}

	// Sort by distance.
	usort( $nearby, function ( $a, $b ) {
		return $a['distance'] <=> $b['distance'];
	} );

	return $nearby;
}
add_filter( 'datamachine_events_map_venues', 'extrachill_events_near_me_map_venues', 10, 2 );

/**
 * Set map center to user location on the Near Me page.
 *
 * @hook datamachine_events_map_center
 */
function extrachill_events_near_me_map_center( $center, array $context ) {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $center;
	}

	$geo = extrachill_events_get_geo_params();

	if ( null !== $geo['lat'] && null !== $geo['lng'] ) {
		return array(
			'lat' => $geo['lat'],
			'lon' => $geo['lng'],
		);
	}

	return $center;
}
add_filter( 'datamachine_events_map_center', 'extrachill_events_near_me_map_center', 10, 2 );

/**
 * Summary text for the Near Me map.
 *
 * @hook datamachine_events_map_summary
 */
function extrachill_events_near_me_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $summary;
	}

	$venue_count = count( $venues );
	if ( 0 === $venue_count ) {
		return $summary;
	}

	$geo = extrachill_events_get_geo_params();

	return sprintf(
		'%d venues within %d miles',
		$venue_count,
		$geo['radius']
	);
}
add_filter( 'datamachine_events_map_summary', 'extrachill_events_near_me_map_summary', 10, 3 );

// --- User Location Marker ---

/**
 * Pass user location to the map block for the blue dot marker.
 *
 * @hook datamachine_events_map_user_location
 */
function extrachill_events_near_me_user_location( $user_location, array $context ) {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $user_location;
	}

	$geo = extrachill_events_get_geo_params();

	if ( null !== $geo['lat'] && null !== $geo['lng'] ) {
		return array(
			'lat' => $geo['lat'],
			'lon' => $geo['lng'],
		);
	}

	return $user_location;
}
add_filter( 'datamachine_events_map_user_location', 'extrachill_events_near_me_user_location', 10, 2 );

// --- Context Bar (when location is active) ---

/**
 * Build the context bar shown above the map when geo params are present.
 *
 * Shows radius dropdown and reset link so users can adjust their search.
 *
 * @param array $geo Sanitized geo params.
 * @return string HTML for the context bar.
 */
function extrachill_events_near_me_context_bar( array $geo ): string {
	$radius_options = array( 5, 10, 25, 50, 100 );
	$current_radius = $geo['radius'];
	$page_url       = get_permalink();

	$html  = '<div class="near-me-context-bar">';
	$html .= '<div class="near-me-context-controls">';

	// Radius selector.
	$html .= '<label class="near-me-radius-label">';
	$html .= '<span>Within</span>';
	$html .= '<select class="near-me-radius-select" data-lat="' . esc_attr( $geo['lat'] ) . '" data-lng="' . esc_attr( $geo['lng'] ) . '" data-url="' . esc_url( $page_url ) . '">';

	foreach ( $radius_options as $r ) {
		$selected = ( $r === $current_radius ) ? ' selected' : '';
		$html    .= sprintf( '<option value="%d"%s>%d miles</option>', $r, $selected, $r );
	}

	$html .= '</select>';
	$html .= '</label>';

	// Reset link.
	$html .= '<a href="' . esc_url( $page_url ) . '" class="near-me-reset">Change location</a>';

	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

// --- Content: Loading State + City Grid Fallback ---

/**
 * Inject loading state and hidden city grid before the blocks.
 *
 * When the page has geo params (from redirect), this does nothing —
 * the map and calendar blocks render normally with filtered data.
 *
 * When no geo params, shows:
 * - Loading spinner (visible) — JS will request geolocation
 * - City grid (hidden) — JS reveals if geolocation is denied
 * - Status message area — JS updates with error messages
 *
 * The city grid also serves as SEO fallback for crawlers that can't
 * execute JavaScript.
 *
 * @hook the_content
 */
function extrachill_events_near_me_content( string $content ): string {
	if ( ! extrachill_events_is_near_me_page() ) {
		return $content;
	}

	$geo = extrachill_events_get_geo_params();

	// Location present — show context bar above blocks.
	if ( null !== $geo['lat'] && null !== $geo['lng'] ) {
		return extrachill_events_near_me_context_bar( $geo ) . $content;
	}

	// No location — build the detection UI.
	$html = '<div class="near-me-detect">';

	// Loading state (visible by default, JS hides if geo fails).
	$html .= '<div class="near-me-loading">';
	$html .= '<div class="near-me-spinner"></div>';
	$html .= '<p class="near-me-status">Detecting your location...</p>';
	$html .= '</div>';

	$html .= '</div>';

	// City grid fallback (hidden by default, JS reveals if geo denied).
	// Also visible to search engines / no-JS users via noscript or as crawlable content.
	$locations = get_terms( array(
		'taxonomy'   => 'location',
		'hide_empty' => true,
		'orderby'    => 'count',
		'order'      => 'DESC',
		'number'     => 20,
		'meta_query' => array(
			array(
				'key'     => '_location_coordinates',
				'compare' => 'EXISTS',
			),
		),
	) );

	if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
		$html .= '<div class="near-me-cities" style="display:none;">';
		$html .= '<h2>Browse by City</h2>';
		$html .= '<div class="near-me-city-grid">';

		foreach ( $locations as $location ) {
			$url = get_term_link( $location );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$html .= sprintf(
				'<a href="%s" class="near-me-city-card"><span class="near-me-city-name">%s</span><span class="near-me-city-count">%d events</span></a>',
				esc_url( $url ),
				esc_html( $location->name ),
				$location->count
			);
		}

		$html .= '</div>';
		$html .= '</div>';
	}

	// Noscript fallback — show city grid for users without JS.
	$html .= '<noscript><style>.near-me-loading{display:none!important}.near-me-cities{display:block!important}</style></noscript>';

	return $html;
}
add_filter( 'the_content', 'extrachill_events_near_me_content' );

// --- Secondary Header ---

/**
 * Add Near Me link to secondary header.
 *
 * @hook extrachill_secondary_header_items
 */
function extrachill_events_near_me_header_item( array $items ): array {
	$items[] = array(
		'url'      => home_url( '/near-me/' ),
		'label'    => __( 'Near Me', 'extrachill-events' ),
		'priority' => 5,
	);
	return $items;
}
add_filter( 'extrachill_secondary_header_items', 'extrachill_events_near_me_header_item' );

// --- Utility ---

/**
 * Calculate distance between two points using the Haversine formula.
 *
 * @param float $lat1 Latitude of point 1.
 * @param float $lon1 Longitude of point 1.
 * @param float $lat2 Latitude of point 2.
 * @param float $lon2 Longitude of point 2.
 * @return float Distance in miles.
 */
function extrachill_events_haversine_distance( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
	$earth_radius = 3959; // miles

	$d_lat = deg2rad( $lat2 - $lat1 );
	$d_lon = deg2rad( $lon2 - $lon1 );

	$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
		+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
		* sin( $d_lon / 2 ) * sin( $d_lon / 2 );

	$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	return $earth_radius * $c;
}
