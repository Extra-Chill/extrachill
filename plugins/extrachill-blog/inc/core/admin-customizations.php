<?php
/**
 * Blog-Specific Admin Customizations
 *
 * Admin menu modifications and customizations specific to the main blog site.
 *
 * @package ExtraChillBlog
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove navigation menus from theme customizer
 * Only applies when this blog plugin is active
 */
function extrachill_blog_remove_customizer_menus( $wp_customize ) {
	$wp_customize->remove_panel( 'nav_menus' );
}
add_action( 'customize_register', 'extrachill_blog_remove_customizer_menus', 20 );