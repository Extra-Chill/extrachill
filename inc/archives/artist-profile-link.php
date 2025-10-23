<?php
/**
 * Artist Profile Link Integration
 *
 * Connects artist taxonomy archives with artist profile pages on artist.extrachill.com
 * by detecting slug matches and displaying navigation buttons.
 *
 * @package ExtraChill
 * @since 69.59
 */

/**
 * Query artist.extrachill.com for matching artist profile by slug
 *
 * @param string $slug Artist slug to search for
 * @return array|false Array with 'id' and 'permalink' if found, false otherwise
 */
function extrachill_get_artist_profile_by_slug( $slug ) {
    if ( empty( $slug ) ) {
        return false;
    }

    switch_to_blog( 4 );

    try {
        $args = array(
            'post_type'      => 'artist_profile',
            'post_status'    => 'publish',
            'name'           => $slug,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        $posts = get_posts( $args );

        if ( ! empty( $posts ) ) {
            $post_id = $posts[0];
            $permalink = get_permalink( $post_id );

            return array(
                'id'        => $post_id,
                'permalink' => $permalink
            );
        }

        return false;

    } finally {
        restore_current_blog();
    }
}

/**
 * Display artist profile button on artist taxonomy archives
 *
 * Hooked into extrachill_archive_filter_bar action.
 */
function extrachill_display_artist_profile_button() {
    if ( ! is_tax( 'artist' ) ) {
        return;
    }

    $term = get_queried_object();
    if ( ! $term || empty( $term->slug ) ) {
        return;
    }

    $artist_profile = extrachill_get_artist_profile_by_slug( $term->slug );

    if ( ! $artist_profile ) {
        return;
    }

    ?>
    <div class="artist-profile-link-container" style="float: right; margin-left: 1em;">
        <a href="<?php echo esc_url( $artist_profile['permalink'] ); ?>"
           class="button-2 button-medium"
           rel="noopener">
            View Artist Profile
        </a>
    </div>
    <?php
}
add_action( 'extrachill_archive_filter_bar', 'extrachill_display_artist_profile_button' );
