<?php
/**
 * Blog Coverage Integration
 *
 * Connects artist profiles with artist taxonomy archives on extrachill.com
 * by detecting slug matches and providing navigation between the two.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query extrachill.com for matching artist taxonomy term by slug
 *
 * @param string $slug Artist slug to search for
 * @return array|false Array with 'id' and 'archive_link' if found, false otherwise
 */
function extrachill_artist_get_taxonomy_by_slug( $slug ) {
    if ( empty( $slug ) ) {
        return false;
    }

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return false;
	}

	switch_to_blog( $main_blog_id );

	try {
		$term = get_term_by( 'slug', $slug, 'artist' );


        if ( $term && ! is_wp_error( $term ) ) {
            $archive_link = get_term_link( $term );

            if ( ! is_wp_error( $archive_link ) ) {
                return array(
                    'id'           => $term->term_id,
                    'archive_link' => $archive_link,
                    'name'         => $term->name
                );
            }
        }

        return false;

    } finally {
        restore_current_blog();
    }
}

/**
 * Display blog coverage button on artist profile pages
 *
 * Should be called directly in the artist profile template.
 *
 * @param string $artist_slug Artist profile post name/slug
 */
function extrachill_artist_display_blog_coverage_button( $artist_slug ) {
    if ( empty( $artist_slug ) ) {
        return;
    }

    $taxonomy_data = extrachill_artist_get_taxonomy_by_slug( $artist_slug );

    if ( ! $taxonomy_data ) {
        return;
    }

    ?>
    <div class="artist-blog-coverage-link" style="margin: 1.5em 0;">
        <a href="<?php echo esc_url( $taxonomy_data['archive_link'] ); ?>"
           class="button-2 button-medium"
           target="_blank"
           rel="noopener noreferrer">
            View Blog Coverage
        </a>
    </div>
    <?php
}
