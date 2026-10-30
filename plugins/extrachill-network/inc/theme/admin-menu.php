<?php
/**
 * Extra Chill Admin Menu Customization
 *
 * Removes Posts menu from non-main sites and Menus submenu globally.
 * Extra Chill-specific network behavior.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_remove_menu_admin_pages() {
	remove_submenu_page( 'themes.php', 'nav-menus.php' );

	$keep_posts = is_main_site() || (int) get_current_blog_id() === EC_BLOG_ID_STUDIO;
	if ( ! $keep_posts ) {
		remove_menu_page( 'edit.php' );
	}
}
add_action( 'admin_menu', 'extrachill_remove_menu_admin_pages', 999 );
