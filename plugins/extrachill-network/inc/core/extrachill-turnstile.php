<?php
/**
 * Cloudflare Turnstile Integration
 *
 * Network-wide captcha configuration accessible from all sites.
 *
 * @package ExtraChill\Network
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

function ec_get_turnstile_site_key() {
	return get_site_option( 'ec_turnstile_site_key', '' );
}

function ec_get_turnstile_secret_key() {
	return get_site_option( 'ec_turnstile_secret_key', '' );
}

function ec_update_turnstile_site_key( $site_key ) {
	return update_site_option( 'ec_turnstile_site_key', sanitize_text_field( $site_key ) );
}

function ec_update_turnstile_secret_key( $secret_key ) {
	return update_site_option( 'ec_turnstile_secret_key', sanitize_text_field( $secret_key ) );
}

/**
 * Log a Turnstile diagnostic message to the PHP error log.
 *
 * Single choke point for all Turnstile logging so the raw error_log() usage
 * stays documented in one place.
 *
 * @param string $message Log message (already prefixed with the component name).
 */
function ec_turnstile_log( $message ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate server-side diagnostics for the captcha verification path.
	error_log( $message );
}

/**
 * Verify Cloudflare Turnstile response via API with comprehensive error logging.
 *
 * Filterable via 'extrachill_bypass_turnstile_verification' for dev environments.
 */
function ec_verify_turnstile_response( $response ) {
	$is_local_environment = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	$bypass               = $is_local_environment || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );
	if ( true === $bypass ) {
		return true;
	}

	$response = sanitize_text_field( wp_unslash( $response ) );

	if ( empty( $response ) ) {
		ec_turnstile_log( 'ExtraChill Turnstile: Empty response token received' );
		return false;
	}

	$secret_key = ec_get_turnstile_secret_key();
	if ( empty( $secret_key ) ) {
		ec_turnstile_log( 'ExtraChill Turnstile: Secret key not configured in network settings' );
		return false;
	}

	$verification_url  = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	$verification_data = array(
		'secret'   => $secret_key,
		'response' => $response,
		'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
	);

	$http_response = wp_remote_post(
		$verification_url,
		array(
			'body'    => $verification_data,
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $http_response ) ) {
		ec_turnstile_log( 'ExtraChill Turnstile Verification Error: ' . $http_response->get_error_message() );
		return false;
	}

	$response_code = wp_remote_retrieve_response_code( $http_response );
	if ( 200 !== $response_code ) {
		ec_turnstile_log( 'ExtraChill Turnstile Verification HTTP Error: Code ' . $response_code . ' Body: ' . wp_remote_retrieve_body( $http_response ) );
		return false;
	}

	$response_body = wp_remote_retrieve_body( $http_response );
	$result        = json_decode( $response_body, true );

	if ( null === $result ) {
		ec_turnstile_log( 'ExtraChill Turnstile Verification JSON Decode Error: Body - ' . $response_body );
		return false;
	}

	if ( isset( $result['success'] ) && true === $result['success'] ) {
		ec_turnstile_log( 'ExtraChill Turnstile: Verification successful' );
		return true;
	}

	if ( isset( $result['error-codes'] ) && is_array( $result['error-codes'] ) ) {
		ec_turnstile_log( 'ExtraChill Turnstile Verification Failed: ' . implode( ', ', $result['error-codes'] ) );
	} else {
		ec_turnstile_log( 'ExtraChill Turnstile Verification Unexpected Response: ' . $response_body );
	}

	return false;
}

function ec_is_turnstile_configured() {
	$site_key   = ec_get_turnstile_site_key();
	$secret_key = ec_get_turnstile_secret_key();

	return ! empty( $site_key ) && ! empty( $secret_key );
}

function ec_render_turnstile_widget( $args = array() ) {
	if ( ! ec_is_turnstile_configured() ) {
		return '';
	}

	$site_key = ec_get_turnstile_site_key();
	$defaults = array(
		'data-sitekey'    => $site_key,
		'data-size'       => 'normal',
		'data-theme'      => 'auto',
		'data-appearance' => 'interaction-only',
		'class'           => 'cf-turnstile',
	);

	$args = wp_parse_args( $args, $defaults );

	$attributes = '';
	foreach ( $args as $key => $value ) {
		if ( 'class' === $key ) {
			$attributes .= sprintf( ' class="%s"', esc_attr( $value ) );
		} else {
			$attributes .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
	}

	return sprintf( '<div%s></div>', $attributes );
}

/**
 * Enqueue the Turnstile client runtime in EXPLICIT render mode.
 *
 * Loads two scripts, in footer, dependency-free:
 *
 * 1. `ec-turnstile-boot` — a tiny site-wide bootstrap shipped from this plugin
 *    (assets/js/turnstile-boot.js). It defines `window.ecTurnstileBoot`, which
 *    renders EACH `.cf-turnstile` widget in its own `turnstile.render()` call
 *    wrapped in try/catch. One bad widget can only break itself; siblings still
 *    render. This must be registered before api.js so the `onload` target
 *    exists when Cloudflare fires it.
 * 2. `cloudflare-turnstile` (the api.js handle, default $handle) — loaded with
 *    `?render=explicit&onload=ecTurnstileBoot` so Cloudflare does NOT auto-scan
 *    the DOM in a single batch (the implicit-render failure class that let one
 *    dangling `data-callback` abort every widget on the page). It instead calls
 *    our boot once ready.
 *
 * The boot script is a third-party-widget bootstrap, not Gutenberg block logic,
 * so it is enqueued the canonical WP way and is exempt from the headless-React
 * block rule. It contains no fetch/AJAX.
 *
 * @param string $handle Script handle for the Cloudflare api.js registration.
 */
function ec_enqueue_turnstile_script( $handle = 'cloudflare-turnstile' ) {
	if ( ! ec_is_turnstile_configured() ) {
		return;
	}

	$boot_handle = 'ec-turnstile-boot';
	$boot_src    = EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/js/turnstile-boot.js';
	$boot_path   = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/js/turnstile-boot.js';
	$boot_ver    = file_exists( $boot_path ) ? (string) filemtime( $boot_path ) : EXTRACHILL_NETWORK_VERSION;

	wp_enqueue_script( $boot_handle, $boot_src, array(), $boot_ver, true );

	// api.js in explicit mode; calls window.ecTurnstileBoot once loaded. Listed
	// after the boot handle as a dependency so the boot definition is present
	// before Cloudflare fires onload.
	wp_enqueue_script(
		$handle,
		'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=ecTurnstileBoot',
		array( $boot_handle ),
		null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party Cloudflare API endpoint is intentionally unversioned.
		true
	);
}

/**
 * Verify a Turnstile token from a REST request or raw value.
 *
 * Reusable check for any form-handling code that has either a WP_REST_Request
 * (reads the 'turnstile_response' parameter automatically) or a raw token
 * string (passed directly).
 *
 * Honors the same bypass conditions as ec_verify_turnstile_response()
 * (local environment + 'extrachill_bypass_turnstile_verification' filter).
 *
 * Fires the stable 'extrachill_turnstile_rejected' action once before returning
 * a turnstile_missing_token or turnstile_failed error. Receives the error code
 * (string) and the original WP_REST_Request|string $request_or_token unchanged.
 * Does not fire on bypass, success, or the turnstile_missing configuration error.
 *
 * @param WP_REST_Request|string $request_or_token Request object or raw token.
 * @return true|WP_Error True on success, WP_Error on missing/invalid token.
 */
function ec_turnstile_check_request( $request_or_token ) {
	$is_local_environment = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	$bypass               = $is_local_environment || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );
	if ( true === $bypass ) {
		return true;
	}

	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error(
			'turnstile_missing',
			__( 'Security verification unavailable.', 'extrachill-network' ),
			array( 'status' => 500 )
		);
	}

	if ( $request_or_token instanceof WP_REST_Request ) {
		$token = (string) $request_or_token->get_param( 'turnstile_response' );
	} else {
		$token = (string) $request_or_token;
	}

	if ( '' === $token ) {
		do_action( 'extrachill_turnstile_rejected', 'turnstile_missing_token', $request_or_token );
		return new WP_Error(
			'turnstile_missing_token',
			__( 'Security verification required.', 'extrachill-network' ),
			array( 'status' => 403 )
		);
	}

	if ( ! ec_verify_turnstile_response( $token ) ) {
		do_action( 'extrachill_turnstile_rejected', 'turnstile_failed', $request_or_token );
		return new WP_Error(
			'turnstile_failed',
			__( 'Security verification failed. Please try again.', 'extrachill-network' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Factory: build a REST permission_callback that requires a valid Turnstile token.
 *
 * Use in register_rest_route():
 *
 *     'permission_callback' => ec_turnstile_permission_callback(),
 *
 * Or, to compose with an additional capability check:
 *
 *     'permission_callback' => ec_turnstile_permission_callback( function( $request ) {
 *         return current_user_can( 'edit_posts' );
 *     } ),
 *
 * The Turnstile check runs first; if it passes, the optional secondary
 * callback decides authorization. Both must return truthy for the request
 * to be authorized; a WP_Error from either short-circuits with that error.
 *
 * Note: route registrations must declare 'turnstile_response' in 'args' so
 * the token is sanitized through the standard REST args pipeline.
 *
 * @param callable|null $also Optional secondary permission callback.
 * @return callable Permission callback suitable for register_rest_route().
 */
function ec_turnstile_permission_callback( $also = null ) {
	return function ( WP_REST_Request $request ) use ( $also ) {
		$check = ec_turnstile_check_request( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( is_callable( $also ) ) {
			return $also( $request );
		}

		return true;
	};
}
