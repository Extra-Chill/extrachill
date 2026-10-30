<?php
/**
 * Events-owned taxonomy context for Data Machine Events rows.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_register_ability_args', 'extrachill_events_add_events_by_term_taxonomy_schema', 10, 2 );
add_filter( 'data_machine_events_events_by_term_result', 'extrachill_events_add_events_by_term_taxonomy_context', 10, 2 );

/**
 * Declare Events-owned taxonomy context on the generic ability's response.
 *
 * @param array  $args Ability arguments.
 * @param string $name Ability name.
 * @return array Ability arguments.
 */
function extrachill_events_add_events_by_term_taxonomy_schema( array $args, string $name ): array {
	if ( 'data-machine-events/events-by-term' !== $name || empty( $args['output_schema']['properties'] ) ) {
		return $args;
	}

	$term_schema                                = array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'name' => array( 'type' => 'string' ),
			'slug' => array( 'type' => 'string' ),
			'url'  => array( 'type' => 'string' ),
		),
	);
	$location_schema                            = $term_schema;
	$location_schema['properties']['display']   = array( 'type' => 'string' );
	$location_schema['properties']['hierarchy'] = array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'region' => array( 'type' => 'string' ),
			'state'  => array( 'type' => 'string' ),
			'label'  => array( 'type' => 'string' ),
		),
	);

	$args['output_schema']['properties']['upcoming']['items']['properties']['relationships'] = array(
		'type'       => 'object',
		'properties' => array(
			'venue'    => $term_schema,
			'location' => $location_schema,
			'festival' => $term_schema,
		),
	);
	$args['output_schema']['properties']['past']['items']['properties']['relationships']     = $args['output_schema']['properties']['upcoming']['items']['properties']['relationships'];

	return $args;
}

/**
 * Add canonical Events taxonomy relationships to each returned event row.
 *
 * This filter runs while Data Machine Events remains switched to the events
 * site, so relationship terms and archive URLs resolve in their canonical
 * context.
 *
 * @param array $result Events-by-term result.
 * @param array $input  Ability input.
 * @return array Events-by-term result.
 */
function extrachill_events_add_events_by_term_taxonomy_context( array $result, array $input ): array {
	unset( $input );

	foreach ( array( 'upcoming', 'past' ) as $scope ) {
		if ( empty( $result[ $scope ] ) || ! is_array( $result[ $scope ] ) ) {
			continue;
		}

		foreach ( $result[ $scope ] as $index => $event ) {
			$post_id = isset( $event['event_id'] ) ? (int) $event['event_id'] : 0;
			if ( $post_id <= 0 ) {
				continue;
			}

			$result[ $scope ][ $index ]['relationships'] = array(
				'venue'    => extrachill_events_get_event_term_relationship( $post_id, 'venue' ),
				'location' => extrachill_events_get_event_term_relationship( $post_id, 'location' ),
				'festival' => extrachill_events_get_event_term_relationship( $post_id, 'festival' ),
			);
		}
	}

	return $result;
}

/**
 * Return an event's assigned term as a portable archive relationship.
 *
 * Events currently assign one term per relationship taxonomy. The first term
 * mirrors the existing venue-name response behavior without inferring data.
 *
 * @param int    $post_id Event post ID.
 * @param string $taxonomy Relationship taxonomy.
 * @return array|null Relationship data, or null when the event is unassigned.
 */
function extrachill_events_get_event_term_relationship( int $post_id, string $taxonomy ): ?array {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( empty( $terms ) || is_wp_error( $terms ) || ! isset( $terms[0] ) || ! ( $terms[0] instanceof WP_Term ) ) {
		return null;
	}

	$term = $terms[0];
	$url  = get_term_link( $term );
	$data = array(
		'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
		'slug' => (string) $term->slug,
		'url'  => is_wp_error( $url ) ? '' : (string) $url,
	);

	if ( 'location' !== $taxonomy || ! function_exists( 'extrachill_events_prepare_canonical_location' ) ) {
		return $data;
	}

	$location = extrachill_events_prepare_canonical_location( $term );
	if ( is_array( $location ) && isset( $location['hierarchy'] ) && is_array( $location['hierarchy'] ) ) {
		$data['display']   = (string) ( $location['hierarchy']['label'] ?? $term->name );
		$data['hierarchy'] = $location['hierarchy'];
	} else {
		$data['display']   = (string) $term->name;
		$data['hierarchy'] = null;
	}

	return $data;
}
