<?php
/**
 * Artist Creator Block - Server-Side Render
 *
 * Renders the artist creation form on the frontend for authorized users.
 * Single-purpose: create a new artist profile.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Require login
if ( ! is_user_logged_in() ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Please log in to create an artist profile.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

$current_user_id = get_current_user_id();

// Ensure required functions exist
if ( ! function_exists( 'ec_can_create_artist_profiles' ) ) {
    echo '<div class="notice notice-error">';
    echo '<p>' . esc_html__( 'Artist platform is not properly configured.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

// Check permission
$can_create = ec_can_create_artist_profiles( $current_user_id );

if ( ! $can_create ) {
	echo '<div class="notice notice-info">';
	echo '<p>' . esc_html__( 'Artist profiles are for artists and music professionals.', 'extrachill-artist-platform' ) . '</p>';
	echo '</div>';
	return;
}

// Check if user already has artist profiles
if ( function_exists( 'ec_get_artists_for_user' ) ) {
	$existing_artists = ec_get_artists_for_user( $current_user_id );
	if ( ! empty( $existing_artists ) ) {
		$artist_count = count( $existing_artists );
		echo '<div class="notice notice-info">';
		if ( $artist_count === 1 ) {
			echo '<p>' . esc_html__( 'You already have an artist profile.', 'extrachill-artist-platform' ) . '</p>';
		} else {
			echo '<p>' . sprintf(
				/* translators: %d: number of artist profiles */
				esc_html__( 'You already have %d artist profiles.', 'extrachill-artist-platform' ),
				$artist_count
			) . '</p>';
		}
		echo '<a href="' . esc_url( home_url( '/manage-artist/' ) ) . '" class="button-1 button-medium">' . esc_html__( 'Manage Artist', 'extrachill-artist-platform' ) . '</a>';
		echo '</div>';
	}
}

// Prefill data from user profile
$prefill = array();
$current_user = wp_get_current_user();
if ( $current_user && $current_user->ID ) {
    $prefill['artist_name'] = $current_user->display_name;
    $prefill['avatar_id']   = get_user_meta( $current_user->ID, 'custom_avatar_id', true );
    if ( $prefill['avatar_id'] ) {
        $prefill['avatar_thumb'] = wp_get_attachment_image_url( $prefill['avatar_id'], 'thumbnail' );
    }
}

// Build config payload for frontend
$config = array(
    'restUrl'           => rest_url( 'extrachill/v1/' ),
    'nonce'             => wp_create_nonce( 'wp_rest' ),
    'prefill'           => $prefill,
    'manageArtistUrl'   => home_url( '/manage-artist/' ),
    'createLinkPageUrl' => home_url( '/manage-link-page/' ),
    // Admin-only during development
    'createShopUrl'     => current_user_can( 'manage_options' ) ? home_url( '/manage-shop/' ) : '',
);

// Enqueue frontend script and styles
$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/artist-creator/view.asset.php';

wp_enqueue_script(
    'ec-artist-creator-frontend',
    EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/artist-creator/view.js',
    $asset_file['dependencies'],
    $asset_file['version'],
    true
);

wp_localize_script( 'ec-artist-creator-frontend', 'ecArtistCreatorConfig', $config );

// Render mount point
$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'ec-artist-creator',
) );

echo '<div ' . $wrapper_attributes . '>';
echo '<div id="ec-artist-creator-root"></div>';
echo '</div>';
