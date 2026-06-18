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
 * Gate the shared brand social accounts behind the `brand_socials` feature.
 *
 * Hooks the generic `datamachine_socials_user_can` filter and ANDs the base
 * capability result with the EC rollout gate. A user who fails the feature gate
 * is denied even if they hold the raw post capability; a user who passes still
 * needs the base capability (we never grant access the socials plugin denied).
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
		return $allowed && function_exists( 'ec_feature_available' ) && ec_feature_available( 'brand_socials', $user_id );
	}

	return $allowed;
}
add_filter( 'datamachine_socials_user_can', 'ec_brand_socials_user_can', 10, 3 );
