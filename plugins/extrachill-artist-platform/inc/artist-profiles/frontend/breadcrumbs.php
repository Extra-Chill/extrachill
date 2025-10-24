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
 * Change breadcrumb root to "Artist Platform"
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 */
function ec_artist_platform_breadcrumb_root( $root_link ) {
	return '<a href="' . esc_url( home_url() ) . '">Artist Platform</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_artist_platform_breadcrumb_root' );

/**
 * Override breadcrumb trail for artist profiles
 *
 * Hooks into theme's breadcrumb system to provide proper breadcrumb trail:
 * Artist Platform › Artist Name
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Modified breadcrumb trail HTML
 */
function ec_artist_profile_breadcrumb_override( $custom_trail ) {
    // Only override on artist profile singles
    if ( ! is_singular( 'artist_profile' ) ) {
        return $custom_trail;
    }

    $artist_name = get_the_title();

    return '<span>' . esc_html( $artist_name ) . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_artist_profile_breadcrumb_override' );
