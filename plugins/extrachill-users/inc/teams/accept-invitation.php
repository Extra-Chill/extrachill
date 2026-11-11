<?php
/** Signed team invitation email callback. */

defined( 'ABSPATH' ) || exit;

/** Resolve an acceptance request through the registered adapter. */
function ec_users_team_invitation_target( array $request, int $user_id ) {
	$type    = sanitize_key( wp_unslash( $request['team_type'] ?? '' ) );
	$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( $type );
	if ( ! $adapter || ! in_array( 'invitations', $adapter->operations(), true ) ) {
		return new WP_Error( 'invalid_invitation_team_type', 'Invalid invitation team type.' );
	}
	$service = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
	$id      = absint( $request['team_id'] ?? $request[ $type . '_term_id' ] ?? $request['team_term_id'] ?? 0 );
	$result  = $service->respond(
		$user_id,
		sanitize_text_field( wp_unslash( $request['invitation_id'] ?? '' ) ),
		sanitize_text_field( wp_unslash( $request['token'] ?? '' ) ),
		$id,
		! empty( $request['is_owner'] ),
		absint( $request['version'] ?? 0 ),
		\ExtraChill\Users\Teams\TeamOnboardingRepository::INVITE_ACCEPTED
	);
	return $adapter->accepted_url( $id, ! is_wp_error( $result ) );
}

/** Authenticate via login, then consume the single-use token as its bound user. */
function ec_accept_team_invitation() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Single-use signed email token.
	$request = $_GET;
	if ( ! get_current_user_id() ) {
		$url = add_query_arg( array_map( 'sanitize_text_field', wp_unslash( $request ) ), admin_url( 'admin-post.php' ) );
		wp_safe_redirect( wp_login_url( $url ) );
		exit;
	}
	$target = ec_users_team_invitation_target( $request, get_current_user_id() );
	if ( is_wp_error( $target ) ) {
		wp_die( esc_html__( 'Invalid invitation team type.', 'extrachill-users' ) );
	}
	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_post_ec_accept_team_invitation', 'ec_accept_team_invitation' );
add_action( 'admin_post_nopriv_ec_accept_team_invitation', 'ec_accept_team_invitation' );
