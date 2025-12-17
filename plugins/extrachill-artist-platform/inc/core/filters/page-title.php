<?php
/**
 * Page Title Filter
 *
 * Hooks into theme's extrachill_show_page_title filter to hide
 * page titles on artist platform management interfaces.
 */

add_filter( 'extrachill_show_page_title', 'ec_hide_management_page_titles', 10, 2 );

/**
 * Hide page titles on artist platform management pages.
 *
 * @param bool $show Whether to show the page title.
 * @param int  $post_id The current page ID.
 * @return bool
 */
function ec_hide_management_page_titles( $show, $post_id ) {
	if ( is_page( 'manage-link-page' ) || is_page( 'manage-artist' ) || is_page( 'create-artist' ) || is_page( 'manage-shop' ) ) {
		return false;
	}
	return $show;
}
