<?php
/**
 * Sweatpants Token Ability
 *
 * Mints a short-lived signed bearer token that browser-side code (the Studio
 * Transcribe tab) can present directly to `https://sweatpants.chubes.net` for
 * scoped operations like uploading audio and creating jobs. The signing key
 * is stored on extrachill.com as a network option and never leaves the
 * server.
 *
 * Flow:
 *
 *   browser (logged-in Studio user)
 *      │
 *      │ POST /wp-json/wp/v2/abilities/extrachill/sweatpants-token/execute
 *      │     { scope: "uploads:write jobs:write jobs:read", ttl: 900 }
 *      ▼
 *   this ability
 *      ├─ ec_is_team_member() gate
 *      ├─ scope allowlist (prevents privilege escalation)
 *      ├─ wp_native_auth_sign_external_token({ iss, sub, scope, exp, jti }, $secret)
 *      └─ returns { token, expires_at }
 *      │
 *      │ Authorization: Bearer <token>
 *      ▼
 *   sweatpants.chubes.net
 *      └─ HMAC-validates against the same shared secret + checks scope
 *
 * The signer primitive lives in wp-native-auth. This ability owns the
 * sweatpants-specific knowledge: which option holds the secret, what scopes
 * are allowed, what issuer string to use.
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 * @since      0.10.4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scopes a Studio user is allowed to mint tokens for.
 *
 * Strict allowlist — prevents a Studio user from minting a token with
 * `modules:admin` or other elevated scopes even though the master signing
 * secret could technically sign any payload.
 *
 * @since 0.10.4
 */
const EC_STUDIO_SWEATPANTS_TOKEN_ALLOWED_SCOPES = array(
	'read',
	'jobs:read',
	'jobs:write',
	'uploads:read',
	'uploads:write',
);

/**
 * Default TTL for minted tokens, in seconds (15 minutes).
 *
 * Long enough for a typical browser workflow (upload + submit + poll a few
 * times); short enough that a leaked token expires before it does much
 * damage.
 *
 * @since 0.10.4
 */
const EC_STUDIO_SWEATPANTS_TOKEN_DEFAULT_TTL = 900;

/**
 * Maximum TTL accepted from the input. Hard cap so callers can't mint
 * 24-hour tokens. 1 hour is generous for any reasonable Studio session.
 *
 * @since 0.10.4
 */
const EC_STUDIO_SWEATPANTS_TOKEN_MAX_TTL = 3600;

/**
 * Issuer identifier embedded in the payload's `iss` claim.
 *
 * Opaque string for audit logging on the sweatpants side. Sweatpants does
 * not validate this — it's metadata.
 *
 * @since 0.10.4
 */
const EC_STUDIO_SWEATPANTS_TOKEN_ISSUER = 'extrachill-studio';

/**
 * Register the sweatpants-token ability.
 *
 * @since 0.10.4
 *
 * @return void
 */
function ec_studio_register_sweatpants_token_ability(): void {
	if ( ! class_exists( 'WP_Ability' ) ) {
		return;
	}

	$register = function () {
		wp_register_ability(
			'extrachill/sweatpants-token',
			array(
				'label'               => __( 'Mint Sweatpants Bearer Token', 'extrachill-studio' ),
				'description'         => __( 'Issue a short-lived HMAC-signed bearer token for direct browser access to the sweatpants compute worker.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'scope' => array(
							'type'        => 'string',
							'description' => __( 'Space-separated capability strings. Must be a subset of the allowlist.', 'extrachill-studio' ),
						),
						'ttl'   => array(
							'type'        => 'integer',
							'description' => __( 'Token lifetime in seconds. Defaults to 900, capped at 3600.', 'extrachill-studio' ),
							'minimum'     => 1,
							'maximum'     => EC_STUDIO_SWEATPANTS_TOKEN_MAX_TTL,
						),
					),
					'required'   => array( 'scope' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'token'      => array( 'type' => 'string' ),
						'expires_at' => array( 'type' => 'integer' ),
						'scope'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'ec_studio_execute_sweatpants_token',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' )
						|| ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	};

	if ( did_action( 'wp_abilities_api_init' ) ) {
		$register();
		return;
	}

	add_action( 'wp_abilities_api_init', $register );
}
add_action( 'init', 'ec_studio_register_sweatpants_token_ability', 20 );

/**
 * Execute the sweatpants-token ability.
 *
 * @since 0.10.4
 *
 * @param array $input Validated input matching the ability's input schema.
 * @return array|\WP_Error { token, expires_at, scope } on success, WP_Error otherwise.
 */
function ec_studio_execute_sweatpants_token( array $input ): array|\WP_Error {
	if ( ! function_exists( 'wp_native_auth_sign_external_token' ) ) {
		return new \WP_Error(
			'signer_unavailable',
			__( 'wp-native-auth is required to mint sweatpants tokens.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new \WP_Error(
			'not_logged_in',
			__( 'You must be logged in to mint a sweatpants token.', 'extrachill-studio' ),
			array( 'status' => 401 )
		);
	}

	$secret = get_site_option( 'sweatpants_signed_token_secret', '' );
	if ( ! is_string( $secret ) || '' === $secret ) {
		return new \WP_Error(
			'secret_not_configured',
			__( 'Sweatpants signing secret is not configured on this network.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$requested_scope = isset( $input['scope'] ) ? trim( (string) $input['scope'] ) : '';
	if ( '' === $requested_scope ) {
		return new \WP_Error(
			'scope_required',
			__( 'A non-empty scope string is required.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$requested_scopes = preg_split( '/\s+/', $requested_scope );
	$invalid_scopes   = array_diff( $requested_scopes, EC_STUDIO_SWEATPANTS_TOKEN_ALLOWED_SCOPES );
	if ( ! empty( $invalid_scopes ) ) {
		return new \WP_Error(
			'scope_not_allowed',
			sprintf(
				/* translators: %s: comma-separated list of disallowed scopes */
				__( 'Scopes not allowed for Studio users: %s', 'extrachill-studio' ),
				implode( ', ', $invalid_scopes )
			),
			array( 'status' => 403 )
		);
	}

	$ttl = isset( $input['ttl'] ) ? (int) $input['ttl'] : EC_STUDIO_SWEATPANTS_TOKEN_DEFAULT_TTL;
	if ( $ttl <= 0 ) {
		$ttl = EC_STUDIO_SWEATPANTS_TOKEN_DEFAULT_TTL;
	}
	if ( $ttl > EC_STUDIO_SWEATPANTS_TOKEN_MAX_TTL ) {
		$ttl = EC_STUDIO_SWEATPANTS_TOKEN_MAX_TTL;
	}

	$expires_at = time() + $ttl;

	$payload = array(
		'iss'   => EC_STUDIO_SWEATPANTS_TOKEN_ISSUER,
		'sub'   => $user_id,
		'scope' => implode( ' ', $requested_scopes ),
		'exp'   => $expires_at,
		'jti'   => wp_generate_uuid4(),
	);

	try {
		$token = wp_native_auth_sign_external_token( $payload, $secret );
	} catch ( \Throwable $e ) {
		return new \WP_Error(
			'sign_failed',
			$e->getMessage(),
			array( 'status' => 500 )
		);
	}

	return array(
		'token'      => $token,
		'expires_at' => $expires_at,
		'scope'      => $payload['scope'],
	);
}
