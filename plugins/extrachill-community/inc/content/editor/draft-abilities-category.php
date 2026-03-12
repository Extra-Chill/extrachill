<?php
/**
 * Community Draft Ability Category
 *
 * Registers the ability category used by community draft abilities.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_community_register_ability_category' );

function extrachill_community_register_ability_category() {
	wp_register_ability_category(
		'extrachill-community',
		array(
			'label'       => __( 'Extra Chill Community', 'extrachill-community' ),
			'description' => __( 'Community and bbPress capabilities for Extra Chill.', 'extrachill-community' ),
		)
	);
}
