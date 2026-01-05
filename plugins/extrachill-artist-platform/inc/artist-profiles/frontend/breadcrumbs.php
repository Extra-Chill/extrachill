<?php
/**
 * Artist Profile Breadcrumb Integration
 *
 * Provides breadcrumb trail override for artist profile pages using theme's
 * extensibility filter hook system.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Change breadcrumb root to "Extra Chill › Artist Platform"
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 */
function ec_artist_platform_breadcrumb_root( $root_link ) {
	// On homepage, just "Extra Chill" (trail will add "Artist Platform")
	if ( is_front_page() ) {
		$main_site_url = ec_get_site_url( 'main' );
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	// On other pages, "Extra Chill › Artist Platform"
	$main_site_url = ec_get_site_url( 'main' );
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Artist Platform</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_artist_platform_breadcrumb_root' );

/**
 * Override breadcrumb trail for artist platform homepage
 *
 * Displays just "Artist Platform" (no link) on the homepage to prevent "Archives" suffix.
 * Priority 5 to run before the artist profile breadcrumb trail function.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 */
function ec_artist_platform_breadcrumb_trail_homepage( $custom_trail ) {
	// Only on front page (homepage)
	if ( is_front_page() ) {
		return '<span class="network-dropdown-target">Artist Platform</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_artist_platform_breadcrumb_trail_homepage', 5 );

/**
 * Override breadcrumb trail for artist profile archive
 *
 * Displays "Artists" (no link) on the archive page.
 * Priority 6 to run after homepage but before single artist profile.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 */
function ec_artist_profile_archive_breadcrumb_trail( $custom_trail ) {
	if ( ! is_post_type_archive( 'artist_profile' ) ) {
		return $custom_trail;
	}

	return '<span>Artists</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_artist_profile_archive_breadcrumb_trail', 6 );

/**
 * Override breadcrumb trail for artist profiles
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Empty string
 */
function ec_artist_profile_breadcrumb_override( $custom_trail ) {
    if ( ! is_singular( 'artist_profile' ) ) {
        return $custom_trail;
    }

    return '';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_artist_profile_breadcrumb_override' );

/**
 * Override back-to-home link label for artist platform pages
 *
 * Changes "Back to Extra Chill" to "Back to Artist Platform" on artist pages.
 * Uses theme's extrachill_back_to_home_label filter.
 *
 * @param string $label Default back-to-home link label
 * @param string $url   Back-to-home link URL
 * @return string Modified label
 */
function ec_artist_platform_back_to_home_label( $label, $url ) {
    // Don't override on homepage (homepage should say "Back to Extra Chill")
    if ( is_front_page() ) {
        return $label;
    }

    return '← Back to Artist Platform';
}
add_filter( 'extrachill_back_to_home_label', 'ec_artist_platform_back_to_home_label', 10, 2 );

/**
 * Override schema breadcrumb items for artist platform site
 *
 * Aligns schema breadcrumbs with visual breadcrumbs for artist.extrachill.com.
 * Only applies on blog ID 4 (artist.extrachill.com).
 *
 * Output patterns:
 * - Homepage: [Extra Chill, Artist Platform]
 * - Artist archive: [Extra Chill, Artist Platform, Artists]
 * - Single artist: [Extra Chill, Artist Platform, Artist Name]
 *
 * @hook extrachill_seo_breadcrumb_items
 * @param array $items Default breadcrumb items from SEO plugin
 * @return array Modified breadcrumb items for artist platform context
 * @since 1.1.0
 */
function ec_artist_platform_schema_breadcrumb_items( $items ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id || get_current_blog_id() !== $artist_blog_id ) {
		return $items;
	}

	$main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : 'https://extrachill.com';

	// Homepage: Extra Chill → Artist Platform
	if ( is_front_page() ) {
		return array(
			array(
				'name' => 'Extra Chill',
				'url'  => $main_site_url,
			),
			array(
				'name' => 'Artist Platform',
				'url'  => '',
			),
		);
	}

	// Artist archive: Extra Chill → Artist Platform → Artists
	if ( is_post_type_archive( 'artist_profile' ) ) {
		return array(
			array(
				'name' => 'Extra Chill',
				'url'  => $main_site_url,
			),
			array(
				'name' => 'Artist Platform',
				'url'  => home_url( '/' ),
			),
			array(
				'name' => 'Artists',
				'url'  => '',
			),
		);
	}

	// Single artist profile: Extra Chill → Artist Platform → Artist Name
	if ( is_singular( 'artist_profile' ) ) {
		return array(
			array(
				'name' => 'Extra Chill',
				'url'  => $main_site_url,
			),
			array(
				'name' => 'Artist Platform',
				'url'  => home_url( '/' ),
			),
			array(
				'name' => get_the_title(),
				'url'  => '',
			),
		);
	}

	return $items;
}
add_filter( 'extrachill_seo_breadcrumb_items', 'ec_artist_platform_schema_breadcrumb_items' );
