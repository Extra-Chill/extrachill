<?php
/**
 * Main-site Artist Dispatch contributor role.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EC_USERS_ARTIST_DISPATCH_ROLE = 'extra_chill_artist_dispatch_contributor';

/**
 * Return the exact native capability contract for Artist Dispatch contributors.
 *
 * @return array<string,bool>
 */
function ec_users_get_artist_dispatch_role_caps() {
	return array(
		'read'              => true,
		'edit_posts'        => true,
		'delete_posts'      => true,
		'submit_for_review' => true,
	);
}

/**
 * Resolve the publication blog.
 *
 * @return int
 */
function ec_users_get_artist_dispatch_blog_id() {
	if ( function_exists( 'ec_get_blog_id' ) ) {
		return (int) ec_get_blog_id( 'main' );
	}

	return function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
}

/**
 * Register the role only when operating on the main publication site.
 */
function ec_users_register_artist_dispatch_role() {
	if ( get_current_blog_id() !== ec_users_get_artist_dispatch_blog_id() ) {
		return;
	}

	$caps     = ec_users_get_artist_dispatch_role_caps();
	$existing = get_role( EC_USERS_ARTIST_DISPATCH_ROLE );
	if ( $existing instanceof WP_Role ) {
		$existing_caps = array_filter( $existing->capabilities );
		$desired_caps  = $caps;
		ksort( $existing_caps );
		ksort( $desired_caps );
		if ( $existing_caps === $desired_caps ) {
			return;
		}
		remove_role( EC_USERS_ARTIST_DISPATCH_ROLE );
	}

	add_role(
		EC_USERS_ARTIST_DISPATCH_ROLE,
		__( 'Artist Dispatch Contributor', 'extrachill-users' ),
		$caps
	);
}

/**
 * Ensure the role exists on blog 1 without leaking the role to other sites.
 */
function ec_users_register_artist_dispatch_role_on_main() {
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	if ( $blog_id <= 0 ) {
		return;
	}

	if ( get_current_blog_id() === $blog_id ) {
		ec_users_register_artist_dispatch_role();
		return;
	}

	switch_to_blog( $blog_id );
	try {
		ec_users_register_artist_dispatch_role();
	} finally {
		restore_current_blog();
	}
}

add_action( 'init', 'ec_users_register_artist_dispatch_role', 5 );

/**
 * Keep the additive role out of WordPress's destructive single-role dropdown.
 *
 * @param array<string,array> $roles Editable roles.
 * @return array<string,array>
 */
function ec_users_hide_artist_dispatch_role_from_editable_roles( $roles ) {
	unset( $roles[ EC_USERS_ARTIST_DISPATCH_ROLE ] );
	return $roles;
}
add_filter( 'editable_roles', 'ec_users_hide_artist_dispatch_role_from_editable_roles' );
