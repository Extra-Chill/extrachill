<?php
/**
 * Artist storefront "Manage Shop" button.
 *
 * Shows a management CTA only when viewing an artist taxonomy archive on the shop site
 * and the current user can manage that artist profile.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_archive_header_actions', 'extrachill_shop_maybe_render_manage_shop_button', 15 );

function extrachill_shop_maybe_render_manage_shop_button() {
	if ( ! is_tax( 'artist' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) || empty( $term->slug ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_get_artist_profile_by_slug' ) ) {
		return;
	}

	$artist_data = extrachill_get_artist_profile_by_slug( $term->slug );
	if ( empty( $artist_data['id'] ) ) {
		return;
	}

	// Artist-aware: admins or users who manage this specific artist.
	$artist_id = (int) $artist_data['id'];
	if ( ! extrachill_shop_user_can_manage_artist( $artist_id ) ) {
		return;
	}

	$artist_site_url = ec_get_site_url( 'artist' );
	if ( ! $artist_site_url ) {
		return;
	}

	$manage_url = trailingslashit( $artist_site_url ) . 'manage-shop/';

	echo '<div class="artist-profile-link-container">';
	echo '<a class="button-1 button-medium" href="' . esc_url( $manage_url ) . '">';
	echo esc_html__( 'Manage Shop', 'extrachill-shop' );
	echo '</a>';
	echo '</div>';
}
