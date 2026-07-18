<?php
/**
 * Analytics integration for Network-owned experiment assignment.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contribute Analytics' existing identity without minting a new subject.
 *
 * Network supplies authenticated WordPress subjects before this filter. Keep
 * those intact; anonymous requests receive the existing privacy-aware ec_vid.
 *
 * @param string $subject_key    Existing subject key.
 * @param string $experiment_key Experiment key (unused; identity is generic).
 * @param string $surface        Surface (unused; identity is generic).
 * @param array  $context        Consumer context (unused; identity is generic).
 * @return string Subject key, or an empty string when no identity exists.
 */
function extrachill_analytics_experiment_subject_key( $subject_key, $experiment_key = '', $surface = '', $context = array() ) {
	unset( $experiment_key, $surface, $context );
	$subject_key = trim( (string) $subject_key );
	if ( '' !== $subject_key ) {
		return $subject_key;
	}

	$visitor_id = function_exists( 'extrachill_analytics_read_visitor_id' ) ? extrachill_analytics_read_visitor_id() : '';

	return '' !== $visitor_id ? 'ec-vid:' . $visitor_id : '';
}
add_filter( 'extrachill_experiment_subject_key', 'extrachill_analytics_experiment_subject_key', 10, 4 );

/**
 * Contribute Analytics' established GPC/DNT measurement policy.
 *
 * Identity availability remains Network's separate subject-key check. This
 * filter answers only whether this request opted out of measured assignment.
 *
 * @param bool   $eligible       Existing provider decision.
 * @param string $experiment_key Experiment key (unused; privacy is generic).
 * @param string $surface        Surface (unused; privacy is generic).
 * @param array  $context        Consumer context (unused; privacy is generic).
 * @return bool Whether experiment measurement is privacy-eligible.
 */
function extrachill_analytics_experiment_measurement_eligible( $eligible, $experiment_key = '', $surface = '', $context = array() ) {
	unset( $eligible, $experiment_key, $surface, $context );

	return function_exists( 'extrachill_analytics_visitor_opted_out' )
		&& ! extrachill_analytics_visitor_opted_out();
}
add_filter( 'extrachill_experiment_measurement_eligible', 'extrachill_analytics_experiment_measurement_eligible', 10, 4 );

/**
 * Persist a Network-validated first-consumer experiment event.
 *
 * Network fires the assignment hook only after a measured assignment resolves,
 * and the exposure hook only after re-validating the browser's 50%-viewport
 * signal against that assignment. This callback accepts no browser request and
 * persists only the three bounded scalar dimensions supplied by those hooks.
 *
 * @param string $event_type Canonical assignment or exposure event name.
 * @param array  $metadata   Network-validated experiment metadata.
 * @return int|false Event ID, or false when the contract/privacy check fails.
 */
function extrachill_analytics_record_experiment_event( $event_type, $metadata ) {
	if ( ! is_array( $metadata ) || array( 'experiment_key', 'variant', 'surface' ) !== array_keys( $metadata ) ) {
		return false;
	}

	$experiment_key = $metadata['experiment_key'];
	$variant        = $metadata['variant'];
	$surface        = $metadata['surface'];
	if (
		! in_array( $event_type, array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE ), true )
		|| ! is_string( $experiment_key )
		|| ! is_string( $variant )
		|| ! is_string( $surface )
		|| EC_ANALYTICS_EXPERIMENT_GEO_BRIDGE_HOLDOUT !== $experiment_key
		|| EC_ANALYTICS_EXPERIMENT_SURFACE_SINGLE_POST_BRIDGE !== $surface
		|| ! in_array( $variant, EC_ANALYTICS_EXPERIMENT_VARIANTS, true )
		|| ( function_exists( 'extrachill_analytics_visitor_opted_out' ) && extrachill_analytics_visitor_opted_out() )
	) {
		return false;
	}

	$visitor_id = function_exists( 'extrachill_analytics_read_visitor_id' ) ? extrachill_analytics_read_visitor_id() : '';
	$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( '' === $visitor_id && $user_id <= 0 ) {
		return false;
	}

	return extrachill_track_analytics_event(
		$event_type,
		$metadata,
		'',
		$visitor_id
	);
}

/**
 * Persist a measured assignment resolved by Network.
 *
 * @param array $metadata Bounded assignment metadata.
 */
function extrachill_analytics_record_experiment_assignment( $metadata ) {
	extrachill_analytics_record_experiment_event( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $metadata );
}
add_action( 'extrachill_experiment_assignment', 'extrachill_analytics_record_experiment_assignment', 10, 1 );

/**
 * Persist a measured viewport exposure validated by Network.
 *
 * @param array $metadata Bounded exposure metadata.
 */
function extrachill_analytics_record_experiment_exposure( $metadata ) {
	extrachill_analytics_record_experiment_event( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $metadata );
}
add_action( 'extrachill_experiment_exposure', 'extrachill_analytics_record_experiment_exposure', 10, 1 );
