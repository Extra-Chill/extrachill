<?php
/**
 * Login/Register Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

wp_enqueue_script( 'extrachill-auth-utils' );

$google_oauth_enabled = function_exists( 'ec_is_google_oauth_configured' ) && ec_is_google_oauth_configured();

// Single-origin Google OAuth: GIS is enqueued ONLY on the canonical
// origin (community.extrachill.com). Non-canonical subsites render a
// "Sign in with Google" link that redirects to the canonical login
// page, carrying a return_to URL so the user lands back where they
// started after auth completes. See inc/oauth/google-canonical-origin.php.
$google_is_canonical = function_exists( 'ec_users_is_canonical_google_origin' )
	&& ec_users_is_canonical_google_origin();

if ( $google_oauth_enabled && $google_is_canonical ) {
	wp_enqueue_script(
		'google-gsi',
		'https://accounts.google.com/gsi/client',
		array(),
		EXTRACHILL_USERS_VERSION,
		true
	);

	$google_signin_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/google-signin.js';
	if ( file_exists( $google_signin_path ) ) {
		wp_enqueue_script(
			'extrachill-google-signin',
			EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/google-signin.js',
			array( 'google-gsi', 'extrachill-auth-utils' ),
			(string) filemtime( $google_signin_path ),
			true
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flag from URL used for analytics/UX hint, not for state changes.
		$google_from_join = isset( $_GET['from_join'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['from_join'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'extrachill-google-signin',
			'ecGoogleConfig',
			array(
				'clientId' => get_site_option( 'extrachill_google_client_id', '' ),
				'restUrl'  => rest_url( 'extrachill/v1/' ),
				'fromJoin' => $google_from_join,
			)
		);
	}
}

$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
$current_url = set_url_scheme(
	( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $request_uri, '?' )
);

$login_redirect_url = $current_url;
$success_redirect   = $current_url;

if ( ! empty( $attributes['redirectUrl'] )
	&& function_exists( 'ec_users_is_valid_return_to_url' )
	&& ec_users_is_valid_return_to_url( $attributes['redirectUrl'] ) ) {
	$login_redirect_url = esc_url_raw( $attributes['redirectUrl'] );
	$success_redirect   = $login_redirect_url;
}

if ( function_exists( 'ec_users_get_validated_login_redirect_from_request' ) ) {
	$validated_login_redirect = ec_users_get_validated_login_redirect_from_request();
	if ( null !== $validated_login_redirect ) {
		$login_redirect_url = $validated_login_redirect;
		$success_redirect   = $validated_login_redirect;
	}
}

// Single-origin Google OAuth: when a non-canonical subsite redirected
// the user here for sign-in, it carries the original page URL in the
// google_redirect query param. Pick it up and use it as the post-auth
// destination so the user lands back where they started. The helper
// validates the host against the network redirect allowlist and
// returns null on anything else, so this is safe to override
// $success_redirect with.
if ( function_exists( 'ec_users_get_validated_google_redirect_from_request' ) ) {
	$validated_google_redirect = ec_users_get_validated_google_redirect_from_request();
	if ( null !== $validated_google_redirect ) {
		$login_redirect_url = $validated_google_redirect;
		$success_redirect   = $validated_google_redirect;
	}
}

$invite_token                   = null;
$invite_artist_id               = null;
$invited_email                  = '';
$artist_name_for_invite_message = '';
$initial_notice                 = EC_Redirect_Handler::get_message( 'ec_login' );

$registration_notice = EC_Redirect_Handler::get_message( 'ec_registration' );
if ( $registration_notice ) {
	$initial_notice = $registration_notice;
}

if ( isset( $_GET['action'] ) && 'ec_accept_invite' === $_GET['action'] && isset( $_GET['token'] ) && isset( $_GET['artist_id'] ) ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only invite context from signed URL parameters for rendering.
	$token_from_url     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$artist_id_from_url = absint( $_GET['artist_id'] );

	if ( function_exists( 'ec_get_pending_invitations' ) ) {
		$pending_invitations = ec_get_pending_invitations( $artist_id_from_url );
		foreach ( $pending_invitations as $invite ) {
			if ( isset( $invite['token'] ) && $invite['token'] === $token_from_url && isset( $invite['status'] ) && 'invited_new_user' === $invite['status'] ) {
				$invite_token           = $token_from_url;
				$invite_artist_id       = $artist_id_from_url;
				$invited_email          = isset( $invite['email'] ) ? sanitize_email( $invite['email'] ) : '';
				$artist_post_for_invite = get_post( $invite_artist_id );

				if ( $artist_post_for_invite instanceof WP_Post ) {
					$artist_name_for_invite_message = $artist_post_for_invite->post_title;
					$initial_notice                 = array(
						/* translators: %s: artist name */
						'text' => sprintf( __( 'You have been invited to join the artist \'%s\'! Please complete your registration below to accept.', 'extrachill-users' ), $artist_name_for_invite_message ),
						'type' => 'info',
					);
				}

				break;
			}
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flag from URL used for analytics/UX hint, not for state changes.
$from_join = isset( $_GET['from_join'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['from_join'] ) );
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// Cross-origin redirect URL: when Google OAuth is enabled but we're
// NOT on the canonical origin, the view layer renders a "Sign in with
// Google" link pointing here instead of trying to render the GIS
// button locally (which would silently fail because GIS rejects
// origins not pre-registered in GCP).
$google_signin_redirect_url = null;
if ( $google_oauth_enabled && ! $google_is_canonical && function_exists( 'ec_users_canonical_google_signin_url' ) ) {
	$google_signin_redirect_url = ec_users_canonical_google_signin_url( $success_redirect );
}

$config = array(
	'loggedIn'                => is_user_logged_in(),
	'googleOAuthEnabled'      => $google_oauth_enabled,
	'googleSignInRedirectUrl' => $google_signin_redirect_url,
	'currentUrl'              => $current_url,
	'loginRedirectUrl'        => $login_redirect_url,
	'successRedirectUrl'      => $success_redirect,
	'resetPasswordUrl'        => ec_get_site_url( 'community' ) . '/reset-password/',
	'inviteToken'             => $invite_token,
	'inviteArtistId'          => $invite_artist_id,
	'invitedEmail'            => $invited_email,
	'fromJoin'                => $from_join,
	'turnstileHtml'           => wp_kses_post( ec_render_turnstile_widget() ),
	'initialNotice'           => $initial_notice ? array(
		'message' => $initial_notice['text'] ?? '',
		'type'    => $initial_notice['type'] ?? 'info',
	) : null,
);

if ( is_user_logged_in() ) {
	$logged_in_user        = wp_get_current_user();
	$config['displayName'] = $logged_in_user->display_name;
	$config['profileUrl']  = ec_get_site_url( 'community' ) . '/u/' . $logged_in_user->user_nicename . '/';
	$config['homeUrl']     = home_url();
	$config['logoutUrl']   = wp_logout_url( home_url() );
	$config['avatarHtml']  = get_avatar( $logged_in_user->ID, 80 );
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-extrachill-login-register',
	)
);

?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML. ?>>
	<div
		data-ec-login-register-root
		data-ec-login-register-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>"
	></div>
</div>

<?php do_action( 'extrachill_below_login_register_form' ); ?>
