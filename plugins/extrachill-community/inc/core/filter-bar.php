<?php
/**
 * Community Filter Bar Items
 *
 * Hooks into theme's universal filter bar to provide community-specific
 * sort options (upvotes) and search functionality.
 *
 * @package ExtraChillCommunity
 * @since 1.1.1
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'extrachill_filter_bar_items', 'extrachill_community_filter_bar_items' );

/**
 * Register community filter bar items.
 *
 * @param array $items Existing items.
 * @return array Modified items.
 */
function extrachill_community_filter_bar_items( $items ) {
	if ( ! function_exists( 'is_bbpress' ) || ! is_bbpress() ) {
		return $items;
	}

	$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'default';
	$current_search = isset( $_GET['bbp_search'] ) ? sanitize_text_field( wp_unslash( $_GET['bbp_search'] ) ) : '';

	// Sort dropdown with community-specific options.
	$items[] = array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-sort',
		'name'    => 'sort',
		'options' => array(
			'default' => __( 'Sort by Recent', 'extrachill-community' ),
			'upvotes' => __( 'Sort by Upvotes', 'extrachill-community' ),
			'popular' => __( 'Sort by Popular', 'extrachill-community' ),
		),
		'current' => $current_sort,
	);

	// Search input.
	$items[] = array(
		'type'        => 'search',
		'id'          => 'filter-bar-search',
		'name'        => 'bbp_search',
		'placeholder' => __( 'Search topics...', 'extrachill-community' ),
		'current'     => $current_search,
	);

	return $items;
}
