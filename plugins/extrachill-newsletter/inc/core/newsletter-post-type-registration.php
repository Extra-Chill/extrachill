<?php
/**
 * Newsletter post type registration shared by web and delegated runtimes.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

/** Register the Newsletter-owned post type only in its blog context. */
function create_newsletter_post_type() {
	if ( ! is_newsletter_site() || post_type_exists( 'newsletter' ) ) {
		return;
	}

	register_post_type(
		'newsletter',
		array(
			'labels'              => array(
				'name'               => __( 'Newsletters', 'extrachill-newsletter' ),
				'singular_name'      => __( 'Newsletter', 'extrachill-newsletter' ),
				'add_new'            => __( 'Create Newsletter', 'extrachill-newsletter' ),
				'add_new_item'       => __( 'Add New Newsletter', 'extrachill-newsletter' ),
				'edit_item'          => __( 'Edit Newsletter', 'extrachill-newsletter' ),
				'new_item'           => __( 'New Newsletter', 'extrachill-newsletter' ),
				'view_item'          => __( 'View Newsletter', 'extrachill-newsletter' ),
				'search_items'       => __( 'Search Newsletters', 'extrachill-newsletter' ),
				'not_found'          => __( 'No newsletters found', 'extrachill-newsletter' ),
				'not_found_in_trash' => __( 'No newsletters found in trash', 'extrachill-newsletter' ),
			),
			'public'              => true,
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => '',
				'with_front' => false,
			),
			'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments' ),
			'show_in_rest'        => true,
			'menu_position'       => 6,
			'menu_icon'           => 'dashicons-email-alt',
			'capability_type'     => 'post',
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'can_export'          => true,
		)
	);
}
add_action( 'init', 'create_newsletter_post_type' );
