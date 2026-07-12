<?php
/**
 * Abilities Registration
 *
 * Registers the extrachill-users ability category and loads all ability files.
 * Each file registers its own abilities on the wp_abilities_api_init hook.
 *
 * @package ExtraChill\Users
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_users_register_category' );

/**
 * Resolve the authenticated user for self-only abilities.
 *
 * Self-only abilities (profile/settings/subscriptions writes, etc.) operate on the
 * current user only. When invoked over the Abilities REST `/run` endpoint a client
 * could otherwise supply an arbitrary `user_id` (IDOR). This helper returns the
 * authenticated user id so execute callbacks can force `$input['user_id']` to it,
 * ignoring any client-supplied value. PHP-path callers (extrachill-api routes) pass
 * `get_current_user_id()` already, so they are unaffected.
 *
 * @return int|WP_Error Current user id, or WP_Error if not logged in.
 */
function extrachill_users_resolve_self_user_id() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error(
			'not_authenticated',
			__( 'You must be logged in.', 'extrachill-users' ),
			array( 'status' => 401 )
		);
	}

	return $user_id;
}

/**
 * Register users ability category.
 */
function extrachill_users_register_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	// Core's wp_abilities_api_categories_init action can fire more than once per
	// request on multisite; guard against re-registration to avoid a
	// _doing_it_wrong notice ("category already registered").
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-users' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-users',
		array(
			'label'       => __( 'Extra Chill Users', 'extrachill-users' ),
			'description' => __( 'User account lifecycle: registration, onboarding, settings, profile, and moderation.', 'extrachill-users' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/create-user.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/welcome-email.php';
require_once __DIR__ . '/artist-access.php';
require_once __DIR__ . '/user-administration.php';
require_once __DIR__ . '/user-settings.php';
require_once __DIR__ . '/user-profile.php';
require_once __DIR__ . '/subscriptions.php';
require_once __DIR__ . '/concert-tracking.php';
require_once __DIR__ . '/concert-import.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/users-leaderboard.php';
require_once __DIR__ . '/users-search.php';
require_once __DIR__ . '/get-user-by-id.php';
require_once __DIR__ . '/get-user-artists.php';
require_once __DIR__ . '/get-user-artist-access.php';
require_once __DIR__ . '/team-experience-stats.php';
require_once __DIR__ . '/artist-access-stats.php';
