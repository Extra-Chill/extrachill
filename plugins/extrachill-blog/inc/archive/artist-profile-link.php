<?php
/**
 * Artist Profile Link Integration
 *
 * Displays navigation button on artist taxonomy archives linking to matching profiles on artist.extrachill.com.
 *
 * @package ExtraChillBlog
 * @since 0.3.1
 */

/**
 * Display artist profile button on artist taxonomy archives.
 */
function extrachill_display_artist_profile_button() {
	if ( ! is_tax( 'artist' ) ) {
		return;
	}

	if ( ! function_exists( 'ec_get_artist_profile_by_slug' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) || empty( $term->slug ) ) {
		return;
	}

	$artist_profile = ec_get_artist_profile_by_slug( $term->slug );
	if ( ! $artist_profile || empty( $artist_profile['permalink'] ) ) {
		return;
	}

	echo '<div class="artist-profile-link-container">';
	echo '<a href="' . esc_url( $artist_profile['permalink'] ) . '" class="button-2 button-medium" rel="noopener">';
	echo esc_html__( 'View Artist Profile', 'extrachill-blog' );
	echo '</a>';
	echo '</div>';
}
add_action( 'extrachill_archive_header_actions', 'extrachill_display_artist_profile_button' );
