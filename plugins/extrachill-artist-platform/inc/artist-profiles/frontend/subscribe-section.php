<?php
/**
 * Artist profile subscription section.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the shared subscription behavior and profile-specific presentation.
 *
 * @return void
 */
function ec_enqueue_artist_profile_subscribe_assets() {
	if ( ! is_singular( 'artist_profile' ) ) {
		return;
	}

	wp_enqueue_style(
		'extrachill-artist-profile-subscribe',
		EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'assets/css/artist-profile-subscribe.css',
		array( 'extrachill-artist-profile' ),
		EXTRACHILL_ARTIST_PLATFORM_VERSION
	);

	wp_enqueue_script(
		'extrachill-subscribe',
		EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'inc/link-pages/live/assets/js/link-page-subscribe.js',
		array(),
		EXTRACHILL_ARTIST_PLATFORM_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'ec_enqueue_artist_profile_subscribe_assets' );

/**
 * Register the artist-specific subscription form on canonical profiles.
 *
 * @param array[] $sections Registered profile sections.
 * @return array[]
 */
function ec_register_artist_profile_subscribe_section( $sections ) {
	$sections[] = array(
		'id'       => 'subscribe',
		'label'    => __( 'Subscribe', 'extrachill-artist-platform' ),
		'priority' => 15,
		'as_tab'   => false,
		'render'   => 'ec_render_artist_profile_subscribe_section',
		'visible'  => 'ec_is_artist_profile_subscribe_section_visible',
	);

	return $sections;
}
add_filter( 'ec_artist_profile_sections', 'ec_register_artist_profile_subscribe_section', 10, 1 );

/**
 * Show subscriptions only for public artists that have not disabled the flow.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return bool
 */
function ec_is_artist_profile_subscribe_section_visible( $artist_profile_id ) {
	if ( 'publish' !== get_post_status( $artist_profile_id ) ) {
		return false;
	}

	$data = ec_get_artist_profile_subscribe_section_data( $artist_profile_id );

	return 'disabled' !== ( $data['_link_page_subscribe_display_mode'] ?? '' );
}

/**
 * Get existing link-page subscription settings for the profile form.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return array
 */
function ec_get_artist_profile_subscribe_section_data( $artist_profile_id ) {
	$link_page_id = (int) apply_filters( 'ec_get_link_page_id', $artist_profile_id );

	if ( $link_page_id > 0 && function_exists( 'ec_get_link_page_data' ) ) {
		return ec_get_link_page_data( $artist_profile_id, $link_page_id );
	}

	return array();
}

/**
 * Render the existing artist-specific inline subscription form.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return void
 */
function ec_render_artist_profile_subscribe_section( $artist_profile_id ) {
	$endpoint = rest_url( 'extrachill/v1/artists/' . absint( $artist_profile_id ) . '/subscribe' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes its values.
	echo ec_render_template(
		'subscribe-inline-form',
		array(
			'artist_id'         => $artist_profile_id,
			'artist_name'       => get_the_title( $artist_profile_id ),
			'data'              => ec_get_artist_profile_subscribe_section_data( $artist_profile_id ),
			'context'           => 'profile',
			'subscribe_api_url' => $endpoint,
		)
	);
}
