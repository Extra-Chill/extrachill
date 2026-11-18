<?php
/**
 * Test support: register Community abilities through WordPress core's real
 * Abilities API registry.
 *
 * The host-smoke backend requires smoke files inside a fully booted WordPress
 * with this plugin mounted but not activated, so none of the plugin's
 * `wp_abilities_api_*` hooks have run. Core only accepts registrations while
 * its init actions are firing, so this replays each action with just the
 * Community callback attached.
 *
 * @package ExtraChillCommunity
 */

/**
 * Whether the smoke is running inside a fully booted WordPress whose
 * Abilities API registry is live (rather than a SHORTINIT bootstrap).
 */
function extrachill_community_test_has_booted_ability_registry(): bool {
	return class_exists( 'WP_Abilities_Registry' ) && function_exists( 'did_action' ) && did_action( 'init' ) > 0;
}

/**
 * Register the Community ability category and the given abilities through
 * the real registry.
 *
 * @param callable $register_abilities Plugin function that calls wp_register_ability().
 */
function extrachill_community_test_register_abilities( callable $register_abilities ): void {
	// Instantiate both registries first so core's own callbacks run once,
	// then replay each init action with only the Community callback attached.
	wp_has_ability_category( 'extrachill-community' );
	remove_all_actions( 'wp_abilities_api_categories_init' );
	add_action( 'wp_abilities_api_categories_init', 'extrachill_community_test_register_ability_category' );
	do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );

	wp_get_abilities();
	remove_all_actions( 'wp_abilities_api_init' );
	add_action( 'wp_abilities_api_init', $register_abilities );
	do_action( 'wp_abilities_api_init', WP_Abilities_Registry::get_instance() );
}

/**
 * Category callback. Loading the plugin's category file while the action is
 * firing makes it register immediately, inside the action core requires.
 */
function extrachill_community_test_register_ability_category(): void {
	if ( function_exists( 'extrachill_community_register_ability_category' ) ) {
		extrachill_community_register_ability_category();
		return;
	}
	require_once dirname( __DIR__, 2 ) . '/inc/content/editor/draft-abilities-category.php';
}
