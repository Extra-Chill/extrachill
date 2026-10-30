<?php
/**
 * Single Event Related Events
 *
 * Handles related events logic for single event pages.
 *
 * Card markup is NOT duplicated here: this module resolves the event data
 * (taxonomy whitelist, venue/location sections, exclude-same-venue logic)
 * and feeds it through the theme's shared renderer,
 * extrachill_render_related_tax_section(). Per theme AGENTS.md, plugins
 * extend via hooks, not template forks.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue assets for related events
 *
 * @since 0.1.1
 */
function ec_events_enqueue_related_assets() {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	// Ensure core block styles are loaded since we are manually rendering components
	if ( wp_style_is( 'wp-block-data-machine-events-calendar', 'registered' ) ) {
		wp_enqueue_style( 'wp-block-data-machine-events-calendar' );
	}

	// Enqueue custom related events styles. filemtime() returns int|false,
	// while wp_enqueue_style()'s $ver expects bool|string|null — cast, and fall
	// back to null when the file is missing rather than emitting ver="".
	$related_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/related-events.css';
	$related_ver = file_exists( $related_css ) ? (string) filemtime( $related_css ) : null;

	wp_enqueue_style(
		'ec-related-events',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/related-events.css',
		array(),
		$related_ver
	);
}
add_action( 'wp_enqueue_scripts', 'ec_events_enqueue_related_assets' );

/**
 * Use venue and location taxonomies for event posts
 *
 * Order matters:
 * 1. venue - Shows upcoming events at same venue
 * 2. location - Shows upcoming events in same location (different venues)
 *
 * @hook extrachill_related_posts_taxonomies
 * @param array  $taxonomies Default taxonomies (artist, venue)
 * @param int    $post_id    Current post ID
 * @param string $post_type  Current post type
 * @return array Modified taxonomies for event posts
 * @since 0.1.0
 */
function ec_events_filter_related_taxonomies( $taxonomies, $post_id, $post_type ) {
	if ( 'data_machine_events' === $post_type ) {
		return array( 'venue', 'location' );
	}
	return $taxonomies;
}
add_filter( 'extrachill_related_posts_taxonomies', 'ec_events_filter_related_taxonomies', 10, 3 );

/**
 * Allow venue and location taxonomies in related posts whitelist
 *
 * @hook extrachill_related_posts_allowed_taxonomies
 * @param array  $allowed   Default allowed taxonomies
 * @param string $post_type Current post type
 * @return array Modified allowed taxonomies
 * @since 0.1.0
 */
function ec_events_allow_related_taxonomies( $allowed, $post_type ) {
	if ( 'data_machine_events' === $post_type ) {
		return array_merge( $allowed, array( 'venue', 'location' ) );
	}
	return $allowed;
}
add_filter( 'extrachill_related_posts_allowed_taxonomies', 'ec_events_allow_related_taxonomies', 10, 2 );

/**
 * Override related posts display for data_machine_events
 *
 * @param bool   $override Whether to override default display
 * @param string $taxonomy Taxonomy being queried
 * @param int    $post_id  Current post ID
 * @return bool
 */
function ec_events_override_related_posts( $override, $taxonomy, $post_id ) {
	if ( get_post_type( $post_id ) === 'data_machine_events' ) {
		return true;
	}
	return $override;
}
add_filter( 'extrachill_override_related_posts_display', 'ec_events_override_related_posts', 10, 3 );

/**
 * Build the trusted meta-content fragment for one related event card.
 *
 * The meta rows and More Info button are caller-owned data, byte-identical
 * to the legacy related-events template (including its whitespace) so the
 * rendered HTML does not change.
 *
 * @param WP_Post $related_post Related event post.
 * @param string  $date_str     Formatted start date, or '' when unknown.
 * @param string  $time_str     Formatted start time, or '' when unknown.
 * @return string Trusted meta-content markup.
 */
function ec_events_related_meta_html( $related_post, $date_str, $time_str ) {
	$t7 = "\t\t\t\t\t\t\t";
	$t8 = "\t\t\t\t\t\t\t\t";
	$t9 = "\t\t\t\t\t\t\t\t\t";

	$meta_html = $t7;
	if ( $date_str ) {
		$meta_html .= $t8 . '<div class="ec-related-meta-item">' . "\n" . $t9 . ec_icon( 'calendar' ) . $t9 . '<span>' . esc_html( $date_str ) . '</span>' . "\n" . $t8 . '</div>' . "\n";
		$meta_html .= $t7;
	}
	$meta_html .= $t7 . "\n";
	$meta_html .= $t7;
	if ( $time_str ) {
		$meta_html .= $t8 . '<div class="ec-related-meta-item">' . "\n" . $t9 . ec_icon( 'clock' ) . $t9 . '<span>' . esc_html( $time_str ) . '</span>' . "\n" . $t8 . '</div>' . "\n";
		$meta_html .= $t7;
	}
	$meta_html .= "\n";
	$meta_html .= $t7 . '<a href="' . esc_url( get_permalink( $related_post ) ) . '" class="data-machine-more-info-button button-3 button-small">More Info</a>' . "\n";

	return $meta_html;
}

/**
 * Build one shared-renderer item for a related event card.
 *
 * @param WP_Post $related_post Related event post.
 * @return array Item for extrachill_render_related_tax_section().
 */
function ec_events_related_item( $related_post ) {
	$permalink  = get_permalink( $related_post );
	$title      = get_the_title( $related_post );
	$image_url  = get_the_post_thumbnail_url( $related_post, 'medium_large' );
	$event_data = data_machine_events_parse_event_data( $related_post ) ?? array();

	$date_str = '';
	$time_str = '';
	if ( ! empty( $event_data['startDate'] ) ) {
		$start_time = ! empty( $event_data['startTime'] ) ? $event_data['startTime'] : '00:00:00';
		$date_obj   = new DateTime( $event_data['startDate'] . ' ' . $start_time, wp_timezone() );
		$date_str   = $date_obj->format( 'D, M j, Y' );
		$time_str   = $date_obj->format( 'g:i A' );
	}

	$thumb_html = $image_url ? '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">' : '';

	return array(
		'id'          => $related_post->ID,
		'layout'      => 'block',
		'permalink'   => $permalink,
		'title'       => $title,
		'thumb_html'  => $thumb_html,
		'badges_html' => data_machine_events_render_taxonomy_badges( $related_post->ID ),
		'meta_html'   => ec_events_related_meta_html( $related_post, $date_str, $time_str ),
	);
}

/**
 * Render custom related events display
 *
 * Resolves related event data through the data-machine-events public
 * integration API and renders it through the theme's shared related-posts
 * section renderer. Degrades to no output when the API or the theme
 * renderer is unavailable (e.g. plugin active on a site running another
 * theme).
 *
 * Items carry their post ID, so the theme's request-level dedup registry
 * applies: an event shown in the venue section is not repeated in the
 * location section on the same page.
 *
 * @param string $taxonomy Taxonomy being queried
 * @param int    $post_id  Current post ID
 */
function ec_events_render_related_posts( $taxonomy, $post_id ) {
	// Gate on data-machine-events public integration API instead of internal
	// class names. See data-machine-events docs/integration-api.md.
	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return;
	}

	// Gate on the theme-owned shared renderer. Without it there is no
	// canonical markup to feed; degrade to no output rather than forking.
	if ( ! function_exists( 'extrachill_render_related_tax_section' ) ) {
		return;
	}

	// Get terms
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}

	$term      = $terms[0];
	$term_id   = $term->term_id;
	$term_link = get_term_link( $term );
	// The legacy template echoed esc_html() over an already-escaped term
	// name, double-encoding entities in term names. Preserved as-is to keep
	// the rendered HTML byte-identical; fixing it is a separate change.
	$term_name = esc_html( esc_html( $term->name ) );

	// Build tax_filters for the ability.
	$ability_tax_filters = array( $taxonomy => array( $term_id ) );

	// For location-based related events, exclude same venue in PHP
	// since the ability's tax_filters only supports IN, not NOT IN.
	$exclude_venue_ids = array();
	if ( 'location' === $taxonomy ) {
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$exclude_venue_ids = wp_list_pluck( $venue_terms, 'term_id' );
		}
	}

	$result = data_machine_events_query_events(
		array(
			'scope'       => 'upcoming',
			'tax_filters' => $ability_tax_filters,
			'exclude'     => array( $post_id ),
			'per_page'    => ! empty( $exclude_venue_ids ) ? 20 : 3,
			'order'       => 'ASC',
		)
	);

	$related_posts_array = $result['posts'] ?? array();

	// Filter out events at excluded venues (for location-based related events).
	if ( ! empty( $exclude_venue_ids ) && ! empty( $related_posts_array ) ) {
		$related_posts_array = array_filter(
			$related_posts_array,
			function ( $rp ) use ( $exclude_venue_ids ) {
				$rp_venues = wp_get_post_terms( $rp->ID, 'venue', array( 'fields' => 'ids' ) );
				if ( is_wp_error( $rp_venues ) ) {
					return true;
				}
				return empty( array_intersect( $rp_venues, $exclude_venue_ids ) );
			}
		);
		$related_posts_array = array_slice( array_values( $related_posts_array ), 0, 3 );
	}

	if ( empty( $related_posts_array ) ) {
		return;
	}

	$items = array();
	foreach ( $related_posts_array as $related_post ) {
		$items[] = ec_events_related_item( $related_post );
	}

	$preposition = ( 'venue' === $taxonomy ) ? 'at' : 'in';

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns pre-escaped markup from trusted, pre-resolved data fragments.
	echo extrachill_render_related_tax_section(
		array(
			'heading_prefix' => 'More ' . $preposition . ' ',
			'term_link'      => $term_link,
			'term_name'      => $term_name,
			'items'          => $items,
		)
	);
}
add_action( 'extrachill_custom_related_posts_display', 'ec_events_render_related_posts', 10, 2 );
