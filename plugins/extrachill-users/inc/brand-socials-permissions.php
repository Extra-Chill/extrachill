<?php
/**
 * Brand socials permission bridge.
 *
 * data-machine-socials operates the SHARED official Extra Chill brand social
 * accounts (Instagram / Twitter / Bluesky / Facebook), whose credentials are
 * stored network-wide. Its REST endpoints gate only on raw post capabilities
 * (`publish_posts` / `edit_posts` / `upload_files`), which the
 * `extra_chill_team` role already has — meaning any team member could post or
 * comment immediately, live, to the official accounts with no review.
 *
 * This bridge wires Data Machine Socials' generic
 * `datamachine_socials_user_can` filter (data-machine-socials#174) into EC's
 * feature-rollout ladder. It is the correct layer for this policy: the socials
 * plugin knows nothing about Extra Chill; this plugin is the one place where
 * EC-specific knowledge meets that generic permission surface — exactly how
 * extrachill-roadie bridges `access_roadie` into `datamachine_can_access_agent`.
 *
 * The whole brand-socials surface (publish / edit / upload) is gated as one
 * clean boundary behind the `brand_socials` feature, which ships with an
 * `admin` ceiling (see ec_feature_ceilings() in inc/core/gating.php). It can be
 * promoted to the team — and later the public — by flipping the network option,
 * exactly how the `shop` feature works, with no code change here.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability that grants ONE specific person brand-socials access.
 *
 * This cap is deliberately NOT part of the `extra_chill_team` role's default
 * cap set (see ec_users_get_team_role_caps() in inc/team-members/role.php).
 * Adding it to the role would hand brand-socials access to EVERY team member,
 * which defeats the purpose of keeping the `brand_socials` feature on an
 * `admin` ceiling. Instead it is an explicit, per-user grant managed via
 * {@see ec_grant_brand_socials()} / {@see ec_revoke_brand_socials()} and ORed
 * into the gate below — broadening access for the granted individual without
 * weakening the admin-only default for everyone else.
 */
const EC_BRAND_SOCIALS_CAP = 'manage_brand_socials';

/**
 * Gate the shared brand social accounts behind the `brand_socials` feature.
 *
 * Hooks the generic `datamachine_socials_user_can` filter and ANDs the base
 * capability result with the EC rollout gate. A user who fails the feature gate
 * is denied even if they hold the raw post capability; a user who passes still
 * needs the base capability (we never grant access the socials plugin denied).
 *
 * The rollout gate is ORed with an explicit per-person capability grant
 * ({@see EC_BRAND_SOCIALS_CAP}). A user who passes the feature tier OR holds
 * the per-person grant is allowed (still subject to the base `$allowed`). The
 * OR can only ever BROADEN access for an explicitly-granted individual — it
 * never weakens the admin-only denial for anyone who lacks the grant, because
 * the base `$allowed &&` short-circuits first.
 *
 * @since 1.0.0
 *
 * @param bool   $allowed Whether the base capability check passed.
 * @param string $action  The action being gated: one of `publish` | `edit` | `upload`.
 * @param int    $user_id The current user ID.
 * @return bool
 */
function ec_brand_socials_user_can( $allowed, $action, $user_id ) {
	if ( in_array( $action, array( 'publish', 'edit', 'upload' ), true ) ) {
		return $allowed && (
			( function_exists( 'ec_feature_available' ) && ec_feature_available( 'brand_socials', $user_id ) )
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom per-person cap granted by ec_grant_brand_socials() (extrachill-users#133).
			|| user_can( $user_id, EC_BRAND_SOCIALS_CAP )
		);
	}

	return $allowed;
}
add_filter( 'datamachine_socials_user_can', 'ec_brand_socials_user_can', 10, 3 );

/**
 * Grant the per-person brand-socials capability to a user, network-wide.
 *
 * Brand-social credentials are network-shared, so the grant must be consistent
 * across every site — a user granted on one subsite but not another would see
 * the gate flip depending on which site's request resolved the cap. Mirrors
 * the network-wide write pattern of ec_users_grant_team_role(): iterate every
 * site, switch_to_blog(), add the cap on the user's row.
 *
 * Super-admins are skipped — they already pass the `admin` tier via
 * manage_options, so the per-person grant is redundant noise on their account.
 *
 * @since 1.0.0
 *
 * @param int $user_id User ID.
 * @return int[] Blog IDs the cap was newly added on (already-present sites omitted).
 */
function ec_grant_brand_socials( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
		return array();
	}

	$added = array();

	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		$blog_id = (int) $blog_id;
		try {
			switch_to_blog( $blog_id );

			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom per-person cap (extrachill-users#133).
			if ( ! $user->has_cap( EC_BRAND_SOCIALS_CAP ) ) {
				$user->add_cap( EC_BRAND_SOCIALS_CAP );
				$added[] = $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}

	return $added;
}

/**
 * Revoke the per-person brand-socials capability from a user, network-wide.
 *
 * Pure write — counterpart to {@see ec_grant_brand_socials()}.
 *
 * @since 1.0.0
 *
 * @param int $user_id User ID.
 * @return int[] Blog IDs the cap was removed from.
 */
function ec_revoke_brand_socials( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	$removed = array();

	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		$blog_id = (int) $blog_id;
		try {
			switch_to_blog( $blog_id );

			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom per-person cap (extrachill-users#133).
			if ( $user->has_cap( EC_BRAND_SOCIALS_CAP ) ) {
				$user->remove_cap( EC_BRAND_SOCIALS_CAP );
				$removed[] = $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}

	return $removed;
}
