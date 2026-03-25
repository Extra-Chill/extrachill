<?php
/**
 * Artist Access Abilities
 *
 * Registers abilities for managing artist platform access requests.
 * Business logic lives here; REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_artist_access_abilities' );

/**
 * Register artist access management abilities.
 */
function extrachill_users_register_artist_access_abilities() {
	wp_register_ability(
		'extrachill/list-artist-access-requests',
		array(
			'label'               => __( 'List Artist Access Requests', 'extrachill-users' ),
			'description'         => __( 'Get all pending artist platform access requests.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_list_artist_access_requests',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/approve-artist-access',
		array(
			'label'               => __( 'Approve Artist Access', 'extrachill-users' ),
			'description'         => __( 'Approve a pending artist platform access request.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'type'    => array(
						'type' => 'string',
						'enum' => array( 'artist', 'professional' ),
					),
				),
				'required'   => array( 'user_id', 'type' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_approve_artist_access',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/request-artist-access',
		array(
			'label'               => __( 'Request Artist Access', 'extrachill-users' ),
			'description'         => __( 'Submit a request for artist platform access. User-facing ability.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'type'    => array(
						'type' => 'string',
						'enum' => array( 'artist', 'professional' ),
					),
				),
				'required'   => array( 'user_id', 'type' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_request_artist_access',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/reject-artist-access',
		array(
			'label'               => __( 'Reject Artist Access', 'extrachill-users' ),
			'description'         => __( 'Reject a pending artist platform access request.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_reject_artist_access',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * List all pending artist access requests.
 *
 * @param array $input Unused.
 * @return array Array with 'requests' key containing pending request data.
 */
function extrachill_users_ability_list_artist_access_requests( $input ) {
	$user_query = new WP_User_Query(
		array(
			'blog_id'  => 0,
			'meta_key' => 'artist_access_request',
			'fields'   => 'all',
			'orderby'  => 'registered',
			'order'    => 'DESC',
		)
	);

	$users    = $user_query->get_results();
	$requests = array();

	foreach ( $users as $user ) {
		$request_data = get_user_meta( $user->ID, 'artist_access_request', true );
		if ( empty( $request_data ) || ! is_array( $request_data ) ) {
			continue;
		}

		$requests[] = array(
			'user_id'      => $user->ID,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'type'         => isset( $request_data['type'] ) ? $request_data['type'] : 'artist',
			'requested_at' => isset( $request_data['timestamp'] ) ? $request_data['timestamp'] : 0,
		);
	}

	return array( 'requests' => $requests );
}

/**
 * Approve a pending artist access request.
 *
 * Sets the appropriate user meta (user_is_artist or user_is_professional),
 * removes the pending request meta, and sends the approval email.
 *
 * @param array $input Input with 'user_id' and 'type'.
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_approve_artist_access( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$type    = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	if ( ! in_array( $type, array( 'artist', 'professional' ), true ) ) {
		return new WP_Error( 'invalid_type', 'type must be "artist" or "professional".' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Check if already approved.
	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';

	if ( $has_artist || $has_professional ) {
		return array(
			'success'  => true,
			'message'  => 'User already has artist/professional access.',
			'user_id'  => $user_id,
			'skipped'  => true,
		);
	}

	// Check for pending request.
	$pending = get_user_meta( $user_id, 'artist_access_request', true );
	if ( empty( $pending ) || ! is_array( $pending ) ) {
		return new WP_Error( 'no_pending_request', 'No pending access request found for this user.' );
	}

	// Grant access.
	$meta_key = 'artist' === $type ? 'user_is_artist' : 'user_is_professional';
	update_user_meta( $user_id, $meta_key, '1' );
	delete_user_meta( $user_id, 'artist_access_request' );

	// Send approval notification email.
	extrachill_users_send_artist_access_approval_email( $user, $type );

	return array(
		'success'    => true,
		'message'    => 'User approved as ' . $type . '.',
		'user_id'    => $user_id,
		'user_login' => $user->user_login,
		'type'       => $type,
	);
}

/**
 * Reject a pending artist access request.
 *
 * Removes the pending request meta without granting access.
 *
 * @param array $input Input with 'user_id'.
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_reject_artist_access( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	delete_user_meta( $user_id, 'artist_access_request' );

	return array(
		'success'    => true,
		'message'    => 'Access request rejected.',
		'user_id'    => $user_id,
		'user_login' => $user->user_login,
	);
}

/**
 * Request artist platform access (user-facing).
 *
 * Creates a pending request and sends notification email to admin.
 * Used by the settings page block.
 *
 * @param array $input Input with 'user_id' and 'type' (artist|professional).
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_request_artist_access( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$type    = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	if ( ! in_array( $type, array( 'artist', 'professional' ), true ) ) {
		return new WP_Error( 'invalid_type', 'type must be "artist" or "professional".' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Already has access?
	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';

	if ( $has_artist || $has_professional ) {
		return new WP_Error( 'already_has_access', 'You already have artist platform access.' );
	}

	// Already has pending request?
	$pending = get_user_meta( $user_id, 'artist_access_request', true );
	if ( ! empty( $pending ) && is_array( $pending ) ) {
		return new WP_Error( 'already_pending', 'You already have a pending access request.' );
	}

	// Create the request.
	$request_data = array(
		'type'         => $type,
		'requested_at' => time(),
		'user_email'   => $user->user_email,
	);
	update_user_meta( $user_id, 'artist_access_request', $request_data );

	// Send admin notification email.
	extrachill_users_send_artist_access_request_email( $user_id, $user, $type );

	return array(
		'success' => true,
		'message' => 'Your request has been submitted. An administrator will review it shortly.',
		'user_id' => $user_id,
		'type'    => $type,
	);
}

/**
 * Send artist access request notification email to admin.
 *
 * @param int      $user_id     User ID requesting access.
 * @param \WP_User $user        User object.
 * @param string   $access_type Type of access requested.
 */
function extrachill_users_send_artist_access_request_email( $user_id, $user, $access_type ) {
	$admin_email = get_option( 'admin_email' );
	$type_label  = 'artist' === $access_type
		? __( 'I am a musician', 'extrachill-users' )
		: __( 'I work in the music industry', 'extrachill-users' );

	$token = function_exists( 'extrachill_api_generate_artist_access_token' )
		? extrachill_api_generate_artist_access_token( $user_id, $access_type, time() )
		: '';

	$approve_url = '';
	if ( $token ) {
		$approve_url = add_query_arg(
			array(
				'type'  => $access_type,
				'token' => $token,
			),
			rest_url( 'extrachill/v1/admin/artist-access/' . $user_id . '/approve' )
		);
	}

	$admin_tools_url = admin_url( 'tools.php?page=extrachill-admin-tools#artist-access-requests' );

	$subject = sprintf(
		/* translators: %s: user display name */
		__( 'Artist Access Request - %s', 'extrachill-users' ),
		$user->display_name
	);

	$message = sprintf(
		"%s (%s) has requested artist platform access.\n\nRequest type: %s\n\n",
		$user->display_name,
		$user->user_email,
		$type_label
	);

	if ( $approve_url ) {
		$message .= sprintf( "Approve this request:\n%s\n\n", $approve_url );
	}

	$message .= sprintf( "Manage all requests:\n%s", $admin_tools_url );

	wp_mail( $admin_email, $subject, $message );
}

/**
 * Send an approval notification email to the user.
 *
 * @param \WP_User $user The approved user.
 * @param string   $type Access type granted ('artist' or 'professional').
 */
function extrachill_users_send_artist_access_approval_email( $user, $type ) {
	$create_url = 'https://artist.extrachill.com/create-artist/';
	$type_label = 'artist' === $type ? 'artist' : 'music industry professional';

	$subject = 'Your Extra Chill Artist Platform Access Has Been Approved';
	$message = "Hey {$user->display_name},\n\n";
	$message .= "Your request for {$type_label} access on Extra Chill has been approved!\n\n";
	$message .= "You can now create your artist profile and link page:\n";
	$message .= "{$create_url}\n\n";
	$message .= "Your link page will be available at extrachill.link/your-artist-name once you set it up.\n\n";
	$message .= "Welcome to the platform.\n\n";
	$message .= "— Extra Chill\n";
	$message .= "https://extrachill.com";

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	wp_mail( $user->user_email, $subject, $message, $headers );
}
