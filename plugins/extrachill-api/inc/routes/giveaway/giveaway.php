<?php
/**
 * Giveaway REST API Endpoints
 *
 * Thin wrappers around the extrachill/run-giveaway and
 * extrachill/resolve-instagram-media abilities.
 *
 * @endpoint POST /wp-json/extrachill/v1/giveaway/run
 * @endpoint POST /wp-json/extrachill/v1/giveaway/schedule
 * @endpoint POST /wp-json/extrachill/v1/instagram/resolve
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_giveaway_routes' );

/**
 * Register giveaway endpoints.
 */
function extrachill_api_register_giveaway_routes() {
	// Run giveaway (instant).
	register_rest_route(
		'extrachill/v1',
		'/giveaway/run',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_giveaway_run_handler',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'media_input'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Instagram post URL, shortcode, or numeric media ID.',
				),
				'require_tag'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'min_tags'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'winner_count' => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'announce'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'message'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => 'Congratulations @{username}, you won the giveaway! Check your DMs for details.',
				),
			),
		)
	);

	// Schedule giveaway (deferred).
	register_rest_route(
		'extrachill/v1',
		'/giveaway/schedule',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_giveaway_schedule_handler',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'media_input'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'require_tag'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'min_tags'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'winner_count' => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'announce'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'message'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => 'Congratulations @{username}, you won the giveaway! Check your DMs for details.',
				),
				'run_at'       => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => 'UTC datetime (ISO 8601) when the giveaway should run.',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// Resolve Instagram URL to media ID.
	register_rest_route(
		'extrachill/v1',
		'/instagram/resolve',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_instagram_resolve_handler',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'input' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Instagram post URL, shortcode, or numeric media ID.',
				),
			),
		)
	);
}

/**
 * Run giveaway handler — instant execution.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_giveaway_run_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/run-giveaway' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Giveaway ability not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'media_input'  => $request->get_param( 'media_input' ),
		'require_tag'  => $request->get_param( 'require_tag' ),
		'min_tags'     => $request->get_param( 'min_tags' ),
		'winner_count' => $request->get_param( 'winner_count' ),
		'announce'     => $request->get_param( 'announce' ),
		'message'      => $request->get_param( 'message' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Schedule giveaway handler — deferred via DM Task System.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_giveaway_schedule_handler( WP_REST_Request $request ) {
	if ( ! class_exists( 'DataMachine\\Engine\\Tasks\\TaskScheduler' ) ) {
		return new WP_Error( 'task_system_missing', 'Data Machine Task System not available.', array( 'status' => 500 ) );
	}

	$run_at = $request->get_param( 'run_at' );
	$timestamp = strtotime( $run_at );
	if ( ! $timestamp || $timestamp <= time() ) {
		return new WP_Error( 'invalid_run_at', 'run_at must be a valid future UTC datetime.', array( 'status' => 400 ) );
	}

	$params = array(
		'media_input'  => $request->get_param( 'media_input' ),
		'require_tag'  => $request->get_param( 'require_tag' ),
		'min_tags'     => $request->get_param( 'min_tags' ),
		'winner_count' => $request->get_param( 'winner_count' ),
		'announce'     => $request->get_param( 'announce' ),
		'message'      => $request->get_param( 'message' ),
	);

	$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule(
		'giveaway',
		$params,
		array(
			'user_id'      => get_current_user_id(),
			'origin'       => 'studio_api',
			'scheduled_at' => $run_at,
		)
	);

	if ( ! $job_id ) {
		return new WP_Error( 'schedule_failed', 'Failed to schedule giveaway task.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'job_id'       => $job_id,
		'task_type'    => 'giveaway',
		'scheduled_at' => $run_at,
		'params'       => $params,
	) );
}

/**
 * Instagram resolve handler.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_instagram_resolve_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/resolve-instagram-media' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Resolve ability not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'input' => $request->get_param( 'input' ) ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
