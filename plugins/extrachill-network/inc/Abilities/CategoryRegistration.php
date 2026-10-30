<?php
/**
 * Single owner for the `extrachill-network` ability category.
 *
 * Every `*Abilities.php` class in this plugin uses
 * `'category' => 'extrachill-network'` on its `wp_register_ability()`
 * calls. The category itself must be registered exactly once, otherwise
 * the second registrar trips `_doing_it_wrong`.
 *
 * Historically each class tried to own its own category registration,
 * which started colliding the moment a second class was added. This file
 * is the single point of truth — every class consumes the category by id.
 *
 * @package ExtraChillNetwork\Abilities
 * @since 1.14.1
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Register the `extrachill-network` ability category.
 *
 * Hooked from the plugin bootstrap.
 */
function register_extrachill_network_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-network' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-network',
		array(
			'label'       => __( 'Extra Chill Network', 'extrachill-network' ),
			'description' => __( 'Network-wide cross-site operations', 'extrachill-network' ),
		)
	);
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_extrachill_network_category' );
