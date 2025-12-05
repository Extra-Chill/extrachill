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
 * Change breadcrumb root to "Extra Chill → Community → Artist Platform"
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 */
function ec_artist_platform_breadcrumb_root( $root_link ) {
	// On homepage, "Extra Chill → Community" (trail will add "Artist Platform")
	if ( is_front_page() ) {
		return '<a href="https://extrachill.com">Extra Chill</a> › <a href="https://community.extrachill.com">Community</a>';
	}

	// On other pages, "Extra Chill → Community → Artist Platform"
	return '<a href="https://extrachill.com">Extra Chill</a> › <a href="https://community.extrachill.com">Community</a> › <a href="' . esc_url( home_url() ) . '">Artist Platform</a>';
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
		return '<span>Artist Platform</span>';
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
 * Override breadcrumb trail for manage artist profiles page
 *
 * Displays artist name with edit context, or create mode text.
 * Priority 8 to run after archive breadcrumb.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 */
function ec_manage_artist_profiles_breadcrumb_trail( $custom_trail ) {
	if ( ! is_page( 'manage-artist-profiles' ) ) {
		return $custom_trail;
	}

	$artist_id = apply_filters( 'ec_get_artist_id', $_GET );

	// Create mode - no artist selected
	if ( ! $artist_id ) {
		return '<span>Create Artist Profile</span>';
	}

	// Edit mode - show artist name linked to profile, then edit indicator
	$artist_post = get_post( $artist_id );
	if ( ! $artist_post ) {
		return $custom_trail;
	}

	return '<a href="' . esc_url( get_permalink( $artist_id ) ) . '">' . esc_html( $artist_post->post_title ) . '</a> › <span>Edit Profile</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_manage_artist_profiles_breadcrumb_trail', 8 );

/**
 * Override breadcrumb trail for manage link page
 *
 * Displays artist name linked to profile management, then link page indicator.
 * Priority 9 to run after manage artist profiles breadcrumb.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 */
function ec_manage_link_page_breadcrumb_trail( $custom_trail ) {
	if ( ! is_page( 'manage-link-page' ) ) {
		return $custom_trail;
	}

	$artist_id = apply_filters( 'ec_get_artist_id', $_GET );
	if ( ! $artist_id ) {
		return $custom_trail;
	}

	$artist_post = get_post( $artist_id );
	if ( ! $artist_post ) {
		return $custom_trail;
	}

	$manage_page = get_page_by_path( 'manage-artist-profiles' );
	$manage_artist_profile_url = $manage_page
		? add_query_arg( 'artist_id', $artist_id, get_permalink( $manage_page ) )
		: site_url( '/manage-artist-profiles/?artist_id=' . $artist_id );

	return '<a href="' . esc_url( $manage_artist_profile_url ) . '">' . esc_html( $artist_post->post_title ) . '</a> › <span>Manage Link Page</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_manage_link_page_breadcrumb_trail', 9 );

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
