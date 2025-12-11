<?php
/**
 * Artist Profile Manager Block - Server-Side Render
 *
 * Renders the artist profile manager React app on the frontend for authorized users.
 * Handles authentication, permissions, artist selection, and config localization.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Require login
if ( ! is_user_logged_in() ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Please log in to manage your artist profile.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

$current_user_id = get_current_user_id();

// Ensure required functions exist
if ( ! function_exists( 'ec_get_artists_for_user' ) || ! function_exists( 'ec_can_create_artist_profiles' ) ) {
    echo '<div class="notice notice-error">';
    echo '<p>' . esc_html__( 'Artist platform is not properly configured.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

// Fetch artists for user (include pending so join-flow prefills can proceed)
$user_artists = ec_get_artists_for_user( $current_user_id, true );

$selected_artist_id = 0;

if ( ! empty( $user_artists ) ) {
    // Prefer latest managed artist helper when available
    $selected_artist_id = function_exists( 'ec_get_latest_artist_for_user' )
        ? ec_get_latest_artist_for_user( $current_user_id )
        : (int) reset( $user_artists );
}

// Build artist list for switcher (published only)
$user_artists_data = array();
foreach ( $user_artists as $artist_id ) {
    $artist_post = get_post( $artist_id );
    if ( $artist_post && $artist_post->post_status === 'publish' ) {
        $user_artists_data[] = array(
            'id'   => (int) $artist_id,
            'name' => $artist_post->post_title,
            'slug' => $artist_post->post_name,
        );
    }
}

// Build config payload for frontend (management-only, no creation)
$config = array(
    'restUrl'       => rest_url( 'extrachill/v1/' ),
    'nonce'         => wp_create_nonce( 'wp_rest' ),
    'userArtists'   => $user_artists_data,
    'selectedId'    => (int) $selected_artist_id,
    'artistSiteUrl' => home_url(),
    'assets'        => array(
        'linkPageCssUrl'     => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/extrch-links.css',
        'socialIconsCssUrl'  => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/custom-social-icons.css',
        'shareModalCssUrl'   => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/extrch-share-modal.css',
        'fontAwesomeUrl'     => '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
        'iconSpriteUrl'      => get_template_directory_uri() . '/assets/fonts/extrachill.svg?v=' . filemtime( get_template_directory() . '/assets/fonts/extrachill.svg' ),
    ),
);

// Enqueue frontend script and styles
$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/artist-profile-manager/view.asset.php';

wp_enqueue_script(
    'ec-artist-profile-manager-frontend',
    EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/artist-profile-manager/view.js',
    $asset_file['dependencies'],
    $asset_file['version'],
    true
);

wp_localize_script( 'ec-artist-profile-manager-frontend', 'ecArtistPlatformConfig', $config );

// Render mount point
$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'ec-artist-profile-manager',
) );

echo '<div ' . $wrapper_attributes . '>';
// data-selected-id allows hydration without relying on URL params
$selected_attr = (int) $selected_artist_id;
echo '<div id="ec-artist-profile-manager-root" data-selected-id="' . esc_attr( $selected_attr ) . '"></div>';
echo '</div>';
