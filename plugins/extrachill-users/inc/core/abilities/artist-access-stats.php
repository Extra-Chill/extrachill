<?php
/**
 * Artist Access Stats Ability
 *
 * Read-side rollup for the artist access funnel
 * (Extra-Chill/extrachill-cli#31). Business logic lives here; the
 * `wp extrachill users access stats` CLI command is a thin wrapper.
 *
 * Single-source design: the windowed REQUEST count is read from the
 * `artist_access_requested` analytics event (emitted by the
 * request-artist-access ability) via the canonical analytics counter —
 * NOT re-derived from `artist_access_request.requested_at` meta. This
 * keeps one source of truth per extrachill-users#127 / extrachill-cli#31
 * coordination. The current GRANTED count is a point-in-time meta tally
 * (user_is_artist / user_is_professional) the events stream doesn't carry.
 *
 * @package ExtraChill\Users
 * @since   0.18.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_artist_access_stats_ability' );

/**
 * Register the get-artist-access-stats ability.
 */
function extrachill_users_register_artist_access_stats_ability() {
	wp_register_ability(
		'extrachill/get-artist-access-stats',
		array(
			'label'               => __( 'Get Artist Access Stats', 'extrachill-users' ),
			'description'         => __( 'Windowed artist access-request count plus current granted artist/professional counts.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days' => array(
						'type'        => 'integer',
						'description' => __( 'Window in days for the request count. 0 for all time.', 'extrachill-users' ),
						'default'     => 28,
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_artist_access_stats',
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
 * Execute callback for get-artist-access-stats ability.
 *
 * @param array $input Input parameters with optional 'days'.
 * @return array Stats rollup.
 */
function extrachill_users_ability_get_artist_access_stats( $input ) {
	$days = isset( $input['days'] ) ? (int) $input['days'] : 28;

	// Windowed request count from the artist_access_requested event stream
	// (single source). Falls back to the pending-request meta tally only
	// when the analytics reader is unavailable.
	$requests_in_window = extrachill_users_count_window_events( 'artist_access_requested', $days );

	$granted = extrachill_users_count_granted_access();

	return array(
		'days'                 => $days,
		'period'               => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'requests_in_window'   => $requests_in_window,
		'pending_requests'     => extrachill_users_count_pending_requests(),
		'granted_artist'       => $granted['artist'],
		'granted_professional' => $granted['professional'],
		'granted_total'        => $granted['artist'] + $granted['professional'],
	);
}

/**
 * Count users currently granted artist and professional access.
 *
 * @return array{artist:int,professional:int}
 */
function extrachill_users_count_granted_access() {
	// phpcs:disable WordPress.DB.SlowDBQuery -- Infrequent admin/CLI read-side counts.
	$artist_query = new WP_User_Query(
		array(
			'blog_id'     => 0,
			'meta_key'    => 'user_is_artist',
			'meta_value'  => '1',
			'fields'      => 'ID',
			'count_total' => true,
			'number'      => 1,
		)
	);

	$professional_query = new WP_User_Query(
		array(
			'blog_id'     => 0,
			'meta_key'    => 'user_is_professional',
			'meta_value'  => '1',
			'fields'      => 'ID',
			'count_total' => true,
			'number'      => 1,
		)
	);
	// phpcs:enable WordPress.DB.SlowDBQuery

	return array(
		'artist'       => (int) $artist_query->get_total(),
		'professional' => (int) $professional_query->get_total(),
	);
}

/**
 * Count users with a pending (un-approved) artist access request.
 *
 * @return int
 */
function extrachill_users_count_pending_requests() {
	$query = new WP_User_Query(
		array(
			'blog_id'     => 0,
			// phpcs:ignore WordPress.DB.SlowDBQuery -- Infrequent admin/CLI read-side count.
			'meta_key'    => 'artist_access_request',
			'fields'      => 'ID',
			'count_total' => true,
			'number'      => 1,
		)
	);

	return (int) $query->get_total();
}
