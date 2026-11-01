<?php
/**
 * Public Stories abilities for Festival Wire.
 *
 * Uses the network foundation's shared Stories projection and schemas.
 *
 * @package ExtraChillNewsWire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'ec_news_wire_register_ability_category' );
add_action( 'wp_abilities_api_init', 'ec_news_wire_register_stories_abilities' );

/**
 * Register Stories for published Festival Wire items.
 *
 * @return void
 */
function ec_news_wire_register_stories_abilities() {
	// Network-activated extrachill-network provides the shared projection. Guarding
	// keeps deploy order safe: no fatal if this plugin lands before the network one.
	if ( ! function_exists( 'extrachill_register_stories_abilities' ) ) {
		return;
	}

	extrachill_register_stories_abilities( 'festival_wire', 'extrachill-news-wire' );
}
