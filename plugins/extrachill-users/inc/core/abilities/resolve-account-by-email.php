<?php
/**
 * Resolve Account By Email Ability
 *
 * Canonical "resolve or create an account from just an email" primitive.
 * Guest flows (event RSVP, event submission, venue invitations, artist
 * onboarding, newsletter) share one unclaimed-account + claim-link path
 * instead of each consumer hand-rolling its own copy.
 *
 * - Existing account by email is reused; never duplicated.
 * - Otherwise a locked subscriber account is created through
 *   `extrachill/create-user` with `unclaimed: true` (ec_unclaimed=1).
 * - A claim (set-password) URL is only minted for unclaimed accounts. A
 *   claimed account never gets a reset key just because someone typed its
 *   email into a public form.
 * - Minting is throttled per user: generating a reset key invalidates the
 *   previous one, so rapid repeat calls must not keep rotating a link the
 *   user may already be holding.
 *
 * Not exposed over the generic Abilities REST runner. Public write
 * boundaries (Turnstile, rate limits) live in dedicated extrachill-api routes.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_resolve_account_by_email_ability' );

/**
 * User meta key recording when a claim key was last minted.
 */
const EXTRACHILL_USERS_CLAIM_MINTED_AT_META = '_ec_account_claim_minted_at';

/**
 * Register the resolve-account-by-email ability.
 */
function extrachill_users_register_resolve_account_by_email_ability() {
	wp_register_ability(
		'extrachill/resolve-account-by-email',
		array(
			'label'               => __( 'Resolve Account By Email', 'extrachill-users' ),
			'description'         => __( 'Reuse the account for an email or create an unclaimed subscriber account, and return a throttled claim (set-password) URL when the account is unclaimed.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'email'               => array(
						'type'        => 'string',
						'description' => __( 'Email address to resolve.', 'extrachill-users' ),
					),
					'registration_source' => array(
						'type'        => 'string',
						'description' => __( 'Source label for newly created accounts (e.g. event_rsvp, event_submission, newsletter).', 'extrachill-users' ),
					),
					'referrer'            => array(
						'type'        => 'string',
						'description' => __( 'Optional external referrer URL passed through to create-user.', 'extrachill-users' ),
					),
					'utm'                 => array(
						'type'        => 'object',
						'description' => __( 'Optional UTM parameters passed through to create-user.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'email', 'registration_source' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'         => array( 'type' => 'integer' ),
					'created'         => array( 'type' => 'boolean' ),
					'unclaimed'       => array( 'type' => 'boolean' ),
					'claim_url'       => array( 'type' => 'string' ),
					'claim_throttled' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_resolve_account_by_email',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Whether a user account is unclaimed (created on the user's behalf and not
 * yet activated by setting a password).
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_users_is_account_unclaimed( $user_id ) {
	return '1' === (string) get_user_meta( (int) $user_id, 'ec_unclaimed', true );
}

/**
 * Build a one-time claim (set-password) URL for a user.
 *
 * Mints a fresh password-reset key and points it at the community
 * /reset-password/ page, the same handoff the plugin's own reset and team
 * welcome emails use. Minting invalidates any previously issued key.
 *
 * Callers that only hold an email should use the
 * `extrachill/resolve-account-by-email` ability instead, which adds the
 * unclaimed-only rule and per-user throttling.
 *
 * @param WP_User $user User object.
 * @return string Claim URL, or empty string when key generation fails.
 */
function ec_users_build_account_claim_url( WP_User $user ) {
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return '';
	}

	update_user_meta( $user->ID, EXTRACHILL_USERS_CLAIM_MINTED_AT_META, time() );

	$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : network_site_url();

	return add_query_arg(
		array(
			'action' => 'reset',
			'key'    => $key,
			'login'  => rawurlencode( $user->user_login ),
		),
		trailingslashit( $base ) . 'reset-password/'
	);
}

/**
 * Seconds a minted claim key is protected from rotation by repeat calls.
 *
 * @return int
 */
function ec_users_account_claim_throttle_window() {
	/**
	 * Filters the claim-key rotation throttle window in seconds.
	 *
	 * @param int $window Default 15 minutes.
	 */
	return max( 0, (int) apply_filters( 'extrachill_users_account_claim_throttle_window', 15 * MINUTE_IN_SECONDS ) );
}

/**
 * Whether a claim key was minted for the user inside the throttle window.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_users_is_account_claim_throttled( $user_id ) {
	$minted_at = (int) get_user_meta( (int) $user_id, EXTRACHILL_USERS_CLAIM_MINTED_AT_META, true );
	$window    = ec_users_account_claim_throttle_window();

	return $minted_at > 0 && $window > 0 && ( time() - $minted_at ) < $window;
}

/**
 * Resolve or create an account for an email.
 *
 * @param array $input {email, registration_source, referrer?, utm?}.
 * @return array|WP_Error {user_id, created, unclaimed, claim_url, claim_throttled}.
 */
function extrachill_users_ability_resolve_account_by_email( $input ) {
	$email  = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
	$source = isset( $input['registration_source'] ) ? sanitize_key( (string) $input['registration_source'] ) : '';

	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	if ( '' === $source ) {
		return new WP_Error( 'missing_registration_source', __( 'A registration source is required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$created = false;
	$user    = get_user_by( 'email', $email );

	if ( ! $user instanceof WP_User ) {
		$create = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/create-user' ) : null;
		if ( ! $create ) {
			return new WP_Error( 'create_user_unavailable', __( 'Account creation is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
		}

		$create_input = array(
			'email'               => $email,
			'password'            => wp_generate_password( 24 ),
			'username'            => ec_generate_username_from_email( $email ),
			'role'                => 'subscriber',
			'unclaimed'           => true,
			'registration_source' => $source,
			'registration_method' => 'email_only',
		);

		if ( ! empty( $input['referrer'] ) ) {
			$create_input['referrer'] = (string) $input['referrer'];
		}

		if ( ! empty( $input['utm'] ) && is_array( $input['utm'] ) ) {
			$create_input['utm'] = $input['utm'];
		}

		$result = $create->execute( $create_input );

		if ( is_wp_error( $result ) ) {
			// Lost a race with a concurrent request for the same email: reuse it.
			$user = get_user_by( 'email', $email );
			if ( ! $user instanceof WP_User ) {
				return $result;
			}
		} else {
			$user    = get_userdata( (int) $result );
			$created = true;
		}

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'account_resolution_failed', __( 'The account could not be loaded.', 'extrachill-users' ), array( 'status' => 500 ) );
		}
	}

	$unclaimed = ec_users_is_account_unclaimed( $user->ID );
	$claim_url = '';
	$throttled = false;

	if ( $unclaimed ) {
		if ( ec_users_is_account_claim_throttled( $user->ID ) ) {
			$throttled = true;
		} else {
			$claim_url = ec_users_build_account_claim_url( $user );
		}
	}

	return array(
		'user_id'         => (int) $user->ID,
		'created'         => $created,
		'unclaimed'       => $unclaimed,
		'claim_url'       => $claim_url,
		'claim_throttled' => $throttled,
	);
}
