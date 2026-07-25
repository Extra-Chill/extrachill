<?php
/**
 * Plugin Name: Extra Chill Auth Fuzz Fixture
 * Description: Test-only external-service seams for the disposable auth campaign.
 * Network: true
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );

if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
	$_SERVER['REMOTE_ADDR'] = '127.0.0.248';
}

function extrachill_auth_fuzz_registration_admitter() {
	$count = (int) get_site_option( 'extrachill_auth_fuzz_registration_attempts', 0 ) + 1;
	update_site_option( 'extrachill_auth_fuzz_registration_attempts', $count );

	return $count > EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT
		? extrachill_users_registration_rate_limit_error( time() + EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW )
		: true;
}

add_filter(
	'extrachill_users_registration_admitter',
	static function () {
		return 'extrachill_auth_fuzz_registration_admitter';
	}
);

function extrachill_auth_fuzz_login_rate_limit_store( $operation, $key ) {
	$state = get_site_option( 'extrachill_auth_fuzz_login_attempts', array() );
	$count = (int) ( $state[ $key ] ?? 0 );

	if ( 'get' === $operation ) {
		return $count;
	}
	if ( 'increment' === $operation ) {
		$state[ $key ] = ++$count;
		update_site_option( 'extrachill_auth_fuzz_login_attempts', $state );
		return $count;
	}
	if ( 'clear' === $operation ) {
		unset( $state[ $key ] );
		update_site_option( 'extrachill_auth_fuzz_login_attempts', $state );
		return 0;
	}

	return new WP_Error( 'ec_login_limiter_unavailable', 'Unsupported auth fuzz limiter operation.' );
}

add_filter(
	'extrachill_users_login_rate_limit_store',
	static function () {
		return 'extrachill_auth_fuzz_login_rate_limit_store';
	}
);
add_filter(
	'ec_site_url_override',
	static function ( $url, $key, $blog_id ) {
		$site_url = get_site_url( (int) $blog_id );
		return is_string( $site_url ) && '' !== $site_url ? untrailingslashit( $site_url ) : $url;
	},
	10,
	3
);

add_action(
	'set_logged_in_cookie',
	static function ( $cookie, $expire, $expiration, $user_id ) {
		update_site_option(
			'extrachill_auth_fuzz_emitted_cookie',
			array(
				'user_id' => (int) $user_id,
				'hash'    => hash( 'sha256', (string) $cookie ),
				'valid'   => (int) wp_validate_auth_cookie( (string) $cookie, 'logged_in' ),
			)
		);
	},
	PHP_INT_MAX,
	4
);

add_action(
	'template_redirect',
	static function () {
		if ( ! isset( $_GET['auth_fuzz_observe'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['auth_fuzz_observe'] ) );
		$observations = get_site_option( 'extrachill_auth_fuzz_browser_observations', array() );
		$cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) $_COOKIE[ LOGGED_IN_COOKIE ] : '';
		$observations[ $key ] = array(
			'user_id'     => get_current_user_id(),
			'cookie_hash' => '' !== $cookie ? hash( 'sha256', $cookie ) : '',
		);
		update_site_option( 'extrachill_auth_fuzz_browser_observations', $observations );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		remove_action( 'extrachill_new_user_registered', 'extrachill_notify_admin_new_user', 10 );
		remove_action( 'wp_abilities_api_init', 'extrachill_users_register_welcome_email_ability' );
	},
	PHP_INT_MAX
);

add_action(
	'init',
	static function () {
		if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			define( 'WP_ENVIRONMENT_TYPE', 'local' );
		}
	}
);
