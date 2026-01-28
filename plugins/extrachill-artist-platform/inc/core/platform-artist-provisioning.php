<?php
/**
 * Platform Artist Profile Provisioning
 *
 * Ensures the "Extra Chill" platform artist profile exists and is
 * linked to the super admin user. Runs on activation and admin_init.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the super admin user ID.
 *
 * @return int Super admin user ID (fallback to 1).
 */
function ec_get_super_admin_user_id() {
	$super_admins = get_super_admins();
	if ( empty( $super_admins ) ) {
		return 1;
	}

	$super_admin_user = get_user_by( 'login', $super_admins[0] );
	return $super_admin_user ? $super_admin_user->ID : 1;
}

/**
 * Provision the platform artist profile.
 *
 * Creates the "Extra Chill" artist profile if it doesn't exist,
 * stores the ID in a network option, and links it to the super admin.
 *
 * @return int|false Artist profile ID on success, false on failure.
 */
function ec_provision_platform_artist() {
	$existing_id = get_site_option( 'ec_platform_artist_id' );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return false;
	}
	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );

	try {
		// If we have a stored ID, validate it still exists.
		if ( $existing_id ) {
			$existing_post = get_post( $existing_id );
			if ( $existing_post
				&& 'artist_profile' === $existing_post->post_type
				&& 'publish' === $existing_post->post_status ) {
				// Valid - ensure super admin is linked and return.
				restore_current_blog();
				ec_ensure_super_admin_linked( $existing_id );
				return $existing_id;
			}
			// Invalid - clear and re-provision.
			delete_site_option( 'ec_platform_artist_id' );
		}

		// Check if profile with slug 'extra-chill' already exists (backfill).
		$existing_by_slug = get_page_by_path( 'extra-chill', OBJECT, 'artist_profile' );
		if ( $existing_by_slug && 'publish' === $existing_by_slug->post_status ) {
			$artist_id = $existing_by_slug->ID;
			update_site_option( 'ec_platform_artist_id', $artist_id );
			restore_current_blog();
			ec_ensure_super_admin_linked( $artist_id );
			return $artist_id;
		}

		// Create new platform artist profile.
		$super_admin_id = ec_get_super_admin_user_id();

		$post_data = array(
			'post_title'   => 'Extra Chill',
			'post_name'    => 'extra-chill',
			'post_content' => '',
			'post_type'    => 'artist_profile',
			'post_status'  => 'publish',
			'post_author'  => $super_admin_id,
		);

		$artist_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $artist_id ) ) {
			return false;
		}

		update_site_option( 'ec_platform_artist_id', $artist_id );

		// Trigger shop to re-sync lifetime membership product with new platform artist.
		$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;
		if ( $shop_blog_id ) {
			switch_to_blog( $shop_blog_id );
			update_option( 'extrachill_shop_needs_lifetime_membership_product_sync', 1 );
			restore_current_blog();
		}

	} finally {
		restore_current_blog();
	}

	// Link super admin to artist (outside blog switch - user meta is network-wide).
	ec_ensure_super_admin_linked( $artist_id );

	return $artist_id;
}

/**
 * Ensure super admin is linked to the platform artist.
 *
 * @param int $artist_id Platform artist profile ID.
 */
function ec_ensure_super_admin_linked( $artist_id ) {
	if ( ! function_exists( 'ec_add_artist_membership' ) ) {
		return;
	}

	$super_admin_id = ec_get_super_admin_user_id();
	ec_add_artist_membership( $super_admin_id, $artist_id );
}

/**
 * Get the platform artist ID.
 *
 * Retrieves from network option, falls back to constant if defined.
 *
 * @return int|null Platform artist ID or null if not provisioned.
 */
function ec_get_platform_artist_id() {
	$id = get_site_option( 'ec_platform_artist_id' );
	if ( $id ) {
		return (int) $id;
	}

	// Fallback to constant (production compatibility).
	if ( defined( 'EC_PLATFORM_ARTIST_ID' ) ) {
		return EC_PLATFORM_ARTIST_ID;
	}

	return null;
}

/**
 * Run provisioning on admin_init (belt).
 * Uses transient to run only once per day.
 */
function ec_maybe_provision_platform_artist() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$transient_key = 'ec_platform_artist_provisioned';
	if ( get_site_transient( $transient_key ) ) {
		return;
	}

	ec_provision_platform_artist();
	set_site_transient( $transient_key, 1, DAY_IN_SECONDS );
}
add_action( 'admin_init', 'ec_maybe_provision_platform_artist' );

/**
 * Run provisioning on plugin activation (suspenders).
 */
function ec_activate_provision_platform_artist() {
	ec_provision_platform_artist();
	set_site_transient( 'ec_platform_artist_provisioned', 1, DAY_IN_SECONDS );
}
