<?php
/** Browser SSO for the mapped Link Pages host. */

defined( 'ABSPATH' ) || exit;

const EC_LINK_SESSION_STATE_COOKIE = '__Host-ec_link_session_state';

/** Build a handoff endpoint on the Link Pages site. */
function ec_network_link_session_url( string $action, array $args = array() ): string {
	$base = ec_get_site_url( 'link_pages' );
	return $base ? add_query_arg( array( 'action' => $action ) + $args, $base . '/wp-admin/admin-post.php' ) : '';
}

/** Allow only an HTTPS Link Pages root, public slug or /edit URL. */
function ec_network_link_session_return_url( string $url ): string {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' )
		|| ec_network_link_page_public_host() !== ( $parts['host'] ?? '' )
		|| isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
		return '';
	}
	$path = $parts['path'] ?? '/';
	// Permit encoded non-ASCII slug bytes, not encoded ASCII delimiters.
	if ( ! preg_match( '~^/(?:(?:[a-z0-9_-]|%[89a-f][a-f0-9])+/?)?$~Di', $path ) ) {
		return '';
	}
	$query = $parts['query'] ?? '';
	if ( '' !== $query && ( '/edit' !== rtrim( $path, '/' ) || ! preg_match( '/^(?:ec_link_sso=1(?:&link_page=[0-9]+)?|link_page=[0-9]+)$/D', $query ) ) ) {
		return '';
	}
	return $url;
}

/** Read an untrusted return parameter without accepting array input. */
function ec_network_link_session_requested_return(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only return URL; redemption checks the token and state.
	return isset( $_GET['return'] ) && is_string( $_GET['return'] )
		? ec_network_link_session_return_url( esc_url_raw( wp_unslash( $_GET['return'] ) ) )
		: '';
}

/** A state-bound payload may only be accepted by the browser that started SSO. */
function ec_network_link_session_matches_state( array $payload, string $state ): bool {
	return 1 === preg_match( '/^[a-zA-Z0-9]{64}$/D', $state )
		&& hash_equals( (string) ( $payload['browser_state_hash'] ?? '' ), hash( 'sha256', $state ) );
}

/** Build the front-end login continuation; ordinary members cannot enter wp-admin. */
function ec_network_link_session_mint_url( string $return, string $challenge, bool $prompt = false ): string {
	$args = array( 'ec_link_session_mint' => '1', 'return' => rawurlencode( $return ), 'challenge' => $challenge );
	if ( $prompt ) {
		$args['login'] = '1';
	}
	return add_query_arg( $args, trailingslashit( ec_get_site_url( 'artist' ) ) );
}

/** Send the browser to the network with state bound to a host-only cookie. */
function ec_network_link_session_start() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- State and authentication are checked on redemption.
	$return = ec_network_link_session_requested_return();
	$prompt = isset( $_GET['login'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! ec_network_link_page_cookie_host( $_SERVER['HTTP_HOST'] ?? '' ) || ! is_ssl() || ! $return ) {
		wp_die( 'Invalid Link Page sign-in request.', '', array( 'response' => 400 ) );
	}

	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	if ( is_user_logged_in() ) {
		wp_safe_redirect( $return );
		exit;
	}
	$state = wp_generate_password( 64, false, false );
	setcookie( EC_LINK_SESSION_STATE_COOKIE, $state, array( 'expires' => time() + 15 * MINUTE_IN_SECONDS, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
	wp_safe_redirect( ec_network_link_session_mint_url( $return, hash( 'sha256', $state ), $prompt ) );
	exit;
}
add_action( 'admin_post_ec_link_session_start', 'ec_network_link_session_start' );
add_action( 'admin_post_nopriv_ec_link_session_start', 'ec_network_link_session_start' );

/** Mint on the network, where the shared auth cookie is first-party. */
function ec_network_link_session_mint() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The opaque state is verified on redemption.
	if ( ! isset( $_GET['ec_link_session_mint'] ) || (int) get_current_blog_id() !== ec_get_blog_id( 'artist' ) ) {
		return;
	}
	$return = ec_network_link_session_requested_return();
	$challenge = sanitize_text_field( wp_unslash( $_GET['challenge'] ?? '' ) );
	$prompt    = isset( $_GET['login'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! is_ssl() || ! $return || ! preg_match( '/^[a-f0-9]{64}$/D', $challenge ) ) {
		wp_die( 'Invalid Link Page sign-in request.', '', array( 'response' => 400 ) );
	}

	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	$user_id = empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? 0 : (int) wp_validate_auth_cookie( '', 'logged_in' );
	if ( ! $user_id || $user_id !== get_current_user_id() ) {
		if ( $prompt && function_exists( 'ec_users_login_url_with_redirect' ) ) {
			$resume = ec_network_link_session_mint_url( $return, $challenge );
			wp_safe_redirect( ec_users_login_url_with_redirect( home_url( '/login/' ), $resume ) );
		} else {
			wp_safe_redirect( $return );
		}
		exit;
	}
	if ( ! function_exists( 'extrachill_users_create_browser_handoff_token' ) ) {
		wp_die( 'Link Page sign-in is unavailable.', '', array( 'response' => 503 ) );
	}
	$token = extrachill_users_create_browser_handoff_token( $user_id, $return, $challenge );
	wp_safe_redirect( ec_network_link_session_url( 'ec_link_session_finish' ) . '#handoff=' . rawurlencode( $token ) );
	exit;
}
add_action( 'template_redirect', 'ec_network_link_session_mint', 0 );

/** Receive the fragment on an isolated page and submit it in a same-origin POST. */
function ec_network_link_session_finish() {
	if ( ! ec_network_link_page_cookie_host( $_SERVER['HTTP_HOST'] ?? '' ) || ! is_ssl() ) {
		wp_die( 'Invalid Link Page sign-in host.', '', array( 'response' => 400 ) );
	}
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$script_nonce = wp_generate_password( 32, false, false );
		header( "Content-Security-Policy: default-src 'none'; script-src 'nonce-{$script_nonce}'; form-action 'self'; frame-ancestors 'none'" );
		?>
		<!doctype html><html lang="en"><meta charset="utf-8"><title>Signing in</title>
		<form method="post" action="<?php echo esc_url( ec_network_link_session_url( 'ec_link_session_finish' ) ); ?>"><input type="hidden" name="handoff"></form>
		<p>Signing in to Link Pages…</p><noscript>JavaScript is required to finish signing in.</noscript>
		<script nonce="<?php echo esc_attr( $script_nonce ); ?>">
			const token = new URLSearchParams( location.hash.slice( 1 ) ).get( 'handoff' );
			history.replaceState( null, '', location.pathname + location.search );
			if ( token ) {
				document.forms[ 0 ].elements.handoff.value = token;
				document.forms[ 0 ].submit();
			} else {
				document.querySelector( 'p' ).textContent = 'Sign-in expired. Return to Link Pages and try again.';
			}
		</script></html>
		<?php
		exit;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The shared service consumes a one-time token, then we verify the browser state cookie.
	$token = sanitize_text_field( wp_unslash( $_POST['handoff'] ?? '' ) );
	$state = sanitize_text_field( wp_unslash( $_COOKIE[ EC_LINK_SESSION_STATE_COOKIE ] ?? '' ) );
	if ( ! preg_match( '/^[a-zA-Z0-9]{64}$/D', $token ) || ! function_exists( 'extrachill_users_consume_browser_handoff_token' ) || ! function_exists( 'extrachill_users_establish_browser_handoff_session' ) ) {
		wp_die( 'Invalid Link Page sign-in token.', '', array( 'response' => 400 ) );
	}
	$payload = extrachill_users_consume_browser_handoff_token( $token );
	if ( ! is_array( $payload ) || ! ec_network_link_session_matches_state( $payload, $state ) || ! ec_network_link_session_return_url( $payload['redirect_url'] ) ) {
		wp_die( 'Link Page sign-in expired or does not match this browser. Please try again.', '', array( 'response' => 400 ) );
	}
	setcookie( EC_LINK_SESSION_STATE_COOKIE, '', array( 'expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
	$user = get_user_by( 'id', (int) $payload['user_id'] );
	if ( ! $user || ! extrachill_users_establish_browser_handoff_session( $user, (int) ( $payload['two_factor_verified_at'] ?? 0 ) ) ) {
		wp_die( 'Please sign in to the network again and complete any two-factor challenge, then retry.', '', array( 'response' => 403 ) );
	}
	wp_safe_redirect( $payload['redirect_url'], 303 );
	exit;
}
add_action( 'admin_post_ec_link_session_finish', 'ec_network_link_session_finish' );
add_action( 'admin_post_nopriv_ec_link_session_finish', 'ec_network_link_session_finish' );
