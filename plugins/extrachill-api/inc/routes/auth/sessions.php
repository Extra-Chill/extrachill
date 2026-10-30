<?php
/**
 * REST routes: auth sessions.
 *
 * GET    /wp-json/extrachill/v1/auth/sessions
 * DELETE /wp-json/extrachill/v1/auth/sessions/<device_id>
 *
 * Thin adapters over the wp-native/auth-sessions and
 * wp-native/auth-revoke-session abilities (wp-native-auth). Both are
 * registered show_in_rest => false there on purpose — the browser-side
 * Connected Apps screen reaches them through this namespace, consistent
 * with every sibling route in this directory.
 *
 * Both abilities derive the acting user from the authenticated bearer
 * token / cookie session internally; neither accepts a user id as input.
 * These routes never forward a client-supplied user id, so a client can
 * only ever see or revoke its own sessions.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_AUTH_SESSIONS_LIST_ABILITY   = 'wp-native/auth-sessions';
const EXTRACHILL_API_AUTH_SESSIONS_REVOKE_ABILITY = 'wp-native/auth-revoke-session';

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_sessions_routes' );

/**
 * Registers the auth sessions routes.
 */
function extrachill_api_register_auth_sessions_routes() {
	register_rest_route(
		'extrachill/v1',
		'/auth/sessions',
		array(
			'methods'               => WP_REST_Server::READABLE,
			'callback'              => 'extrachill_api_auth_sessions_list_handler',
			'permission_callback'   => 'is_user_logged_in',
			'_extrachill_abilities' => array( EXTRACHILL_API_AUTH_SESSIONS_LIST_ABILITY ),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/auth/sessions/(?P<device_id>[0-9a-fA-F-]{36})',
		array(
			'methods'               => WP_REST_Server::DELETABLE,
			'callback'              => 'extrachill_api_auth_sessions_revoke_handler',
			'permission_callback'   => 'is_user_logged_in',
			'args'                  => array(
				'device_id' => array(
					'required'          => true,
					'type'              => 'string',
					'pattern'           => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			'_extrachill_abilities' => array( EXTRACHILL_API_AUTH_SESSIONS_REVOKE_ABILITY ),
		)
	);
}

/**
 * Handles GET /auth/sessions — list the current user's active device
 * sessions.
 *
 * Wraps the wp-native/auth-sessions ability, which takes no input and
 * derives the acting user from wp_get_current_user() internally. Nothing
 * here selects whose sessions come back except the authenticated session
 * itself.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_auth_sessions_list_handler( WP_REST_Request $request ) {
	if ( ! wp_has_ability( EXTRACHILL_API_AUTH_SESSIONS_LIST_ABILITY ) ) {
		return new WP_Error( 'ability_not_found', 'wp-native-auth plugin is required.', array( 'status' => 500 ) );
	}
	$ability = wp_get_ability( EXTRACHILL_API_AUTH_SESSIONS_LIST_ABILITY );

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handles DELETE /auth/sessions/{device_id} — revoke one of the current
 * user's device sessions.
 *
 * Wraps the wp-native/auth-revoke-session ability. The ability resolves the
 * owning user from the authenticated bearer token / cookie session, not
 * from input — device_id only ever selects which of *that* user's own
 * sessions to revoke, never which user's sessions to touch.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_auth_sessions_revoke_handler( WP_REST_Request $request ) {
	if ( ! wp_has_ability( EXTRACHILL_API_AUTH_SESSIONS_REVOKE_ABILITY ) ) {
		return new WP_Error( 'ability_not_found', 'wp-native-auth plugin is required.', array( 'status' => 500 ) );
	}
	$ability = wp_get_ability( EXTRACHILL_API_AUTH_SESSIONS_REVOKE_ABILITY );

	$result = $ability->execute(
		array(
			'device_id' => (string) $request->get_param( 'device_id' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
