<?php
/**
 * Artist Analytics Block - Server-Side Render
 *
 * Renders the artist analytics React app on the frontend.
 * Handles authentication, artist resolution, and data localization.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Authentication check
if ( ! is_user_logged_in() ) {
	echo '<div class="notice notice-info">';
	echo '<p>' . esc_html__( 'Please log in to view your analytics.', 'extrachill-artist-platform' ) . '</p>';
	echo '</div>';
	return;
}

$current_user_id = get_current_user_id();

// Get user's artists
if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
	echo '<div class="notice notice-error">';
	echo '<p>' . esc_html__( 'Artist platform is not properly configured.', 'extrachill-artist-platform' ) . '</p>';
	echo '</div>';
	return;
}

$user_artists = ec_get_artists_for_user( $current_user_id, true );

// No artists - show creation prompt
if ( empty( $user_artists ) ) {
	if ( function_exists( 'ec_can_create_artist_profiles' ) && ec_can_create_artist_profiles( $current_user_id ) ) {
		echo '<div class="notice notice-info">';
		echo '<p>' . esc_html__( 'Create an artist profile to start tracking analytics.', 'extrachill-artist-platform' ) . '</p>';
		echo '<a href="' . esc_url( site_url( '/create-artist/' ) ) . '" class="button-1">' . esc_html__( 'Create Artist Profile', 'extrachill-artist-platform' ) . '</a>';
		echo '</div>';
	} else {
		echo '<div class="notice notice-info">';
		echo '<p>' . esc_html__( 'Artist profiles are for artists and music professionals.', 'extrachill-artist-platform' ) . '</p>';
		echo '</div>';
	}
	return;
}

// Build user artists data for switcher (only artists with link pages)
$user_artists_data = array();
$user_artist_ids_with_link_pages = array();

foreach ( $user_artists as $ua_id ) {
    $artist_post = get_post( $ua_id );
    if ( ! $artist_post || $artist_post->post_status !== 'publish' ) {
        continue;
    }

    $link_page_id = function_exists( 'ec_get_link_page_for_artist' )
        ? ec_get_link_page_for_artist( $ua_id )
        : 0;

    if ( $link_page_id && get_post_status( $link_page_id ) === 'publish' ) {
        $user_artists_data[] = array(
            'id'   => (int) $ua_id,
            'name' => $artist_post->post_title,
            'slug' => $artist_post->post_name,
        );
        $user_artist_ids_with_link_pages[] = (int) $ua_id;
    }
}

// No link pages yet for this user
if ( empty( $user_artists_data ) ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Create a link page to start tracking analytics.', 'extrachill-artist-platform' ) . '</p>';
    echo '<a href="' . esc_url( site_url( '/manage-link-page/' ) ) . '" class="button-1 button-medium">' . esc_html__( 'Create Link Page', 'extrachill-artist-platform' ) . '</a>';
    echo '</div>';
    return;
}

// Get the artist to view (prefer latest with a link page)
$artist_id = 0;
if ( function_exists( 'ec_get_latest_artist_for_user' ) ) {
    $latest_artist_id = ec_get_latest_artist_for_user( $current_user_id );
    if ( $latest_artist_id && in_array( (int) $latest_artist_id, $user_artist_ids_with_link_pages, true ) ) {
        $artist_id = (int) $latest_artist_id;
    }
}

if ( ! $artist_id ) {
    $artist_id = (int) $user_artists_data[0]['id'];
}

if ( ! $artist_id ) {
    echo '<div class="notice notice-error">';
    echo '<p>' . esc_html__( 'Could not determine which artist to view.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

// Localize configuration data
$link_page_id = ec_get_link_page_for_artist( $artist_id );

if ( ! $link_page_id || get_post_status( $link_page_id ) !== 'publish' ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Create a link page to start tracking analytics.', 'extrachill-artist-platform' ) . '</p>';
    echo '<a href="' . esc_url( site_url( '/manage-link-page/' ) ) . '" class="button-1 button-medium">' . esc_html__( 'Create Link Page', 'extrachill-artist-platform' ) . '</a>';
    echo '</div>';
    return;
}

$config = array(
	'artistId'    => (int) $artist_id,
	'userArtists' => $user_artists_data,
	'restUrl'     => rest_url( 'extrachill/v1/' ),
	'nonce'       => wp_create_nonce( 'wp_rest' ),
);

// Enqueue the frontend script with localized data
$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/artist-analytics/view.asset.php';

wp_enqueue_script(
	'ec-artist-analytics-frontend',
	EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/artist-analytics/view.js',
	$asset_file['dependencies'],
	$asset_file['version'],
	true
);

wp_localize_script( 'ec-artist-analytics-frontend', 'ecArtistAnalyticsConfig', $config );

// Render mount point
$wrapper_attributes = get_block_wrapper_attributes( array(
	'class' => 'ec-artist-analytics',
) );

echo '<div ' . $wrapper_attributes . '>';
echo '<div id="ec-artist-analytics-root" data-artist-id="' . esc_attr( $artist_id ) . '"></div>';
echo '</div>';
