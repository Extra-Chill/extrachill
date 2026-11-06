<?php
/**
 * Branded, expiring password signups backed by the core multisite signup table.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_ACTIVATION_TTL = DAY_IN_SECONDS;

/** Serialize signup/resend/activation across workers, including non-persistent caches. */
function extrachill_users_signup_lock( string $email, callable $callback ) {
	global $wpdb;
	$name = 'ec-signup-' . substr( hash( 'sha256', strtolower( $email ) ), 0, 48 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) {
		return new WP_Error( 'signup_busy', __( 'Please try again shortly.', 'extrachill-users' ), array( 'status' => 503 ) );
	}
	try {
		return $callback();
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}

/** Create a pending signup, or resend the existing immutable signup after a cooldown. */
function extrachill_users_create_pending_signup( array $data, array $context = array() ) {
	global $wpdb;
	if ( ! is_multisite() || ! is_email( $data['email'] ?? '' ) || empty( $data['password'] ) ) {
		return new WP_Error( 'invalid_signup', __( 'Registration is unavailable.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	return extrachill_users_signup_lock( $data['email'], static function () use ( $wpdb, $data, $context ) {
		if ( email_exists( $data['email'] ) ) {
			return new WP_Error( 'registration_failed', __( 'Please sign in or reset your password.', 'extrachill-users' ), array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE user_email = %s AND active = 0 ORDER BY signup_id DESC LIMIT 1", $data['email'] ) );
		if ( $row ) {
			$meta = maybe_unserialize( $row->meta );
			if ( ! empty( $meta['ec_activation'] ) && (int) $meta['ec_activation']['expires_at'] > time() ) {
				// Never replace credentials, consent or invitations on a pending signup.
				if ( (int) ( $meta['ec_activation']['sent_at'] ?? 0 ) + MINUTE_IN_SECONDS <= time() ) {
					$meta['ec_activation']['sent_at'] = time();
					$wpdb->update( $wpdb->signups, array( 'meta' => maybe_serialize( $meta ) ), array( 'signup_id' => $row->signup_id ) );
					wpmu_signup_user_notification( $row->user_login, $row->user_email, $row->activation_key, $meta );
				}
				return array(
					'status'  => 'pending_activation',
					'message' => __( 'Check your email to activate your account. To resend, submit this form again after one minute.', 'extrachill-users' ),
				);
			}
			$wpdb->delete( $wpdb->signups, array( 'signup_id' => $row->signup_id ) );
		}
		if ( ! ec_get_blog_id( 'community' ) ) {
			return new WP_Error( 'community_unavailable', __( 'Registration is temporarily unavailable.', 'extrachill-users' ), array( 'status' => 503 ) );
		}
		$hash = wp_hash_password( $data['password'] );
		unset( $data['password'] );
		$meta = array(
			'ec_activation' => array(
				'password_hash' => $hash,
				'expires_at'    => time() + EXTRACHILL_USERS_ACTIVATION_TTL,
				'sent_at'       => time(),
				'data'          => $data,
				'context'       => $context,
			),
		);
		wpmu_signup_user( $data['username'], $data['email'], $meta );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT signup_id FROM $wpdb->signups WHERE user_email = %s AND active = 0", $data['email'] ) );
		if ( ! $exists ) {
			return new WP_Error( 'signup_failed', __( 'Registration failed. Please try again.', 'extrachill-users' ), array( 'status' => 503 ) );
		}
		return array(
			'status'  => 'pending_activation',
			'message' => __( 'Check your email to activate your account. To resend, submit this form again after one minute.', 'extrachill-users' ),
		);
	} );
}

/** Replace core notification content with a branded confirmation URL. */
function extrachill_users_activation_email( $message, $login, $email, $key, $meta ) {
	if ( empty( $meta['ec_activation'] ) ) {
		return $message;
	}
	$url = add_query_arg( 'ec_activate', $key, network_home_url( '/login/', 'https' ) );
	// Core applies sprintf to this string; escape percent-encoded URL bytes.
	/* translators: %s: branded activation URL. */
	return str_replace( '%', '%%', sprintf( __( "Activate your Extra Chill account:\n\n%s\n\nThis link expires in 24 hours. If you did not register, ignore this email.", 'extrachill-users' ), $url ) );
}
add_filter( 'wpmu_signup_user_notification_email', 'extrachill_users_activation_email', 10, 5 );

/** Activate once under the same email lock used by signup/resend. No sessions are issued. */
function extrachill_users_activate_password_signup( string $key ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE activation_key = %s", $key ) );
	if ( ! $row ) {
		return new WP_Error( 'invalid_key', __( 'This activation link is invalid.', 'extrachill-users' ) );
	}
	return extrachill_users_signup_lock( $row->user_email, static function () use ( $wpdb, $key ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE activation_key = %s", $key ) );
		if ( ! $row || $row->active ) {
			return new WP_Error( 'already_active', __( 'This link has already been used. Please sign in.', 'extrachill-users' ) );
		}
		$meta       = maybe_unserialize( $row->meta );
		$activation = $meta['ec_activation'] ?? array();
		if ( empty( $activation['password_hash'] ) || (int) $activation['expires_at'] <= time() ) {
			return new WP_Error( 'expired_key', __( 'This link has expired. Register again to receive a new link.', 'extrachill-users' ) );
		}
		if ( ! ec_get_blog_id( 'community' ) ) {
			return new WP_Error( 'community_unavailable', __( 'Activation is temporarily unavailable. Please try again.', 'extrachill-users' ) );
		}
		if ( email_exists( $row->user_email ) || username_exists( $row->user_login ) ) {
			return new WP_Error( 'registration_conflict', __( 'An account already exists. Please sign in or reset your password.', 'extrachill-users' ) );
		}
		// Core's welcome email would contain its discarded generated password.
		remove_action( 'wpmu_activate_user', 'wpmu_welcome_user_notification', 10 );
		try {
			$result = wpmu_activate_signup( $key );
		} finally {
			add_action( 'wpmu_activate_user', 'wpmu_welcome_user_notification', 10, 3 );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Do not retain password hashes, invitation secrets or device context after use.
		$wpdb->update( $wpdb->signups, array( 'meta' => maybe_serialize( array() ) ), array( 'signup_id' => $row->signup_id ) );
		return array( 'status' => 'activated' );
	} );
}

/** Provision the core-created network user using the canonical registration effects. */
function extrachill_users_complete_password_activation( $user_id, $password, $meta ) {
	global $wpdb;
	if ( empty( $meta['ec_activation'] ) ) {
		return;
	}
	$activation = $meta['ec_activation'];
	$data       = $activation['data'];
	$context    = $activation['context'];
	// Set the already-hashed intended password; never pass it through wp_set_password().
	$wpdb->update( $wpdb->users, array( 'user_pass' => $activation['password_hash'] ), array( 'ID' => $user_id ) );
	clean_user_cache( $user_id );
	$community = ec_get_blog_id( 'community' );
	add_user_to_blog( $community, $user_id, get_blog_option( $community, 'default_role', 'subscriber' ) );
	switch_to_blog( $community );
	try {
		extrachill_users_provision_registered_user( (int) $user_id, $data );
	} finally {
		restore_current_blog();
	}
	update_user_meta( $user_id, 'registration_timestamp', current_time( 'mysql' ) );
	extrachill_users_record_registration_newsletter_consent( $user_id, $data['email'], ! empty( $context['newsletter_consent'] ), $data['registration_source'] ?? 'web', $data['registration_method'] ?? 'standard', $data['registration_page'] ?? '' );
	if ( ! empty( $data['from_join'] ) && ! empty( $context['success_redirect_url'] ) ) {
		update_user_meta( $user_id, 'onboarding_redirect_url', $context['success_redirect_url'] );
	}
	if ( ! empty( $context['invite_token'] ) || ! empty( $context['invite_artist_id'] ) ) {
		ec_users_request_artist_invitation( $data['email'], $context['invite_token'], $context['invite_artist_id'], $user_id );
	}
}
add_action( 'wpmu_activate_user', 'extrachill_users_complete_password_activation', 1, 3 );

/** Prevent core's unguarded activation endpoint from bypassing expiry and locking. */
function extrachill_users_redirect_core_activation() {
	if ( 'wp-activate.php' !== basename( $_SERVER['SCRIPT_NAME'] ?? '' ) ) {
		return;
	}
	wp_safe_redirect( network_home_url( '/login/', 'https' ) );
	exit;
}
add_action( 'init', 'extrachill_users_redirect_core_activation', 0 );

/** Render a branded confirmation page; GET never consumes a key (mail scanners). */
function extrachill_users_activation_page() {
	if ( ! isset( $_GET['ec_activate'] ) ) {
		return;
	}
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	$key    = sanitize_text_field( wp_unslash( $_GET['ec_activate'] ) );
	$result = null;
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'ec_activate_' . $key ) ) {
			$result = new WP_Error( 'invalid_request', __( 'Please reload and try again.', 'extrachill-users' ) );
		} else {
			$result = extrachill_users_activate_password_signup( $key );
		}
	}
	get_header();
	echo '<main class="container"><h1>' . esc_html__( 'Activate your account', 'extrachill-users' ) . '</h1>';
	if ( null === $result ) {
		echo '<form method="post"><p>' . esc_html__( 'Confirm your email address to activate your Extra Chill account.', 'extrachill-users' ) . '</p>';
		wp_nonce_field( 'ec_activate_' . $key );
		echo '<button class="button-1" type="submit">' . esc_html__( 'Activate account', 'extrachill-users' ) . '</button></form>';
	} else {
		echo '<div class="notice"><p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'Your account is activated. Sign in with your chosen password.', 'extrachill-users' ) ) . '</p></div>';
		echo '<a class="button-1" href="' . esc_url( extrachill_users_get_registration_url() ) . '">' . esc_html__( 'Sign in or register', 'extrachill-users' ) . '</a>';
	}
	echo '</main>';
	get_footer();
	exit;
}
add_action( 'template_redirect', 'extrachill_users_activation_page', 0 );
