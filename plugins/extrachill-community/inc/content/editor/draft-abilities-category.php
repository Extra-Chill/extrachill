<?php
/**
 * Community Draft Ability Category
 *
 * Registers the ability category used by community draft abilities.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( did_action( 'wp_abilities_api_categories_init' ) ) {
	extrachill_community_register_ability_category();
} else {
	add_action( 'wp_abilities_api_categories_init', 'extrachill_community_register_ability_category' );
}

function extrachill_community_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-community' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-community',
		array(
			'label'       => __( 'Extra Chill Community', 'extra-chill-community' ),
			'description' => __( 'Community and bbPress capabilities for Extra Chill.', 'extra-chill-community' ),
		)
	);
}
