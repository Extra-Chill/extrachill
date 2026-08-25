<?php
/** Artist Platform embedding adapter for the portable Link Page editor. */
defined( 'ABSPATH' ) || exit;

add_filter( 'ec_link_page_editor_configuration', 'extrachill_artist_link_page_editor_configuration', 10, 3 );

/**
 * Supply only presentation context; canonical APIs reauthorize every operation.
 *
 * @param mixed $configuration Existing configuration.
 * @return mixed
 */
function extrachill_artist_link_page_editor_configuration( $configuration ) {
	if ( ! is_user_logged_in() || ! function_exists( 'ec_get_artists_for_user' ) ) {
		return $configuration;
	}
	$identities = array();
	foreach ( ec_get_artists_for_user( get_current_user_id(), true ) as $artist_id ) {
		$post         = get_post( $artist_id );
		$link_page_id = function_exists( 'ec_get_link_page_for_artist' ) ? ec_get_link_page_for_artist( $artist_id ) : 0;
		if ( ! $post || 'publish' !== $post->post_status || ! $link_page_id || 'publish' !== get_post_status( $link_page_id ) ) {
			continue;
		}
		$identities[] = array(
			'id'        => (int) $artist_id,
			'label'     => $post->post_title,
			'publicUrl' => function_exists( 'ec_get_link_page_public_url' ) ? ec_get_link_page_public_url( $link_page_id ) : 'https://extrachill.link/' . $post->post_name,
		);
	}
	if ( ! $identities || ! function_exists( 'ec_enqueue_link_page_editor' ) || ! ec_enqueue_link_page_editor() ) {
		return $configuration;
	}
	$asset_file = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/link-page-editor/adapter.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return $configuration;
	}
	$asset = include $asset_file;
	wp_enqueue_script(
		'extrachill-artist-link-page-editor-adapter',
		EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/link-page-editor/adapter.js',
		array_merge( $asset['dependencies'], array( 'extrachill-link-page-editor-view-script' ) ),
		$asset['version'],
		true
	);
	$initial = $identities[0]['id'];
	$fonts   = class_exists( 'ExtraChillArtistPlatform_Fonts' ) ? ExtraChillArtistPlatform_Fonts::instance()->get_supported_fonts() : array();
	$local_fonts_css = class_exists( 'ExtraChillArtistPlatform_Fonts' ) ? ExtraChillArtistPlatform_Fonts::instance()->get_local_fonts_css(
		array(
			ExtraChillArtistPlatform_Fonts::DEFAULT_TITLE_FONT,
			ExtraChillArtistPlatform_Fonts::DEFAULT_BODY_FONT,
		)
	) : '';
	$social_types = array();
	if ( function_exists( 'extrachill_artist_platform_social_links' ) ) {
		foreach ( extrachill_artist_platform_social_links()->get_supported_types() as $type => $details ) {
			$social_types[] = array(
				'id'         => $type,
				'label'      => $details['label'],
				'icon_class' => $details['icon'] ?? '',
			);
		}
	}
	if ( function_exists( 'ec_get_latest_artist_for_user' ) ) {
		$latest = (int) ec_get_latest_artist_for_user( get_current_user_id() );
		if ( in_array( $latest, array_column( $identities, 'id' ), true ) ) {
			$initial = $latest;
		}
	}
	return array(
		'adapter'         => 'extrachill-artist-platform',
		'identities'      => $identities,
		'initialIdentity' => $initial,
		'managementUrl'   => site_url( '/manage-link-page/' ),
		'fonts'           => $fonts,
		'localFontsCss'   => $local_fonts_css,
		'socialTypes'     => $social_types,
		'limits'          => array(
			'sections'          => 10,
			'linksPerSection'    => 25,
			'sectionTitleLength' => 200,
			'linkTextLength'     => 200,
			'urlLength'          => 2048,
			'bioLength'          => 5000,
			'displayNameLength'  => 200,
		),
	);
}
