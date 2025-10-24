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
 * Considers the profile's last modified date, link page activity, and forum activity.
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
        $profile_modified_gmt = get_post_time( 'U', true, $artist_profile_id ); // Fallback to creation time
    }
    
    $latest_activity_timestamp = $profile_modified_gmt ?: 0; // Initialize with profile time

    // Check link page activity
    $link_page_id = apply_filters('ec_get_link_page_id', $artist_profile_id);
    if ( $link_page_id ) {
        $link_page_modified = get_post_modified_time( 'U', true, $link_page_id );
        if ( $link_page_modified && $link_page_modified > $latest_activity_timestamp ) {
            $latest_activity_timestamp = $link_page_modified;
        }
    }

    // Get the associated forum ID
    $forum_id = get_post_meta( $artist_profile_id, '_artist_forum_id', true );
    $forum_id = absint( $forum_id );

    if ( $forum_id > 0 && function_exists( 'bbp_get_topic_post_type' ) && function_exists( 'bbp_get_reply_post_type' ) ) {
        // Query for the latest topic or reply in this specific forum
        $latest_post_args = array(
            'post_type'      => array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ),
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'post_status'    => array( 'publish', 'closed' ),
            'meta_query'     => array(
                array(
                    'key'     => '_bbp_forum_id',
                    'value'   => $forum_id,
                    'compare' => '=',
                ),
            ),
             'no_found_rows' => true,
             'update_post_term_cache' => false,
             'update_post_meta_cache' => false,
        );

        $latest_posts = get_posts( $latest_post_args );

        if ( ! empty( $latest_posts ) ) {
            $latest_post_id = $latest_posts[0];
            $latest_post_gmt = get_post_time( 'U', true, $latest_post_id );
            if ( $latest_post_gmt && $latest_post_gmt > $latest_activity_timestamp ) {
                $latest_activity_timestamp = $latest_post_gmt;
            }
        }
    }

    return $latest_activity_timestamp > 0 ? $latest_activity_timestamp : false;
}

/**
 * Displays a grid of artist profile cards sorted by most recent activity.
 *
 * @param int $limit Number of artists to display per page (default: 12)
 * @param bool $exclude_user_artists Whether to exclude current user's owned artists (default: false)
 * @param int $page Current page number for pagination (default: 1)
 * @param bool $return_data Whether to return data array instead of echoing (default: false)
 * @return array|void Returns array with HTML and pagination data if $return_data is true, otherwise echoes output
 */
function ec_display_artist_cards_grid( $limit = 12, $exclude_user_artists = false, $page = 1, $return_data = false ) {
    // Get all published artist profiles
    $args = array(
        'post_type'      => 'artist_profile',
        'post_status'    => 'publish',
        'posts_per_page' => -1, // Get all, we'll sort and limit after
        'fields'         => 'ids',
        'no_found_rows'  => true,
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
        $no_results_html = '<div class="no-artists-found">';
        $no_results_html .= '<p>' . __( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ) . '</p>';
        if ( is_user_logged_in() && ec_can_create_artist_profiles( get_current_user_id() ) ) {
            $no_results_html .= '<p><a href="' . esc_url( home_url( '/manage-artist-profiles/' ) ) . '" class="button">';
            $no_results_html .= __( 'Create the First Artist Profile', 'extrachill-artist-platform' );
            $no_results_html .= '</a></p>';
        }
        $no_results_html .= '</div>';

        if ( $return_data ) {
            return array(
                'html' => $no_results_html,
                'pagination_html' => '',
                'current_page' => 1,
                'total_pages' => 0,
                'total_artists' => 0
            );
        }

        echo $no_results_html;
        return;
    }

    // Create array with activity timestamps for sorting
    $artists_with_activity = array();
    foreach ( $artist_ids as $artist_id ) {
        $activity_timestamp = ec_get_artist_profile_last_activity_timestamp( $artist_id );
        $artists_with_activity[] = array(
            'id' => $artist_id,
            'activity' => $activity_timestamp ?: 0
        );
    }

    // Sort by activity (most recent first)
    usort( $artists_with_activity, function( $a, $b ) {
        return $b['activity'] - $a['activity'];
    });

    // Calculate pagination
    $total_artists = count( $artists_with_activity );
    $total_pages = ceil( $total_artists / $limit );
    $current_page = max( 1, min( $page, $total_pages ) );
    $offset = ( $current_page - 1 ) * $limit;

    // Get artists for current page
    $paged_artists = array_slice( $artists_with_activity, $offset, $limit );

    // Build artist cards HTML
    ob_start();
    echo '<div class="artist-cards-grid">';

    foreach ( $paged_artists as $artist_data ) {
        $artist_id = $artist_data['id'];
        $artist_post = get_post( $artist_id );

        if ( $artist_post ) {
            global $post;
            $post = $artist_post;
            setup_postdata( $post );
            include( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/artist-card.php' );
            wp_reset_postdata();
        }
    }

    echo '</div>'; // .artist-cards-grid
    $artist_cards_html = ob_get_clean();

    // Generate pagination HTML using WordPress native paginate_links
    $pagination_html = '';
    if ( $total_pages > 1 ) {
        $pagination_links = paginate_links( array(
            'base' => add_query_arg( 'paged', '%#%' ),
            'format' => '?paged=%#%',
            'total' => $total_pages,
            'current' => $current_page,
            'prev_text' => '&laquo; Previous',
            'next_text' => 'Next &raquo;',
            'type' => 'list',
            'end_size' => 1,
            'mid_size' => 2,
        ) );

        // Add button-2 class to pagination links for theme consistency
        if ( $pagination_links ) {
            $pagination_links = str_replace( 'class="prev page-numbers', 'class="prev page-numbers button-2 button-medium', $pagination_links );
            $pagination_links = str_replace( 'class="next page-numbers', 'class="next page-numbers button-2 button-medium', $pagination_links );
            $pagination_links = str_replace( 'class="page-numbers', 'class="page-numbers button-2 button-medium', $pagination_links );

            $start = ( $offset ) + 1;
            $end = min( $offset + $limit, $total_artists );

            $count_html = '';
            if ( $total_artists == 1 ) {
                $count_html = 'Viewing 1 artist';
            } elseif ( $end == $start ) {
                $count_html = sprintf( 'Viewing artist %s of %s', number_format( $start ), number_format( $total_artists ) );
            } else {
                $count_html = sprintf( 'Viewing artists %s-%s of %s total', number_format( $start ), number_format( $end ), number_format( $total_artists ) );
            }

            $pagination_html = '<div class="extrachill-pagination pagination-default">';
            $pagination_html .= '<div class="pagination-count">' . esc_html( $count_html ) . '</div>';
            $pagination_html .= '<div class="pagination-links">' . $pagination_links . '</div>';
            $pagination_html .= '</div>';
        }
    }

    // Return data for AJAX or echo for direct use
    if ( $return_data ) {
        return array(
            'html' => $artist_cards_html,
            'pagination_html' => $pagination_html,
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'total_artists' => $total_artists
        );
    }

    echo $artist_cards_html;

    // Fire action hook for pagination and other extensions
    do_action( 'extrachill_below_artist_grid', array(
        'pagination_html' => $pagination_html,
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'total_artists' => $total_artists,
        'limit' => $limit,
        'exclude_user_artists' => $exclude_user_artists
    ) );
}

/**
 * Render pagination below artist grid.
 * Hooks into extrachill_below_artist_grid action.
 *
 * @param array $context Pagination context data
 */
function ec_render_artist_grid_pagination( $context ) {
    if ( empty( $context['pagination_html'] ) ) {
        return;
    }

    $exclude_user_attr = $context['exclude_user_artists'] ? 'true' : 'false';

    echo '<div class="artist-grid-pagination" data-exclude-user="' . esc_attr( $exclude_user_attr ) . '">';
    echo $context['pagination_html'];
    echo '</div>';
}
add_action( 'extrachill_below_artist_grid', 'ec_render_artist_grid_pagination' );

// Load AJAX handler
require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/artist-grid-ajax.php';