<?php
/**
 * Artist Grid Display Functions
 * 
 * Handles artist profile grid display with activity-based sorting,
 * user exclusion logic, and responsive grid layouts.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Get the timestamp of the last activity related to an Artist Profile.
 * Considers the profile's last modified date and link page activity.
 *
 * @param int $artist_profile_id The ID of the artist_profile CPT.
 * @return int|false Unix timestamp (UTC/GMT) of the latest activity, or false on error.
 */
function ec_get_artist_profile_last_activity_timestamp( $artist_profile_id ) {
    $artist_profile_id = absint( $artist_profile_id );
    if ( ! $artist_profile_id || get_post_type( $artist_profile_id ) !== 'artist_profile' ) {
        return false;
    }

    // Get profile's last modified timestamp (GMT)
    $profile_modified_gmt = get_post_modified_time( 'U', true, $artist_profile_id );
    if ( ! $profile_modified_gmt ) {
        $profile_modified_gmt = get_post_time( 'U', true, $artist_profile_id );
    }
    
    $latest_activity_timestamp = $profile_modified_gmt ?: 0;

    // Check link page activity
    $link_page_id = apply_filters( 'ec_get_link_page_id', $artist_profile_id );
    if ( $link_page_id ) {
        $link_page_modified = get_post_modified_time( 'U', true, $link_page_id );
        if ( $link_page_modified && $link_page_modified > $latest_activity_timestamp ) {
            $latest_activity_timestamp = $link_page_modified;
        }
    }

    return $latest_activity_timestamp > 0 ? $latest_activity_timestamp : false;
}

/**
 * Displays a grid of artist profile cards sorted by most recent activity.
 *
 * @param int  $limit               Number of artists to display per page (default: 24)
 * @param bool $exclude_user_artists Whether to exclude current user's owned artists (default: false)
 * @param bool $show_pagination     Whether to display pagination below the grid (default: true)
 */
function ec_display_artist_cards_grid( $limit = 24, $exclude_user_artists = false, $show_pagination = true ) {
    // Get current page from query var
    $current_page = max( 1, get_query_var( 'paged', 1 ) );

    // Get all published artist profiles for sorting (only IDs for efficiency)
    $args = array(
        'post_type'              => 'artist_profile',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    );

    // Exclude current user's owned artists if requested
    if ( $exclude_user_artists && is_user_logged_in() ) {
        $user_artist_ids = ec_get_artists_for_user( get_current_user_id() );
        if ( ! empty( $user_artist_ids ) ) {
            $args['post__not_in'] = $user_artist_ids;
        }
    }

    $artist_ids = get_posts( $args );

    if ( empty( $artist_ids ) ) {
        echo '<div class="no-artists-found">';
        echo '<p>' . esc_html__( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ) . '</p>';
        if ( is_user_logged_in() && ec_can_create_artist_profiles( get_current_user_id() ) ) {
            echo '<p><a href="' . esc_url( home_url( '/create-artist/' ) ) . '" class="button">';
            echo esc_html__( 'Create the First Artist Profile', 'extrachill-artist-platform' );
            echo '</a></p>';
        }
        echo '</div>';
        return;
    }

    // Create array with activity timestamps for sorting
    $artists_with_activity = array();
    foreach ( $artist_ids as $artist_profile_id ) {
        $activity_timestamp      = ec_get_artist_profile_last_activity_timestamp( $artist_profile_id );
        $artists_with_activity[] = array(
            'id'       => $artist_profile_id,
            'activity' => $activity_timestamp ?: 0,
        );
    }

    // Sort by activity (most recent first)
    usort( $artists_with_activity, function( $a, $b ) {
        return $b['activity'] - $a['activity'];
    });

    // Extract sorted IDs
    $sorted_artist_ids = wp_list_pluck( $artists_with_activity, 'id' );

    // Calculate pagination
    $total_artists = count( $sorted_artist_ids );
    $total_pages   = ceil( $total_artists / $limit );
    $current_page  = max( 1, min( $current_page, $total_pages ) );
    $offset        = ( $current_page - 1 ) * $limit;

    // Get artists for current page
    $paged_artist_ids = array_slice( $sorted_artist_ids, $offset, $limit );

    // Create WP_Query for proper pagination support
    $query_args = array(
        'post_type'      => 'artist_profile',
        'post_status'    => 'publish',
        'post__in'       => $paged_artist_ids,
        'orderby'        => 'post__in', // Preserve our custom sorting
        'posts_per_page' => count( $paged_artist_ids ),
        'paged'          => $current_page,
    );

    $artists_query = new WP_Query( $query_args );

    echo '<div class="artist-directory-grid">';

    echo '<div class="artist-cards-grid">';
    if ( $artists_query->have_posts() ) {
        while ( $artists_query->have_posts() ) {
            $artists_query->the_post();
            include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/artist-card.php';
        }
        wp_reset_postdata();
    }
    echo '</div>';

    if ( $show_pagination && $total_pages > 1 ) {
        $pagination_query                                = new WP_Query();
        $pagination_query->max_num_pages                 = $total_pages;
        $pagination_query->found_posts                   = $total_artists;
        $pagination_query->query_vars['posts_per_page']  = $limit;

        echo '<div class="artist-grid-pagination">';
        extrachill_pagination( $pagination_query, 'artist-archive', 'artist' );
        echo '</div>';
    }

    echo '</div>';

}
