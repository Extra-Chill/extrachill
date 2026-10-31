<?php
/**
 * Capability gate + feature-rollout primitives.
 *
 * The single source of truth for "who can do what" (capability) and
 * "is this feature live for this user's tier yet" (rollout). These two
 * concerns are orthogonal and compose; they never collapse into one check:
 *
 *     if ( ec_feature_available( 'shop' ) && ec_user_can( 'manage_shop', [ 'artist_id' => $id ] ) ) { ... }
 *
 * Capability is answered by {@see ec_user_can()}. Rollout tier is answered by
 * {@see ec_feature_available()} / {@see ec_feature_tier()}.
 *
 * See Extra-Chill/extrachill-users#60 for the locked design spec.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rollout ladder, lowest-to-highest audience.
 *
 * admin  → only super-admins / manage_options users.
 * team   → admin OR extra_chill_team role (ec_is_team_member()).
 * public → everyone who passes the feature's own base capability gate.
 *
 * Position in this array is the ladder order used for clamping.
 *
 * @return string[]
 */
function ec_feature_tier_ladder() {
	return array( 'admin', 'team', 'public' );
}

/**
 * Feature ceiling registry: feature => the MAX tier the code is ready to expose.
 *
 * The ceiling is a code-owned constant changed only via commit + deploy + review.
 * The live tier (network option) can never exceed this — see ec_feature_tier().
 *
 * Filterable via `ec_feature_ceilings` so other plugins can register gated
 * features without editing this file.
 *
 * @return array<string,string> feature slug => ceiling tier.
 */
function ec_feature_ceilings() {
	$ceilings = array(
		// Shop manager: admin-only until the system is ready for the team.
		'shop'          => 'admin',
		// Shared brand social accounts: admin-only until a review workflow
		// exists for the team (the official accounts post immediately live).
		'brand_socials' => 'admin',
	);

	/**
	 * Filter the feature ceiling registry.
	 *
	 * Each entry maps a feature slug to the maximum rollout tier the code is
	 * ready to expose (`admin` | `team` | `public`). The live tier stored in
	 * the network option is always clamped to this ceiling.
	 *
	 * @param array<string,string> $ceilings feature slug => ceiling tier.
	 */
	$ceilings = apply_filters( 'ec_feature_ceilings', $ceilings );

	return is_array( $ceilings ) ? $ceilings : array();
}

/**
 * Get the code ceiling tier for a feature.
 *
 * Unknown features default to `admin` (the most restrictive rung) so a
 * misconfigured or unregistered feature can never leak past admins.
 *
 * @param string $feature Feature slug.
 * @return string One of `admin` | `team` | `public`.
 */
function ec_feature_ceiling( $feature ) {
	$ceilings = ec_feature_ceilings();
	$ceiling  = isset( $ceilings[ $feature ] ) ? $ceilings[ $feature ] : 'admin';

	return ec_feature_tier_is_valid( $ceiling ) ? $ceiling : 'admin';
}

/**
 * Whether a tier string is a valid rung on the ladder.
 *
 * @param string $tier Tier string.
 * @return bool
 */
function ec_feature_tier_is_valid( $tier ) {
	return in_array( $tier, ec_feature_tier_ladder(), true );
}

/**
 * Compare two ladder positions.
 *
 * Returns the LOWER (more restrictive) of the two tiers — i.e. the clamp.
 * Invalid inputs fall back to the most restrictive rung (`admin`).
 *
 * @param string $a First tier.
 * @param string $b Second tier.
 * @return string The more restrictive of the two.
 */
function ec_feature_tier_min( $a, $b ) {
	$ladder = ec_feature_tier_ladder();

	$pos_a = array_search( $a, $ladder, true );
	$pos_b = array_search( $b, $ladder, true );

	if ( false === $pos_a ) {
		$pos_a = 0;
	}
	if ( false === $pos_b ) {
		$pos_b = 0;
	}

	return $ladder[ min( $pos_a, $pos_b ) ];
}

/**
 * Get the effective (clamped) rollout tier for a feature.
 *
 * Hybrid storage:
 *   - Code ceiling   = ec_feature_ceiling( $feature )      (max the code is ready for)
 *   - Network option = get_site_option( "ec_feature_tier_{$feature}" )  (current live tier)
 *
 * Effective tier = min( option_tier, ceiling ) on the ladder. The option can
 * NEVER expose a feature past the code ceiling — this is the safety rail that
 * makes flipping the live tier from wp-admin foot-gun-proof.
 *
 * @param string $feature Feature slug.
 * @return string One of `admin` | `team` | `public`.
 */
function ec_feature_tier( $feature ) {
	$ceiling = ec_feature_ceiling( $feature );

	$option_tier = get_site_option( "ec_feature_tier_{$feature}", $ceiling );
	if ( ! ec_feature_tier_is_valid( $option_tier ) ) {
		$option_tier = $ceiling;
	}

	return ec_feature_tier_min( $option_tier, $ceiling );
}

/**
 * Whether a feature is live for the given user's tier.
 *
 * This answers ONLY the rollout question ("has the feature been promoted to a
 * tier this user is in?"). It is composed with — never a substitute for — the
 * feature's own base capability gate (ec_user_can()).
 *
 *     admin  → user_can( $uid, 'manage_options' )
 *     team   → admin OR ec_is_team_member( $uid )
 *     public → true
 *
 * @param string   $feature Feature slug.
 * @param int|null $user_id User ID (defaults to current user).
 * @return bool
 */
function ec_feature_available( $feature, $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	$tier = ec_feature_tier( $feature );

	switch ( $tier ) {
		case 'public':
			return true;

		case 'team':
			if ( $user_id <= 0 ) {
				return false;
			}
			if ( user_can( $user_id, 'manage_options' ) ) {
				return true;
			}
			return function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id );

		case 'admin':
		default:
			if ( $user_id <= 0 ) {
				return false;
			}
			return user_can( $user_id, 'manage_options' );
	}
}

/**
 * The canonical capability gate.
 *
 * Answers "can this user do X?" for a named capability, dispatching per
 * capability to the existing permission logic. This is the single signature
 * every gate site should eventually call (existing ec_can_* functions are
 * temporary thin wrappers slated for migration+deletion per #60).
 *
 * Supported capabilities:
 *   - manage_artist          (context: artist_id) → ec_can_manage_artist()
 *   - create_artist_profile                       → ec_can_create_artist_profiles()
 *   - manage_shop                                 → ec_can_manage_shop()
 *   - manage_options                              → user_can manage_options passthrough
 *
 * Unknown capabilities return false.
 *
 * @param string $capability Capability slug.
 * @param array  $context    Optional context (e.g. [ 'artist_id' => 123, 'user_id' => 5 ]).
 * @return bool
 */
function ec_user_can( $capability, array $context = array() ) {
	$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();

	if ( $user_id <= 0 ) {
		return false;
	}

	switch ( $capability ) {
		case 'manage_artist':
			$artist_id = isset( $context['artist_id'] ) ? (int) $context['artist_id'] : null;
			return function_exists( 'ec_can_manage_artist' )
				&& ec_can_manage_artist( $user_id, $artist_id );

		case 'create_artist_profile':
			return function_exists( 'ec_can_create_artist_profiles' )
				&& ec_can_create_artist_profiles( $user_id );

		case 'manage_shop':
			return function_exists( 'ec_can_manage_shop' )
				&& ec_can_manage_shop( $user_id );

		case 'manage_options':
			return user_can( $user_id, 'manage_options' );

		default:
			return false;
	}
}
