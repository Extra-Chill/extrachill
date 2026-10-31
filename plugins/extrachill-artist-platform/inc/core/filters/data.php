<?php
/**
 * URL where a user edits an artist's Link Page.
 *
 * The owner-neutral editor on the public Link Page host
 * (extrachill.link/edit?link_page=<id>, extrachill-link-pages#27) when the
 * runtime serves one; otherwise the legacy /manage-link-page/ screen.
 *
 * @param int $artist_id Artist profile ID.
 * @return string URL, or '' when neither is available.
 */
function ec_get_artist_link_page_edit_url( $artist_id ) {
	$link_page_id = (int) ec_get_link_page_for_artist( $artist_id );
	if ( $link_page_id && function_exists( 'ec_link_page_public_base_url' ) && function_exists( 'ec_get_link_page_edit_endpoints' ) ) {
		$base = ec_link_page_public_base_url();
		if ( '' !== $base && '' !== ec_get_link_page_edit_endpoints()['configuration_url'] ) {
			return add_query_arg( 'link_page', $link_page_id, $base . 'edit' );
		}
	}
	$manage_page = get_page_by_path( 'manage-link-page' );
	return $manage_page ? (string) get_permalink( $manage_page ) : '';
}

/**
 * Post status of a Link Page, read on the canonical Link Page storage blog.
 *
 * After the extrachill.link cutover, Link Pages live on the storage blog,
 * so get_post_status() on the artist blog misses every page created after
 * the cutover (Extra-Chill/extrachill-artist-platform#245).
 *
 * @param int $link_page_id Link Page ID.
 * @return string|false Post status, or false when the page does not exist.
 */
function ec_artist_link_page_status( $link_page_id ) {
	$link_page_id = (int) $link_page_id;
	if ( $link_page_id <= 0 ) {
		return false;
	}
	$read = static function () use ( $link_page_id ) {
		return get_post_type( $link_page_id ) === extrachill_artist_platform_link_page_post_type() ? get_post_status( $link_page_id ) : false;
	};
	if ( function_exists( 'ec_with_link_page_storage_blog' ) ) {
		$status = ec_with_link_page_storage_blog( $read );
		return is_wp_error( $status ) ? false : $status;
	}
	return $read();
}

/**
 * Whether a Link Page exists and is published on the storage blog.
 *
 * @param int $link_page_id Link Page ID.
 * @return bool
 */
function ec_artist_link_page_is_published( $link_page_id ) {
	return 'publish' === ec_artist_link_page_status( $link_page_id );
}

/**
 * Where "Manage / Create Link Page" should send the current user.
 *
 * The editor for their most recent artist with a published Link Page, else
 * the /manage-link-page/ screen (which offers to create one).
 *
 * @param int $user_id User ID (defaults to current user).
 * @return string
 */
function ec_get_user_link_page_manage_url( $user_id = 0 ) {
	$user_id        = $user_id ? (int) $user_id : get_current_user_id();
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
	if ( $artist_blog_id && get_current_blog_id() !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		try {
			return ec_get_user_link_page_manage_url( $user_id );
		} finally {
			restore_current_blog();
		}
	}
	if ( $user_id && function_exists( 'ec_get_artists_for_user' ) ) {
		$artist_ids = array_map( 'intval', (array) ec_get_artists_for_user( $user_id ) );
		$latest     = function_exists( 'ec_get_latest_artist_for_user' ) ? (int) ec_get_latest_artist_for_user( $user_id ) : 0;
		if ( $latest && in_array( $latest, $artist_ids, true ) ) {
			array_unshift( $artist_ids, $latest );
		}
		foreach ( array_unique( $artist_ids ) as $artist_id ) {
			if ( ec_artist_link_page_is_published( (int) ec_get_link_page_for_artist( $artist_id ) ) ) {
				$url = ec_get_artist_link_page_edit_url( $artist_id );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
	}
	$site = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : '';
	return ( $site ? untrailingslashit( $site ) : untrailingslashit( home_url() ) ) . '/manage-link-page/';
}

/**
 * Centralized Data Functions
 *
 * Single source of truth for artist profile and link page data.
 * ec_get_link_page_data() provides complete link page data with live preview support.
 */

function ec_is_user_artist_member( $user_id = null, $artist_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id || ! $artist_id ) {
		return false;
	}

	// @phpstan-ignore phpstan.arguments.count (provided by extrachill-users at runtime; outside this component's analysis scope.)
	$user_artist_ids = ec_get_artists_for_user( $user_id );
	return in_array( (int) $artist_id, $user_artist_ids, true );
}

function ec_get_link_page_for_artist( $artist_id ) {
	if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
		return false;
	}

	$query = static function () use ( $artist_id ) {
		$link_pages = get_posts( array(
			'post_type'      => extrachill_artist_platform_link_page_post_type(),
			'meta_key'       => '_associated_artist_profile_id',
			'meta_value'     => (string) $artist_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return ! empty( $link_pages ) ? (int) $link_pages[0] : false;
	};

	// Link Pages live on the storage blog, not necessarily the current blog:
	// this fallback resolver runs from ambient contexts (abilities, editor
	// bootstrap) that don't always switch there themselves.
	if ( function_exists( 'ec_with_link_page_storage_blog' ) ) {
		$result = ec_with_link_page_storage_blog( $query );
		return is_wp_error( $result ) ? false : $result;
	}

	return $query();
}

function ec_get_user_artist_profiles( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return array();
	}

	// @phpstan-ignore phpstan.arguments.count (provided by extrachill-users at runtime; outside this component's analysis scope.)
	$artist_ids = ec_get_artists_for_user( $user_id );
	if ( empty( $artist_ids ) ) {
		return array();
	}

	return get_posts( array(
		'post_type'      => 'artist_profile',
		'post__in'       => $artist_ids,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	) );
}

/**
 * Single source of truth for link page data (CSS vars, links, socials, settings).
 * Supports live preview overrides and comprehensive data validation.
 */
function ec_get_link_page_data( $artist_id, $link_page_id = null, $overrides = array() ) {
	if ( ! $artist_id ) {
		return array();
	}

	if ( ! $link_page_id ) {
		$link_page_id = ec_get_link_page_for_artist( $artist_id );
	}

	if ( ! $link_page_id ) {
		return array();
	}

	return ec_get_external_artist_link_page_data( $artist_id, $link_page_id, $overrides );
}

/** Build the legacy artist response around standalone generic persistence. */
function ec_get_external_artist_link_page_data( $artist_id, $link_page_id, $overrides = array() ) {
	$persistence = ec_read_link_page_persistence( $link_page_id );
	if ( is_wp_error( $persistence ) ) {
		return array();
	}
	$socials   = extrachill_artist_platform_social_links()->get( $artist_id );
	$css_vars  = $persistence['css_vars'];
	$raw_fonts = array(
		'title_font' => $css_vars['--link-page-title-font-family'] ?? '',
		'body_font'  => $css_vars['--link-page-body-font-family'] ?? '',
	);
	if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
		$css_vars = ExtraChillArtistPlatform_Fonts::instance()->process_font_css_vars( $css_vars );
	}
	$settings = array_merge(
		$persistence['settings'],
		array(
			'subscribe_display_mode' => ( get_post_meta( $link_page_id, '_link_page_subscribe_display_mode', true ) ? get_post_meta( $link_page_id, '_link_page_subscribe_display_mode', true ) : 'icon_modal' ),
			'subscribe_description'  => (string) get_post_meta( $link_page_id, '_link_page_subscribe_description', true ),
			'profile_image_id'       => ( get_post_thumbnail_id( $artist_id ) ? get_post_thumbnail_id( $artist_id ) : '' ),
		)
	);
	$data     = array(
		'display_title'                     => ! empty( $overrides['artist_profile_title'] ) ? $overrides['artist_profile_title'] : (string) get_the_title( $artist_id ),
		'bio'                               => ! empty( $overrides['link_page_bio_text'] ) ? $overrides['link_page_bio_text'] : $persistence['bio'],
		'profile_img_url'                   => ! empty( $overrides['profile_img_url'] ) ? $overrides['profile_img_url'] : ( get_the_post_thumbnail_url( $artist_id, 'large' ) ? get_the_post_thumbnail_url( $artist_id, 'large' ) : '' ),
		'social_links'                      => isset( $overrides['social_links'] ) ? $overrides['social_links'] : $socials,
		'socials'                           => $socials,
		'link_sections'                     => $persistence['link_sections'],
		'css_vars'                          => $css_vars,
		'background_type'                   => $css_vars['--link-page-background-type'] ?? 'color',
		'background_style'                  => '',
		'powered_by'                        => true,
		'artist_id'                         => (int) $artist_id,
		'link_page_id'                      => (int) $link_page_id,
		'profile_img_shape'                 => $settings['profile_image_shape'],
		'_link_page_social_icons_position'  => $settings['social_icons_position'],
		'_link_page_subscribe_display_mode' => $settings['subscribe_display_mode'],
		'_link_page_subscribe_description'  => $settings['subscribe_description'],
		'_actual_link_page_id_for_template' => (int) $link_page_id,
		'artist_profile'                    => get_post( $artist_id ),
		'settings'                          => $settings,
		'links'                             => $persistence['links'],
		'raw_font_values'                   => $raw_fonts,
		'background_image_id'               => $persistence['background_image_id'],
		'background_image_url'              => $persistence['background_image_url'],
	);
	if ( isset( $overrides['artist_profile_social_links_json'] ) ) {
		$decoded = json_decode( $overrides['artist_profile_social_links_json'], true );
		if ( is_array( $decoded ) ) {
			$data['social_links'] = $decoded;
			$data['socials']      = $decoded;
		}
	}
	if ( isset( $overrides['link_page_links_json'] ) ) {
		$decoded = json_decode( $overrides['link_page_links_json'], true );
		if ( is_array( $decoded ) ) {
			$data['links']         = $decoded;
			$data['link_sections'] = isset( $decoded[0]['links'] ) || empty( $decoded ) ? $decoded : array(
				array(
					'section_title' => '',
					'links'         => $decoded,
				),
			);
		}
	}
	if ( isset( $overrides['css_vars'] ) ) {
		$data['css_vars'] = is_array( $overrides['css_vars'] ) ? $overrides['css_vars'] : array();
	}
	return apply_filters( 'extrachill_artist_get_link_page_data', $data, $artist_id, $link_page_id, $overrides );
}

/**
 * Single source of truth for artist profile data.
 * Centralizes meta access, normalization, and derived display fields.
 */
function ec_get_artist_profile_data( $artist_id, $overrides = array() ) {
	if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
		return array();
	}

	$meta = get_post_meta( $artist_id );

	// Socials are owned by the artist's Link Page.
	$social_links = extrachill_artist_platform_social_links()->get( $artist_id );

	$header_image_id  = $meta['_artist_profile_header_image_id'][0] ?? '';
	$profile_image_id = ( get_post_thumbnail_id( $artist_id ) ? get_post_thumbnail_id( $artist_id ) : '' );

	$data = array(
		'artist_id'         => (int) $artist_id,
		'title'             => ( get_the_title( $artist_id ) ? get_the_title( $artist_id ) : '' ),
		'slug'              => ( get_post_field( 'post_name', $artist_id ) ? get_post_field( 'post_name', $artist_id ) : '' ),
		'permalink'         => ( get_permalink( $artist_id ) ? get_permalink( $artist_id ) : '' ),
		'bio'               => ( get_post( $artist_id )->post_content ?? '' ),
		'genres'            => ec_artist_get_genres( $artist_id ),
		'genre_labels'      => ec_artist_get_genre_labels( $artist_id ),
		'local_city'        => $meta['_local_city'][0] ?? '',
		'website_url'       => $meta['_website_url'][0] ?? '',
		'spotify_url'       => $meta['_spotify_url'][0] ?? '',
		'apple_music_url'   => $meta['_apple_music_url'][0] ?? '',
		'bandcamp_url'      => $meta['_bandcamp_url'][0] ?? '',
		'social_links'      => $social_links,
		'header_image_id'   => $header_image_id,
		'header_image_url'  => $header_image_id ? wp_get_attachment_url( $header_image_id ) : '',
		'profile_image_id'  => $profile_image_id,
		'profile_image_url' => $profile_image_id ? get_the_post_thumbnail_url( $artist_id, 'large' ) : '',
		'link_page_id'      => function_exists( 'ec_get_link_page_id' ) ? (int) ec_get_link_page_id( $artist_id ) : 0,
	);

	if ( isset( $overrides['title'] ) ) {
		$data['title'] = $overrides['title'];
	}
	if ( isset( $overrides['bio'] ) ) {
		$data['bio'] = $overrides['bio'];
	}
	if ( isset( $overrides['header_image_id'] ) ) {
		$data['header_image_id']  = $overrides['header_image_id'];
		$data['header_image_url'] = $overrides['header_image_id'] ? wp_get_attachment_url( $overrides['header_image_id'] ) : '';
	}
	if ( isset( $overrides['profile_image_id'] ) ) {
		$data['profile_image_id']  = $overrides['profile_image_id'];
		$data['profile_image_url'] = $overrides['profile_image_id'] ? wp_get_attachment_url( $overrides['profile_image_id'] ) : '';
	}
	if ( isset( $overrides['social_links'] ) && is_array( $overrides['social_links'] ) ) {
		$data['social_links'] = $overrides['social_links'];
	}

	$field_overrides = array( 'genres', 'genre_labels', 'local_city', 'website_url', 'spotify_url', 'apple_music_url', 'bandcamp_url' );
	foreach ( $field_overrides as $field_key ) {
		if ( isset( $overrides[ $field_key ] ) ) {
			$data[ $field_key ] = $overrides[ $field_key ];
		}
	}

	return $data;
}

function ec_render_single_link( $link_data, $args = array() ) {
	$template_args = array_merge( $args, $link_data );
	return ec_render_template( 'single-link', $template_args );
}

function ec_render_link_section( $section_data, $args = array() ) {
	$template_args = array_merge( $args, $section_data );
	return ec_render_template( 'link-section', $template_args );
}

function ec_render_social_icon( $social_data, $social_manager = null ) {
	$template_args = array(
		'social_data'    => $social_data,
		'social_manager' => $social_manager,
	);
	return ec_render_template( 'social-icon', $template_args );
}

function ec_render_social_icons_container( $social_links, $position = 'above', $social_manager = null ) {
	$template_args = array(
		'social_links'   => $social_links,
		'position'       => $position,
		'social_manager' => $social_manager,
	);
	return ec_render_template( 'social-icons-container', $template_args );
}
