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
 * @param int $limit Number of artists to display (default: 12)
 * @param bool $exclude_user_artists Whether to exclude current user's owned artists (default: false)
 */
function ec_display_artist_cards_grid( $limit = 12, $exclude_user_artists = false ) {
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
        $user_artist_ids = ec_get_user_owned_artists( get_current_user_id() );
        if ( ! empty( $user_artist_ids ) ) {
            $args['post__not_in'] = $user_artist_ids;
        }
    }

    $artist_ids = get_posts( $args );

    if ( empty( $artist_ids ) ) {
        echo '<div class="no-artists-found">';
        echo '<p>' . __( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ) . '</p>';
        if ( is_user_logged_in() && ec_can_create_artist_profiles( get_current_user_id() ) ) {
            echo '<p><a href="' . esc_url( home_url( '/manage-artist-profiles/' ) ) . '" class="button button-primary">';
            echo __( 'Create the First Artist Profile', 'extrachill-artist-platform' );
            echo '</a></p>';
        }
        echo '</div>';
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

    // Limit results
    $artists_with_activity = array_slice( $artists_with_activity, 0, $limit );

    echo '<div class="artist-cards-grid">';
    
    foreach ( $artists_with_activity as $artist_data ) {
        $artist_id = $artist_data['id'];
        $activity_timestamp = $artist_data['activity'];
        
        // Format activity date
        $activity_date = $activity_timestamp ? human_time_diff( $activity_timestamp, time() ) : __( 'No recent activity', 'extrachill-artist-platform' );
        
        // Use the artist profile card template - set context to indicate this is for directory/homepage display
        $context = 'directory';
        echo ec_render_template( 'artist-profile-card', array(
            'artist_id' => $artist_id,
            'context' => $context
        ) );
    }
    
    echo '</div>'; // .artist-cards-grid
}