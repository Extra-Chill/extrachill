<?php
/**
 * OG Card Data Resolver
 *
 * Resolves the data payload that each post type's OG template should
 * receive. Generic and filterable so any plugin can register a data
 * collector for its own post type without coupling to extrachill-network.
 *
 * The post-type → template mapping is filterable too so plugins can claim
 * their own post types ("use template X for post type Y") without us
 * touching this file.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the OG card template ID for a post.
 *
 * @param \WP_Post $post Post object.
 * @return string|null Template ID, or null when no card should be generated.
 */
function template_id_for_post( \WP_Post $post ): ?string {
	$default_map = array(
		'data_machine_events' => 'event_og_card',
	);

	/**
	 * Filter the post-type → OG card template ID map.
	 *
	 * Plugins register their post types and the GD template ID to use
	 * (which must be registered with Data Machine separately). Returning
	 * null for a post type opts it out of card generation.
	 *
	 * @param array<string, string> $map Post type slug => template ID.
	 */
	$map = (array) apply_filters( 'extrachill_og_card_template_map', $default_map );

	return $map[ $post->post_type ] ?? null;
}

/**
 * Resolve the data payload for a post's OG card.
 *
 * Dispatches to a per-post-type filter. Plugins register data collectors
 * via `extrachill_og_card_data_<post_type>`. The result is then passed
 * through the generic `extrachill_og_card_data` filter so cross-cutting
 * concerns (location colors, author overlay, etc.) can layer on top
 * without knowing about specific post types.
 *
 * @param \WP_Post $post Post object.
 * @return array Data payload (empty array if no collector responded).
 */
function resolve_card_data( \WP_Post $post ): array {
	/**
	 * Filter providing the OG card data for a specific post type.
	 *
	 * Plugins return the structured data their template expects.
	 *
	 * @param array    $data Empty array initially.
	 * @param \WP_Post $post Post object.
	 */
	$data = (array) apply_filters( "extrachill_og_card_data_{$post->post_type}", array(), $post );

	/**
	 * Generic filter applied to all OG card data after per-post-type
	 * collection. Use this to layer brand/context overrides (e.g. inject
	 * location colors via `_brand_override`) without coupling to the
	 * specific data shape of any one post type.
	 *
	 * @param array    $data Per-post-type data payload.
	 * @param \WP_Post $post Post object.
	 */
	return (array) apply_filters( 'extrachill_og_card_data', $data, $post );
}

/**
 * Default data collector for the events post type.
 *
 * Reads title, start date, venue (name + city/state) from the events
 * plugin's existing primitives. The events plugin owns its data shape;
 * this collector only adapts it to the OG card field contract.
 *
 * @param array    $data Existing data (from earlier hooks).
 * @param \WP_Post $post Event post.
 * @return array
 */
function collect_event_card_data( array $data, $post ): array {
	if ( ! $post instanceof \WP_Post ) {
		return $data;
	}

	$data['event_name'] = (string) $post->post_title;

	// Uses data-machine-events public integration API. See data-machine-events
	// docs/integration-api.md.
	if ( function_exists( 'datamachine_get_event_dates' ) ) {
		$dates = datamachine_get_event_dates( (int) $post->ID );
		if ( $dates && ! empty( $dates->start_datetime ) ) {
			$start_obj = date_create( $dates->start_datetime );
			if ( $start_obj ) {
				$end_obj = ! empty( $dates->end_datetime ) ? date_create( $dates->end_datetime ) : null;
				if ( $end_obj && $start_obj->format( 'Y-m-d' ) !== $end_obj->format( 'Y-m-d' ) ) {
					if ( $start_obj->format( 'Y-m' ) === $end_obj->format( 'Y-m' ) ) {
						$data['date_label'] = sprintf(
							'%s %s–%s, %s',
							$start_obj->format( 'M' ),
							$start_obj->format( 'j' ),
							$end_obj->format( 'j' ),
							$start_obj->format( 'Y' )
						);
					} else {
						$data['date_label'] = sprintf(
							'%s – %s',
							$start_obj->format( 'M j, Y' ),
							$end_obj->format( 'M j, Y' )
						);
					}
				} else {
					$data['date_label'] = $start_obj->format( 'M j, Y' );
				}
			}
		}
	}

	$venue_terms = get_the_terms( $post->ID, 'venue' );
	if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
		$venue_term    = $venue_terms[0];
		$data['venue'] = (string) $venue_term->name;

		if ( function_exists( 'data_machine_events_get_venue_data' ) ) {
			$venue_data    = data_machine_events_get_venue_data( (int) $venue_term->term_id );
			$data['venue'] = (string) ( $venue_data['name'] ?? $venue_term->name );

			$city_parts   = array_filter(
				array(
					$venue_data['city'] ?? '',
					$venue_data['state'] ?? '',
				)
			);
			$data['city'] = implode( ', ', $city_parts );
		}
	}

	return $data;
}

add_filter( 'extrachill_og_card_data_data_machine_events', __NAMESPACE__ . '\\collect_event_card_data', 10, 2 );

/**
 * Inject per-location brand overrides into OG card data.
 *
 * Layers location badge colors (from @extrachill/tokens / root.css) onto
 * any post that has a `location` taxonomy term. Works across post types
 * — events, blog posts, anything else that uses the platform's location
 * taxonomy automatically picks up city-specific palettes.
 *
 * @param array    $data Existing card data.
 * @param \WP_Post $post Post object.
 * @return array
 */
function inject_location_overrides( array $data, $post ): array {
	if ( ! $post instanceof \WP_Post ) {
		return $data;
	}

	$terms = get_the_terms( $post->ID, 'location' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return $data;
	}

	$term   = $terms[0];
	$colors = term_badge_colors( $term, 'location' );
	if ( ! $colors ) {
		return $data;
	}

	$existing = (array) ( $data['_brand_override'] ?? array() );

	$existing['colors']         = array_merge(
		(array) ( $existing['colors'] ?? array() ),
		array(
			'accent'      => $colors['bg'],
			'accent_text' => $colors['text'],
		)
	);
	$existing['location_label'] = $term->name;

	$data['_brand_override'] = $existing;

	return $data;
}

add_filter( 'extrachill_og_card_data', __NAMESPACE__ . '\\inject_location_overrides', 10, 2 );
