<?php
/**
 * REST routes: photo contest (extrachill-content-blocks).
 *
 * POST /wp-json/extrachill/v1/content-blocks/photo-contest/submit
 * GET  /wp-json/extrachill/v1/content-blocks/photo-contest/entries
 * GET  /wp-json/extrachill/v1/content-blocks/photo-contest/entry-qr
 * POST /wp-json/extrachill/v1/content-blocks/photo-contest/vote
 *
 * Thin transport adapters for the extrachill/photo-contest-submit,
 * extrachill/photo-contest-list, extrachill/photo-contest-entry-qr, and
 * extrachill/photo-contest-vote abilities
 * (extrachill-content-blocks plugin). The routes only normalize requests,
 * pass multipart $_FILES data through, and map ability error codes to HTTP
 * statuses. All business logic lives in the owning abilities.
 *
 * The submit and vote routes are the public write boundary: each consumes
 * the shared per-client public-write budget before the handler runs. The
 * write abilities are hidden from the core REST runner (show_in_rest:false)
 * so this admission layer cannot be bypassed; a REST-visible ability is
 * refused with 503. The entries route stays open like the other cacheable
 * anonymous reads (calendar, taxonomy counts). QR reads use the shared
 * public-read limiter to bound image generation.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_content_blocks_photo_contest_routes' );

/** Register the photo contest submit, entries, entry QR, and vote adapters. */
function extrachill_api_register_content_blocks_photo_contest_routes() {
	$public_submit_permission = function_exists( 'ec_turnstile_permission_callback' )
		? ec_turnstile_permission_callback( 'extrachill_api_content_blocks_photo_contest_submit_permission' )
		: 'extrachill_api_content_blocks_photo_contest_submit_permission';

	// Contest moderators (logged in, nonce-authenticated) add entries on
	// someone's behalf: no Turnstile or public-write budget. The ability
	// owns what a moderator may do differently (e.g. omit the email).
	$submit_permission = static function ( WP_REST_Request $request ) use ( $public_submit_permission ) {
		if ( function_exists( 'extrachill_content_blocks_photo_contest_can_moderate' )
			&& extrachill_content_blocks_photo_contest_can_moderate() ) {
			return true;
		}
		return call_user_func( $public_submit_permission, $request );
	};

	register_rest_route(
		'extrachill/v1',
		'/content-blocks/photo-contest/submit',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_content_blocks_photo_contest_submit_handler',
			'permission_callback' => $submit_permission,
			'args'                => array(
				'post_id'      => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'contest_id'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				// Required for the public; contest moderators may omit it
				// (enforced by the ability).
				'email'        => array(
					'required'          => false,
					'type'              => 'string',
					'format'            => 'email',
					'maxLength'         => 254,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_email',
				),
				'display_name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'instagram'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				// Honeypot: must stay empty. The ability owns enforcement; the
				// route passes it through untouched so spam decisions are not
				// duplicated at the transport boundary.
				'website'      => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'turnstile_response' => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/content-blocks/photo-contest/entries',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_content_blocks_photo_contest_entries_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id'    => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'contest_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/content-blocks/photo-contest/entry-qr',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_content_blocks_photo_contest_entry_qr_handler',
			'permission_callback' => 'extrachill_api_content_blocks_photo_contest_entry_qr_permission',
			'args'                => array(
				'post_id'    => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'contest_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'entry_id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/content-blocks/photo-contest/vote',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_content_blocks_photo_contest_vote_handler',
			'permission_callback' => 'extrachill_api_content_blocks_photo_contest_vote_permission',
			'args'                => array(
				'post_id'       => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'contest_id'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'entry_id'      => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'email_address' => array(
					'required'          => true,
					'type'              => 'string',
					'format'            => 'email',
					'maxLength'         => 254,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		)
	);
}

/**
 * Apply the shared public-write budget to contest submissions.
 *
 * The route's Turnstile wrapper verifies the token before this atomic
 * per-client fixed-window limiter shared by all public writes runs.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function extrachill_api_content_blocks_photo_contest_submit_permission( WP_REST_Request $request ) {
	/**
	 * Filters the photo contest submissions allowed per client per minute.
	 *
	 * @param int $limit Default 5.
	 */
	$limit = (int) apply_filters( 'extrachill_api_photo_contest_submit_rate_limit', 5 );
	return extrachill_api_check_public_write_rate_limit( $request, 'photo-contest-submit', $limit );
}

/**
 * Apply the shared public-write budget to contest votes.
 *
 * The cap is deliberately looser than submit: voters cluster behind shared
 * venue wifi, and email dedup inside the ability is the primary control.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function extrachill_api_content_blocks_photo_contest_vote_permission( WP_REST_Request $request ) {
	/**
	 * Filters the photo contest votes allowed per client per minute.
	 *
	 * @param int $limit Default 30.
	 */
	$limit = (int) apply_filters( 'extrachill_api_photo_contest_vote_rate_limit', 30 );
	return extrachill_api_check_public_write_rate_limit( $request, 'photo-contest-vote', $limit );
}

/**
 * Apply the shared public-read budget to entry QR generation.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function extrachill_api_content_blocks_photo_contest_entry_qr_permission( WP_REST_Request $request ) {
	/**
	 * Filters the entry QR reads allowed per client per minute.
	 *
	 * @param int $limit Default 30.
	 */
	$limit = (int) apply_filters( 'extrachill_api_photo_contest_entry_qr_rate_limit', 30 );
	return extrachill_api_check_public_read_rate_limit( $request, 'photo-contest-entry-qr', $limit );
}

/**
 * Refuse write abilities that the core REST runner could execute directly.
 *
 * Mirrors the booking inquiry handler: the public-write admission on these
 * routes is only meaningful while the backing ability stays hidden from the
 * core abilities REST controller, so a REST-visible ability is refused.
 *
 * @param WP_Ability $ability Resolved photo contest write ability.
 * @return WP_Error|null 503 refusal when REST-visible, null when hidden.
 */
function extrachill_api_content_blocks_photo_contest_require_hidden( $ability ) {
	if ( $ability->get_meta_item( 'show_in_rest' ) ) {
		return new WP_Error(
			'photo_contest_unavailable',
			__( 'Photo contest is unavailable.', 'extrachill-api' ),
			array( 'status' => 503 )
		);
	}

	return null;
}

/**
 * Map an ability error code to the route's HTTP status contract.
 *
 * The mapped status wins over any ability-provided status so the public
 * transport contract stays stable. Unmapped codes keep the ability's own
 * status when present and fall back to 500. All other error data (for
 * example the `entry` payload on `already_entered`) is preserved.
 *
 * @param WP_Error $error      Ability error.
 * @param array    $status_map Error code to HTTP status map.
 * @return WP_Error Error with a transport status applied.
 */
function extrachill_api_content_blocks_photo_contest_error( WP_Error $error, array $status_map ) {
	$code = $error->get_error_code();
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : array();

	if ( isset( $status_map[ $code ] ) ) {
		$data['status'] = $status_map[ $code ];
	} elseif ( ! isset( $data['status'] ) || ! is_int( $data['status'] ) ) {
		$data['status'] = 500;
	}

	return new WP_Error( $code, $error->get_error_message(), $data );
}

/**
 * Handle photo contest entry submission (multipart/form-data).
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error Submission response.
 */
function extrachill_api_content_blocks_photo_contest_submit_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/photo-contest-submit' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			'Photo contest unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$refused = extrachill_api_content_blocks_photo_contest_require_hidden( $ability );
	if ( $refused ) {
		return $refused;
	}

	$input = array(
		'post_id'      => $request->get_param( 'post_id' ),
		'contest_id'   => $request->get_param( 'contest_id' ),
		'display_name' => $request->get_param( 'display_name' ),
		'instagram'    => (string) $request->get_param( 'instagram' ),
		'website'      => (string) $request->get_param( 'website' ),
	);

	// Omitted for moderator-added entries; the ability's email format check
	// would reject an empty string.
	$email = (string) $request->get_param( 'email' );
	if ( '' !== $email ) {
		$input['email'] = $email;
	}

	// Pass the multipart photo upload through untouched.
	$files = $request->get_file_params();
	if ( isset( $files['photo'] ) && is_array( $files['photo'] ) ) {
		$input['photo'] = $files['photo'];
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return extrachill_api_content_blocks_photo_contest_error(
			$result,
			array(
				'invalid_submission' => 400,
				'invalid_email'      => 400,
				'missing_name'       => 400,
				'missing_photo'      => 400,
				'upload_failed'      => 400,
				'invalid_photo_type' => 400,
				'photo_too_large'    => 413,
				'rate_limited'       => 429,
				'contest_not_found'  => 404,
				'entries_closed'     => 403,
				// Keeps error data.entry so the entrant gets their share link back.
				'already_entered'    => 409,
			)
		);
	}

	return rest_ensure_response(
		array(
			'message' => $result['message'] ?? '',
			'entry'   => $result['entry'] ?? null,
		)
	);
}

/**
 * Handle the public photo contest entries listing.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error Entries response.
 */
function extrachill_api_content_blocks_photo_contest_entries_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/photo-contest-list' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			'Photo contest unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute(
		array(
			'post_id'    => $request->get_param( 'post_id' ),
			'contest_id' => $request->get_param( 'contest_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return extrachill_api_content_blocks_photo_contest_error(
			$result,
			array(
				'contest_not_found' => 404,
			)
		);
	}

	$entries  = is_array( $result ) && array_key_exists( 'entries', $result ) ? $result['entries'] : $result;
	$data     = array( 'entries' => is_array( $entries ) ? array_values( $entries ) : array() );
	// Live contest phase (e.g. a moderator's manual close) rides along so the
	// public page can switch state without waiting for its cached HTML.
	if ( is_array( $result ) && isset( $result['phase'] ) && is_array( $result['phase'] ) ) {
		$data['phase'] = $result['phase'];
	}
	$response = rest_ensure_response( $data );
	// Cacheable, but vote counts must stay fresh: keep the window short.
	$response->header( 'Cache-Control', 'public, max-age=15' );

	return $response;
}

/**
 * Handle the public entry QR image read.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error QR image response.
 */
function extrachill_api_content_blocks_photo_contest_entry_qr_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/photo-contest-entry-qr' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			'Photo contest unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute(
		array(
			'post_id'    => $request->get_param( 'post_id' ),
			'contest_id' => $request->get_param( 'contest_id' ),
			'entry_id'   => $request->get_param( 'entry_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return extrachill_api_content_blocks_photo_contest_error(
			$result,
			array(
				'contest_not_found' => 404,
				'entry_not_found'   => 404,
			)
		);
	}

	$response = rest_ensure_response(
		array(
			'image'     => $result['image'],
			'mime_type' => $result['mime_type'],
		)
	);
	// The image is deterministic per entry; cache successful reads briefly.
	$response->header( 'Cache-Control', 'public, max-age=60' );

	return $response;
}

/**
 * Handle a photo contest vote (JSON body).
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error Vote response.
 */
function extrachill_api_content_blocks_photo_contest_vote_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/photo-contest-vote' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			'Photo contest unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$refused = extrachill_api_content_blocks_photo_contest_require_hidden( $ability );
	if ( $refused ) {
		return $refused;
	}

	$result = $ability->execute(
		array(
			'post_id'       => $request->get_param( 'post_id' ),
			'contest_id'    => $request->get_param( 'contest_id' ),
			'entry_id'      => $request->get_param( 'entry_id' ),
			'email_address' => $request->get_param( 'email_address' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return extrachill_api_content_blocks_photo_contest_error(
			$result,
			array(
				'entry_not_found'   => 404,
				'contest_not_found' => 404,
				'voting_closed'     => 403,
				'already_voted'     => 409,
				'invalid_email'     => 400,
				'rate_limited'      => 429,
			)
		);
	}

	return rest_ensure_response(
		array(
			'message'    => $result['message'] ?? '',
			'vote_count' => $result['vote_count'] ?? 0,
		)
	);
}
