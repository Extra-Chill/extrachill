<?php
/**
 * Artist projection and compatibility adapter for the standalone public runtime.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return an artist projection for a supported Link Page owner.
 *
 * @param array $context Public runtime context.
 * @return array|null
 */
function ec_artist_link_page_public_projection_provider( $context ) {
	$owner          = $context['owner'];
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	if ( 'post' !== $owner['kind'] || $artist_blog_id !== (int) $owner['blog_id'] || 'artist_profile' !== $owner['subtype'] ) {
		return null;
	}

	$artist_id = (int) $owner['object_id'];
	$artist    = get_post( $artist_id );
	if ( ! $artist || 'artist_profile' !== $artist->post_type || 'publish' !== $artist->post_status ) {
		return new WP_Error( 'link_page_public_owner_unavailable', 'The Link Page public owner is unavailable.', array( 'status' => 404 ) );
	}
	$link_page_id    = (int) $context['link_page_id'];
	$data            = ec_get_link_page_data( $artist_id, $link_page_id );
	$artist_site_url = ec_get_site_url( 'artist' );
	$api_base        = $artist_site_url . '/wp-json/extrachill/v1';
	$seo             = extrachill_artist_link_page_seo_context( $artist_id, $link_page_id );

	return array(
		'display_title'         => (string) $data['display_title'],
		'bio'                   => (string) $data['bio'],
		'profile_img_url'       => (string) $data['profile_img_url'],
		'social_links'          => $data['social_links'],
		'social_renderer'       => 'ec_artist_link_page_render_socials',
		'management_url'        => $artist_site_url . '/manage-link-page/?artist_id=' . $artist_id,
		'body_attributes'       => array(
			'data-extrch-artist-id'           => (string) $artist_id,
			'data-extrch-permissions-api-url' => $api_base . '/artists/' . $artist_id . '/permissions',
			'data-extrch-token-handoff-url'   => $artist_site_url . '/wp-admin/admin-post.php?action=ec_link_token_handoff',
			'data-extrch-subscribe-api-url'   => $api_base . '/artists/' . $artist_id . '/subscribe',
		),
		'seo'                   => $seo,
		'tracking_url'          => $api_base . '/analytics/click',
		'css_vars'              => $data['css_vars'],
		'components'            => array(
			'head'           => array( 'ec_artist_link_page_render_tracking_head' ),
			'body_start'     => array( 'ec_artist_link_page_render_tracking_body' ),
			'header_actions' => 'icon_modal' === $data['_link_page_subscribe_display_mode'] ? array( 'ec_artist_link_page_render_subscribe_trigger' ) : array(),
			'after_links'    => 'disabled' === $data['_link_page_subscribe_display_mode'] ? array() : array( 'ec_artist_link_page_render_subscription' ),
		),
		'assets'                => 'ec_artist_link_page_enqueue_asset_extensions',
		'legacy_head_arguments' => array( $artist_id ),
	);
}

/** Preserve the historical public query variable. */
function ec_artist_link_page_public_query_var() {
	return 'artist_link_page';
}

/**
 * Preserve historical Artist public query variables.
 *
 * @param string[] $vars Public query variables.
 * @return string[]
 */
function ec_artist_link_page_public_query_vars( $vars ) {
	return array_values( array_unique( array_merge( $vars, array( 'artist_link_page', 'dev_view_link_page', 'artist_id' ) ) ) );
}

/**
 * Preserve historical Artist route exclusions.
 *
 * @param string[] $excluded Public route exclusions.
 * @return string[]
 */
function ec_artist_link_page_public_exclusions( $excluded ) {
	return array_values( array_unique( array_merge( $excluded, array( 'manage-artist', 'manage-link-page', 'join' ) ) ) );
}

/**
 * Project the historical join redirect without teaching generic routing its policy.
 *
 * @param mixed  $route Existing special route projection.
 * @param string $path  Requested public path.
 * @return mixed
 */
function ec_artist_link_page_public_special_route( $route, $path ) {
	if ( 'join' !== $path ) {
		return $route;
	}
	return array(
		'url'    => ec_get_site_url( 'artist' ) . '/login/?from_join=true',
		'status' => 301,
		'safe'   => false,
	);
}

/**
 * Render artist socials through the retained Artist Platform template contract.
 *
 * @param array  $social_links Artist social projections.
 * @param string $position     Social icon position.
 * @return string
 */
function ec_artist_link_page_render_socials( $social_links, $position ) {
	return ec_render_social_icons_container( $social_links, $position );
}

/** Render the historical subscription bell in the header action slot. */
function ec_artist_link_page_render_subscribe_trigger() {
	return '<button class="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger" aria-label="Subscribe to this artist"><i class="fas fa-bell"></i></button>';
}

/**
 * Render the configured artist subscription component.
 *
 * @param array $context    Public runtime context.
 * @param array $projection Artist public projection.
 * @return string
 */
function ec_artist_link_page_render_subscription( $context, $projection ) {
	$artist_id = (int) $context['owner']['object_id'];
	$data      = ec_get_link_page_data( $artist_id, (int) $context['link_page_id'] );
	$template  = 'inline_form' === $data['_link_page_subscribe_display_mode'] ? 'subscribe-inline-form' : 'subscribe-modal';
	return ec_render_template(
		$template,
		array(
			'artist_id'         => $artist_id,
			'data'              => $data,
			'subscribe_api_url' => $projection['body_attributes']['data-extrch-subscribe-api-url'],
		)
	);
}

/**
 * Enqueue only artist-owned public asset extensions.
 *
 * @param array $context Public runtime context.
 * @return true
 */
function ec_artist_link_page_enqueue_asset_extensions( $context ) {
	foreach ( array(
		'extrch-subscribe'   => 'inc/link-pages/live/assets/js/link-page-subscribe.js',
		'extrch-edit-button' => 'inc/link-pages/live/assets/js/link-page-edit-button.js',
	) as $handle => $path ) {
		$file = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $path;
		if ( file_exists( $file ) ) {
			wp_enqueue_script( $handle, EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . $path, array(), filemtime( $file ), true );
		}
	}
	if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
		$data    = ec_get_link_page_data( (int) $context['owner']['object_id'], (int) $context['link_page_id'] );
		$values  = array_filter( array( $data['raw_font_values']['title_font'] ?? '', $data['raw_font_values']['body_font'] ?? '' ) );
		$manager = ExtraChillArtistPlatform_Fonts::instance();
		$url     = $manager->get_google_fonts_url( $values );
		$css     = $manager->get_local_fonts_css( $values );
		if ( $url ) {
			wp_enqueue_style( 'extrch-link-page-artist-fonts', $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URLs are immutable for their family query.
		}
		if ( $css ) {
			wp_add_inline_style( 'extrch-link-page', $css );
		}
	}
	return true;
}

/** Render the network-wide GTM head snippet retained by artist pages. */
function ec_artist_link_page_render_tracking_head() {
	return '<!-- Google Tag Manager --><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\'script\',\'dataLayer\',\'GTM-NXKDLFD\');</script><!-- End Google Tag Manager -->';
}

/** Render the network-wide GTM body fallback retained by artist pages. */
function ec_artist_link_page_render_tracking_body() {
	return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
}

/**
 * Build artist-owned SEO and schema projection data.
 *
 * @param int $artist_id    Artist profile ID.
 * @param int $link_page_id Link Page ID.
 * @return array
 */
function extrachill_artist_link_page_seo_context( $artist_id, $link_page_id ) {
	$artist = get_post( $artist_id );
	if ( ! $artist ) {
		return array();
	}
	$artist_data = ec_get_artist_profile_data( $artist_id );
	$canonical   = ec_get_link_page_public_url( $link_page_id );
	$description = wp_strip_all_tags( $artist->post_excerpt ? $artist->post_excerpt : $artist->post_content );
	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$space       = strrpos( $description, ' ' );
		$description = ( false === $space ? $description : substr( $description, 0, $space ) ) . '...';
	}
	if ( '' === $description ) {
		$description = sprintf( '%s — all important links in one place on Extra Chill.', $artist->post_title );
	}
	$profile_url = get_permalink( $artist_id );
	$music_group = array(
		'@type' => 'MusicGroup',
		'@id'   => $profile_url . '#musicgroup',
		'name'  => $artist->post_title,
		'url'   => $profile_url,
	);
	foreach ( array(
		'bio'               => 'description',
		'genre'             => 'genre',
		'profile_image_url' => 'image',
	) as $source => $target ) {
		if ( ! empty( $artist_data[ $source ] ) ) {
			$music_group[ $target ] = 'bio' === $source ? wp_strip_all_tags( $artist_data[ $source ] ) : $artist_data[ $source ];
		}
	}
	$same_as = array_filter( array( $artist_data['website_url'] ?? '', $artist_data['spotify_url'] ?? '', $artist_data['apple_music_url'] ?? '', $artist_data['bandcamp_url'] ?? '' ) );
	foreach ( $artist_data['social_links'] ?? array() as $social ) {
		if ( ! empty( $social['url'] ) ) {
			$same_as[] = $social['url'];
		}
	}
	if ( $same_as ) {
		$music_group['sameAs'] = array_values( array_unique( $same_as ) );
	}
	return array(
		'title'       => $artist->post_title . ' | extrachill.link',
		'description' => $description,
		'canonical'   => $canonical,
		'image'       => $artist_data['profile_image_url'] ?? '',
		'image_alt'   => ! empty( $artist_data['profile_image_id'] ) ? ( get_post_meta( $artist_data['profile_image_id'], '_wp_attachment_image_alt', true ) ? get_post_meta( $artist_data['profile_image_id'], '_wp_attachment_image_alt', true ) : $artist->post_title ) : $artist->post_title,
		'og_type'     => 'profile',
		'schema'      => array(
			$music_group,
			array(
				'@type'      => 'ProfilePage',
				'@id'        => $canonical . '#profilepage',
				'url'        => $canonical,
				'name'       => $artist->post_title,
				'mainEntity' => array( '@id' => $profile_url . '#musicgroup' ),
			),
		),
	);
}

/** Preserve legacy defaults globals while standalone owns their implementation. */
function ec_get_link_page_defaults() {
	return ec_link_page_defaults();
}

/**
 * Return one legacy defaults category from standalone storage.
 *
 * @param string $category Defaults category.
 * @return array
 */
function ec_get_link_page_defaults_for( $category ) {
	return ec_link_page_defaults_for( $category );
}

/**
 * Return one legacy default value from standalone storage.
 *
 * @param string $category Defaults category.
 * @param string $key      Defaults key.
 * @param mixed  $fallback Fallback value.
 * @return mixed
 */
function ec_get_link_page_default( $category, $key, $fallback = null ) {
	return ec_link_page_default( $category, $key, $fallback );
}

/**
 * Preserve the historical head function for direct external consumers.
 *
 * @param int $artist_id    Artist profile ID.
 * @param int $link_page_id Link Page ID.
 * @return void
 */
function extrachill_artist_link_page_custom_head( $artist_id, $link_page_id ) {
	$projection = ec_get_link_page_public_projection( $link_page_id );
	$data       = ec_read_link_page_persistence( $link_page_id );
	if ( ! is_wp_error( $projection ) && ! is_wp_error( $data ) ) {
		$projection = ec_prepare_link_page_public_render( $projection, $data );
	}
	if ( ! is_wp_error( $projection ) && ! is_wp_error( $data ) ) {
		ec_render_link_page_public_head( $link_page_id, $data, $projection );
	}
}
