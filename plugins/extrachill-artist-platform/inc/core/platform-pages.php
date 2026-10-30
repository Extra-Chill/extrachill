<?php
/**
 * Required Artist Platform pages.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate old page slugs to new naming convention.
 *
 * Renames manage-artist-profiles -> manage-artist and updates block content.
 * Safe to run multiple times - checks if migration already complete.
 */
function extrachill_artist_platform_migrate_pages() {
	$old_page = get_page_by_path( 'manage-artist-profiles' );
	if ( $old_page ) {
		wp_update_post(
			array(
				'ID'           => $old_page->ID,
				'post_name'    => 'manage-artist',
				'post_title'   => 'Manage Artist',
				'post_content' => '<!-- wp:extrachill/artist-manager /-->',
			)
		);
	}
}

/**
 * Create required pages for the artist platform.
 *
 * Creates pages with appropriate blocks if they don't already exist.
 * Called on plugin activation and admin_init with version check.
 */
function extrachill_artist_platform_create_pages() {
	$pages = array(
		'create-artist'    => array(
			'title'   => 'Create Artist',
			'content' => '<!-- wp:extrachill/artist-creator /-->',
		),
		'manage-artist'    => array(
			'title'   => 'Manage Artist',
			'content' => '<!-- wp:extrachill/artist-manager /-->',
		),
		'manage-link-page' => array(
			'title'   => 'Manage Link Page',
			'content' => '<!-- wp:extrachill/link-page-editor /-->',
		),
		'manage-shop'      => array(
			'title'   => 'Manage Shop',
			'content' => '<!-- wp:extrachill/artist-shop-manager /-->',
		),
		'analytics'        => array(
			'title'   => 'Analytics',
			'content' => '<!-- wp:extrachill/artist-analytics /-->',
		),
	);

	foreach ( $pages as $slug => $page_data ) {
		$existing_page = get_page_by_path( $slug );
		if ( ! $existing_page ) {
			wp_insert_post(
				array(
					'post_title'   => $page_data['title'],
					'post_name'    => $slug,
					'post_content' => $page_data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
		}
	}
}

/**
 * Run page migration and creation on admin_init with version check.
 *
 * Ensures pages are migrated/created on plugin upgrades, not just fresh activations.
 */
function extrachill_artist_platform_maybe_create_pages() {
	$current_version = EXTRACHILL_ARTIST_PLATFORM_VERSION;
	$stored_version  = get_option( 'extrachill_artist_platform_pages_version', '0' );

	if ( version_compare( $stored_version, $current_version, '<' ) ) {
		extrachill_artist_platform_migrate_pages();
		extrachill_artist_platform_create_pages();
		update_option( 'extrachill_artist_platform_pages_version', $current_version );
	}
}
add_action( 'admin_init', 'extrachill_artist_platform_maybe_create_pages' );
