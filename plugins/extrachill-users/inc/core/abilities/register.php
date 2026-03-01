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
 * Register users ability category.
 */
function extrachill_users_register_category() {
	wp_register_ability_category(
		'extrachill-users',
		array(
			'label'       => __( 'Extra Chill Users', 'extrachill-users' ),
			'description' => __( 'User account lifecycle: registration, onboarding, and welcome emails.', 'extrachill-users' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/create-user.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/welcome-email.php';
