<?php
/**
 * Conversion outcome trust classification.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classify one outcome by the strongest evidence that it represents a person.
 *
 * Authenticated server-side outcomes remain trusted even when request-origin
 * heuristics stamped them as bots before the newly created user became current.
 * Anonymous bot-stamped rows are excluded; a stable visitor identifies a
 * browser outcome only when the canonical bot stamp is not true.
 *
 * @param array $outcome Normalized outcome row.
 * @return string Trust class.
 */
function extrachill_analytics_conversion_outcome_trust_class( $outcome ) {
	if ( extrachill_analytics_conversion_outcome_user_id( $outcome ) > 0 ) {
		return 'authenticated_server';
	}

	$data = isset( $outcome['event_data'] ) && is_array( $outcome['event_data'] ) ? $outcome['event_data'] : array();
	if ( true === ( $data['is_bot'] ?? null ) ) {
		return 'confirmed_bot';
	}

	if ( '' !== trim( (string) ( $outcome['visitor_id'] ?? '' ) ) ) {
		return 'visitor_identified_browser';
	}

	return 'unclassified';
}

/**
 * Keep the strongest trust evidence observed across duplicate outcome rows.
 *
 * @param string $current Current trust class.
 * @param string $candidate Candidate trust class.
 * @return string Strongest trust class.
 */
function extrachill_analytics_conversion_stronger_outcome_trust_class( $current, $candidate ) {
	$rank = array(
		'unclassified'               => 0,
		'visitor_identified_browser' => 1,
		'authenticated_server'       => 2,
	);

	return ( $rank[ $candidate ] ?? -1 ) > ( $rank[ $current ] ?? -1 ) ? $candidate : $current;
}
