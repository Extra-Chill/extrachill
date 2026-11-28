<?php
/**
 * Public calendar analytics adapter. The browser cannot select an event type.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/** Register the REST-visible calendar query ability. */
function extrachill_analytics_register_track_calendar_query_ability(): void {
	wp_register_ability(
		'extrachill/track-calendar-query',
		array(
			'label'               => __( 'Track Calendar Query', 'extrachill-analytics' ),
			'description'         => __( 'Record a calendar query from the public browser.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source_url' => array(
						'type'      => 'string',
						'maxLength' => 2048,
					),
					'event_data' => array( 'type' => 'object' ),
				),
				'required'             => array( 'source_url', 'event_data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'integer' ),
			'execute_callback'    => 'extrachill_analytics_ability_track_calendar_query',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Pass a calendar query through shared browser admission.
 *
 * @param array $input Browser input.
 * @return int|WP_Error
 */
function extrachill_analytics_ability_track_calendar_query( array $input ) {
	$input['event_type'] = EC_ANALYTICS_EVENT_CALENDAR_QUERY;
	return extrachill_analytics_ability_track_event( $input );
}

/**
 * Validate the closed calendar query payload before resolving term IDs.
 *
 * @param array $data Browser event data.
 * @return true|WP_Error
 */
function extrachill_analytics_validate_calendar_query( array $data ) {
	$valid = ! array_diff( array_keys( $data ), array( 'trigger', 'scope', 'has_date_range', 'search_term', 'taxonomies', 'archive_taxonomy', 'archive_term', 'result_count', 'route', 'is_bot' ) )
		&& in_array( $data['trigger'] ?? null, EC_ANALYTICS_CALENDAR_TRIGGERS, true )
		&& in_array( $data['route'] ?? null, extrachill_analytics_route_families(), true )
		&& is_bool( $data['has_date_range'] ?? null )
		&& is_int( $data['result_count'] ?? null ) && $data['result_count'] >= 0;

	foreach ( array(
		'scope'            => 32,
		'archive_taxonomy' => 32,
		'search_term'      => 4096,
	) as $field => $max ) {
		if ( isset( $data[ $field ] ) && ( ! is_string( $data[ $field ] ) || strlen( $data[ $field ] ) > $max || ( 'search_term' !== $field && sanitize_key( $data[ $field ] ) !== $data[ $field ] ) ) ) {
			$valid = false;
		}
	}
	if ( isset( $data['archive_term'] ) && ( ! is_int( $data['archive_term'] ) || $data['archive_term'] <= 0 || empty( $data['archive_taxonomy'] ) ) ) {
		$valid = false;
	}

	$taxonomies = $data['taxonomies'] ?? array();
	if ( ! is_array( $taxonomies ) || count( $taxonomies ) > 8 ) {
		$valid = false;
	} else {
		foreach ( $taxonomies as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || strlen( $taxonomy ) > 32 || sanitize_key( $taxonomy ) !== $taxonomy || ! is_array( $ids ) || array_values( $ids ) !== $ids || count( $ids ) > 25 ) {
				$valid = false;
				break;
			}
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id <= 0 ) {
					$valid = false;
					break 2;
				}
			}
		}
	}

	return $valid ? true : new WP_Error( 'invalid_calendar_query', __( 'Invalid calendar query dimensions.', 'extrachill-analytics' ), array( 'status' => 400 ) );
}

/**
 * Translate upstream term IDs to current-site slugs for reporting.
 *
 * @param array $data Admitted calendar event data.
 * @return array Normalized event data.
 */
function extrachill_analytics_resolve_calendar_terms( array $data ): array {
	$taxonomies = array();
	foreach ( $data['taxonomies'] ?? array() as $taxonomy => $ids ) {
		if ( ! $ids || ! is_object_in_taxonomy( 'data_machine_events', $taxonomy ) ) {
			continue;
		}
		$slugs = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		if ( is_array( $slugs ) && $slugs ) {
			$taxonomies[ $taxonomy ] = $slugs;
		}
	}
	$data['taxonomies'] = (object) $taxonomies;

	$taxonomy = $data['archive_taxonomy'] ?? '';
	$term     = isset( $data['archive_term'] ) && is_object_in_taxonomy( 'data_machine_events', $taxonomy ) ? get_term( $data['archive_term'], $taxonomy ) : null;
	if ( $term instanceof WP_Term ) {
		$data['archive_term'] = $term->slug;
	} else {
		unset( $data['archive_taxonomy'], $data['archive_term'] );
	}
	return $data;
}
