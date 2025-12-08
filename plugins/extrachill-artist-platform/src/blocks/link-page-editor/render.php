<?php
/**
 * Link Page Editor Block - Server-Side Render
 *
 * Renders the link page editor React app on the frontend.
 * Handles authentication, artist resolution, and data localization.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Authentication check
if ( ! is_user_logged_in() ) {
	echo '<div class="notice notice-info">';
	echo '<p>' . esc_html__( 'Please log in to manage your link page.', 'extrachill-artist-platform' ) . '</p>';
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
		echo '<p>' . esc_html__( 'Create an artist profile to get started with your link page.', 'extrachill-artist-platform' ) . '</p>';
		echo '<a href="' . esc_url( site_url( '/manage-artist-profiles/' ) ) . '" class="button-1">' . esc_html__( 'Create Artist Profile', 'extrachill-artist-platform' ) . '</a>';
		echo '</div>';
	} else {
		echo '<div class="notice notice-info">';
		echo '<p>' . esc_html__( 'Artist profiles are for artists and music professionals.', 'extrachill-artist-platform' ) . '</p>';
		echo '</div>';
	}
	return;
}

// Get the artist to edit (latest by default)
$artist_id = function_exists( 'ec_get_latest_artist_for_user' ) 
	? ec_get_latest_artist_for_user( $current_user_id ) 
	: reset( $user_artists );

if ( ! $artist_id ) {
	echo '<div class="notice notice-error">';
	echo '<p>' . esc_html__( 'Could not determine which artist to edit.', 'extrachill-artist-platform' ) . '</p>';
	echo '</div>';
	return;
}

// Build user artists data for switcher
$user_artists_data = array();
foreach ( $user_artists as $ua_id ) {
	$artist_post = get_post( $ua_id );
	if ( $artist_post && $artist_post->post_status === 'publish' ) {
		$user_artists_data[] = array(
			'id'   => (int) $ua_id,
			'name' => $artist_post->post_title,
			'slug' => $artist_post->post_name,
		);
	}
}

// Get fonts for dropdown and local font CSS for defaults
$fonts_data      = array();
$local_fonts_css = '';
if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
	$fonts_manager   = ExtraChillArtistPlatform_Fonts::instance();
	$fonts_data      = $fonts_manager->get_supported_fonts();
	$local_fonts_css = $fonts_manager->get_local_fonts_css(
		array(
			ExtraChillArtistPlatform_Fonts::DEFAULT_TITLE_FONT,
			ExtraChillArtistPlatform_Fonts::DEFAULT_BODY_FONT,
		)
	);
}

// Get social link types and transform to array format for React
$social_types = array();
if ( function_exists( 'extrachill_artist_platform_social_links' ) ) {
	$social_manager = extrachill_artist_platform_social_links();
	$raw_types      = $social_manager->get_supported_types();
	
	// Transform associative array to indexed array with id/label for React
	foreach ( $raw_types as $type_id => $type_data ) {
		$social_types[] = array(
			'id'    => $type_id,
			'label' => $type_data['label'],
			'icon'  => isset( $type_data['icon'] ) ? $type_data['icon'] : '',
		);
	}
}

// Localize configuration data
$config = array(
	'artistId'          => (int) $artist_id,
	'userArtists'       => $user_artists_data,
	'restUrl'           => rest_url( 'extrachill/v1/' ),
	'nonce'             => wp_create_nonce( 'wp_rest' ),
	'fonts'             => $fonts_data,
	'localFontsCss'     => $local_fonts_css,
	'socialTypes'       => $social_types,
'linkPageCssUrl'     => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/extrch-links.css',
    'socialIconsCssUrl' => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/custom-social-icons.css',
    'shareModalCssUrl'  => EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/extrch-share-modal.css',
    'fontAwesomeUrl'    => '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
    'iconSpriteUrl'     => get_template_directory_uri() . '/assets/fonts/extrachill.svg?v=' . filemtime( get_template_directory() . '/assets/fonts/extrachill.svg' ),
);


// Enqueue the frontend script with localized data
$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/link-page-editor/view.asset.php';

wp_enqueue_script(
	'ec-link-page-editor-frontend',
	EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/link-page-editor/view.js',
	$asset_file['dependencies'],
	$asset_file['version'],
	true
);

wp_localize_script( 'ec-link-page-editor-frontend', 'ecLinkPageEditorConfig', $config );

// Render mount point
$wrapper_attributes = get_block_wrapper_attributes( array(
	'class' => 'ec-link-page-editor',
) );

echo '<div ' . $wrapper_attributes . '>';
echo '<div id="ec-link-page-editor-root" data-artist-id="' . esc_attr( $artist_id ) . '"></div>';
echo '</div>';
