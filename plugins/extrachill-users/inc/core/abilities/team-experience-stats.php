<?php
/**
 * Team Experience Stats Ability
 *
 * Read-side cohort rollup for the team-experience instrumentation
 * (Extra-Chill/extrachill-users#127). Business logic lives here; the
 * `wp extrachill users team stats` CLI command is a thin wrapper.
 *
 * Why a dedicated ability and not just the raw analytics summary: the
 * existing `extrachill/get-analytics-summary` reader already exposes per
 * event_type windowed counts and is the read path for those. This ability
 * adds ONLY the cohort rollup that summary can't express — the current
 * `extra_chill_team` membership count plus a curated view of the
 * team/tool event counts in one call. It composes the existing reader; it
 * does not reimplement event counting.
 *
 * @package ExtraChill\Users
 * @since   0.18.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_team_experience_stats_ability' );

/**
 * Register the get-team-experience-stats ability.
 */
function extrachill_users_register_team_experience_stats_ability() {
	wp_register_ability(
		'extrachill/get-team-experience-stats',
		array(
			'label'               => __( 'Get Team Experience Stats', 'extrachill-users' ),
			'description'         => __( 'Team cohort rollup: current extra_chill_team count, members added/removed in window, and Studio/Roadie/submission event counts.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days' => array(
						'type'        => 'integer',
						'description' => __( 'Window in days for event counts. 0 for all time.', 'extrachill-users' ),
						'default'     => 28,
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_team_experience_stats',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for get-team-experience-stats ability.
 *
 * @param array $input Input parameters with optional 'days'.
 * @return array Cohort rollup.
 */
function extrachill_users_ability_get_team_experience_stats( $input ) {
	$days = isset( $input['days'] ) ? (int) $input['days'] : 28;

	// Single-source event contract (extrachill-users#129): iterate the
	// canonical group constant owned by extrachill-analytics instead of
	// re-listing string literals, so this reader can never desync from the
	// emit sites (which reference the same EC_ANALYTICS_EVENT_* constants).
	$team_event_types = EC_ANALYTICS_TEAM_EXPERIENCE_EVENTS;

	$events = array();
	foreach ( $team_event_types as $event_type ) {
		$events[ $event_type ] = extrachill_users_count_window_events( $event_type, $days );
	}

	return array(
		'days'                 => $days,
		'period'               => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'team_member_count'    => extrachill_users_count_team_members(),
		'team_members_added'   => $events[ EC_ANALYTICS_EVENT_TEAM_MEMBER_ADDED ],
		'team_members_removed' => $events[ EC_ANALYTICS_EVENT_TEAM_MEMBER_REMOVED ],
		'events'               => $events,
	);
}

/**
 * Count current extra_chill_team role holders on the main site.
 *
 * The role is assigned network-wide, so counting on any one site is
 * representative; the main site is canonical.
 *
 * @return int
 */
function extrachill_users_count_team_members() {
	$role = defined( 'EC_USERS_TEAM_ROLE' ) ? EC_USERS_TEAM_ROLE : 'extra_chill_team';

	$counts = count_users();
	if ( isset( $counts['avail_roles'][ $role ] ) ) {
		return (int) $counts['avail_roles'][ $role ];
	}

	return 0;
}

/**
 * Count analytics events of a type within a day window.
 *
 * Delegates to the existing extrachill-analytics counter (the canonical
 * read path) rather than reimplementing the query. Returns 0 when the
 * analytics reader is unavailable.
 *
 * @param string $event_type Event type identifier.
 * @param int    $days       Window in days. 0 for all time.
 * @return int
 */
function extrachill_users_count_window_events( $event_type, $days ) {
	if ( ! function_exists( 'extrachill_count_analytics_events' ) ) {
		return 0;
	}

	$args = array( 'event_type' => $event_type );

	if ( $days > 0 ) {
		$args['date_from'] = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	}

	return extrachill_count_analytics_events( $args );
}
