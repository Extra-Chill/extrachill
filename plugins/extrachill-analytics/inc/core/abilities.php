<?php
/**
 * Abilities API Integration
 *
 * Registers analytics tracking capabilities via the WordPress Abilities API.
 *
 * @package ExtraChill\Analytics
 * @since 0.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_analytics_register_category' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_abilities' );

/**
 * Register analytics ability category.
 */
function extrachill_analytics_register_category() {
	wp_register_ability_category(
		'extrachill-analytics',
		array(
			'label'       => __( 'Extra Chill Analytics', 'extrachill-analytics' ),
			'description' => __( 'Analytics tracking capabilities', 'extrachill-analytics' ),
		)
	);
}

/**
 * Register analytics abilities.
 */
function extrachill_analytics_register_abilities() {
	wp_register_ability(
		'extrachill/track-analytics-event',
		array(
			'label'       => __( 'Track Analytics Event', 'extrachill-analytics' ),
			'description' => __( 'Record an analytics event to the network-wide events table.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'event_type' => array(
						'type'        => 'string',
						'description' => __( 'Event type identifier (e.g., newsletter_signup, user_registration, search).', 'extrachill-analytics' ),
					),
					'event_data' => array(
						'type'        => 'object',
						'description' => __( 'Flexible payload data stored as JSON.', 'extrachill-analytics' ),
						'default'     => array(),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the event occurred.', 'extrachill-analytics' ),
						'default'     => '',
					),
				),
				'required' => array( 'event_type' ),
			),
			'output_schema' => array(
				'type'        => 'integer',
				'description' => __( 'Event ID on success, 0 on failure.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_event',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
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
 * Execute callback for track-analytics-event ability.
 *
 * @param array $input Input parameters.
 * @return int Event ID on success, 0 on failure.
 */
function extrachill_analytics_ability_track_event( $input ) {
	if ( empty( $input['event_type'] ) ) {
		return 0;
	}

	$event_type = $input['event_type'];
	$event_data = isset( $input['event_data'] ) ? $input['event_data'] : array();
	$source_url = isset( $input['source_url'] ) ? $input['source_url'] : '';

	/**
	 * Filter whether to track an analytics event.
	 *
	 * Allows event types/contexts to opt-out of tracking. For example,
	 * auto-subscriptions during registration aren't explicit user actions.
	 *
	 * @param bool   $should_track Whether to track this event. Default true.
	 * @param string $event_type   Event type identifier.
	 * @param array  $event_data   Event payload data.
	 * @param string $source_url   URL where event occurred.
	 */
	$should_track = apply_filters( 'extrachill_should_track_analytics_event', true, $event_type, $event_data, $source_url );

	if ( ! $should_track ) {
		return 0;
	}

	$result = extrachill_track_analytics_event(
		$event_type,
		$event_data,
		$source_url
	);

	return $result ? $result : 0;
}
