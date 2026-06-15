<?php
declare(strict_types=1);
/**
 * Abilities Registration
 *
 * Registers the extrachill-contact ability category and loads all ability files.
 * Each file registers its own abilities on the wp_abilities_api_init hook.
 *
 * @package ExtraChillContact
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_contact_register_ability_category' );

/**
 * Register contact ability category.
 */
function extrachill_contact_register_ability_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-contact' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-contact',
		array(
			'label'       => __( 'Extra Chill Contact', 'extrachill-contact' ),
			'description' => __( 'Contact form submission and processing.', 'extrachill-contact' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/contact-submit.php';
