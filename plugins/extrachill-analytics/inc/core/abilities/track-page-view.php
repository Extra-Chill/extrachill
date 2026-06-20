<?php
declare(strict_types=1);
/**
 * Track Page View Ability
 *
 * Write-side ability that increments post view counts.
 * High-frequency hot-path — keeps logic minimal.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the track-page-view ability.
 */
function extrachill_analytics_register_track_page_view_ability(): void {
	wp_register_ability(
		'extrachill/track-page-view',
		array(
			'label'               => __( 'Track Page View', 'extrachill-analytics' ),
			'description'         => __( 'Increment the view counter for a post. High-frequency endpoint called async after page load.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to record a view for.', 'extrachill-analytics' ),
					),
					'visitor_id' => array(
						'type'        => 'string',
						'description' => __( 'Optional anonymous first-party visitor UUID v4. Stored on the pageview event only if well-formed; never PII. Empty when the visitor opted out (GPC/DNT).', 'extrachill-analytics' ),
						'default'     => '',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Confirmation object with recorded flag.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_page_view',
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
 * Execute callback for track-page-view ability.
 *
 * Mirrors the existing REST handler: quick post-meta increment,
 * plus link-page daily-table action when applicable. Additionally writes a
 * `pageview` event row to the network-wide events table (carrying the
 * anonymous visitor_id when present) so per-visitor retention history exists.
 * The post-meta bump is retained for back-compat with the theme view counter.
 *
 * @param array $input Input parameters.
 * @return array{recorded: bool}|WP_Error Confirmation or error.
 */
function extrachill_analytics_ability_track_page_view( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$visitor_id = isset( $input['visitor_id'] ) ? (string) $input['visitor_id'] : '';

	if ( $post_id <= 0 ) {
		return new \WP_Error(
			'invalid_post_id',
			__( 'A valid post_id is required.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_track_post_views' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'View tracking function not available.', 'extrachill-analytics' ),
			array( 'status' => 500 )
		);
	}

	// All-time view increment (post meta) — retained for theme back-compat.
	ec_track_post_views( $post_id );

	// Write a deterministic pageview event row so per-visitor retention can be
	// queried. visitor_id is persisted only when it is a valid UUID v4; an empty
	// or opted-out value still records the pageview anonymously (NULL visitor_id),
	// keeping aggregate volume accurate. Bots are excluded via the canonical
	// classifier's UA-class signal so the retention table stays bot-resistant by
	// construction, using the one shared bot-UA pattern list.
	//
	// The pageview gate intentionally keys on the UA signal ONLY (not the full
	// strict verdict): this beacon is a browser-initiated fetch, and a
	// privacy-opted-out human (GPC/DNT, hence no ec_vid cookie) is still a real
	// pageview we want counted anonymously. Requiring the cookie here would drop
	// those legitimate humans. Per-visitor retention metrics already exclude
	// NULL-visitor_id rows downstream, so anonymous pageviews never distort them.
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';

	$is_bot = function_exists( 'extrachill_analytics_classify_user_agent' )
		&& 'browser' !== extrachill_analytics_classify_user_agent( $user_agent );

	if ( ! $is_bot && function_exists( 'extrachill_track_analytics_event' ) ) {
		$permalink = get_permalink( $post_id );
		extrachill_track_analytics_event(
			defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview',
			array( 'post_id' => $post_id ),
			is_string( $permalink ) ? $permalink : '',
			$visitor_id
		);
	}

	// Link pages also fire the 90-day daily-table action.
	if ( get_post_type( $post_id ) === 'artist_link_page' ) {
		do_action( 'extrachill_link_page_view_recorded', $post_id );
	}

	return array( 'recorded' => true );
}
