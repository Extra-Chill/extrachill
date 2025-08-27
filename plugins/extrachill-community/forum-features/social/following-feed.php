<?php
/**
 * Following Feed Template Tag and Query Functions
 * 
 * Handles displaying topics from band forums that the current user follows.
 */

/**
 * Modify the bbp_has_topics query to show only topics from band forums that the user follows.
 */
function modify_following_feed_query( $args ) {
    if ( ! isset( $GLOBALS['bbpress'] ) ) {
        $GLOBALS['bbpress'] = bbpress();
    }
    $bbp = $GLOBALS['bbpress'];

    // Check if this is a following feed page query and modify accordingly
    $is_following_feed_context = false;

    // Check query_var flag
    if ( get_query_var( 'extrachill_is_following_feed_context' ) === 'true' ) {
        $is_following_feed_context = true;
    }

    // Check if the query was initiated from our template context and modify
    if ( isset( $bbp->extrachill_passthrough_args ) && is_array( $bbp->extrachill_passthrough_args ) ) {
        $is_following_feed_context = true;
        $args = array_merge( $args, $bbp->extrachill_passthrough_args );
        unset( $bbp->extrachill_passthrough_args );
    }

    if ( $is_following_feed_context ) {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            // User not logged in, show no results
            $args['post__in'] = array( 0 );
            return $args;
        }

        // Get followed band profile IDs from user meta
        $followed_band_profile_ids = get_user_meta( $current_user_id, '_followed_band_profile_ids', true );
        if ( ! is_array( $followed_band_profile_ids ) || empty( $followed_band_profile_ids ) ) {
            // User not following any bands, show no results
            $args['post__in'] = array( 0 );
            return $args;
        }

        // Convert band profile IDs to their corresponding forum IDs
    $band_forum_ids = array();
    foreach ( $followed_band_profile_ids as $band_profile_id ) {
        $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
            if ( $forum_id ) {
                $band_forum_ids[] = (int) $forum_id;
            }
        }

        if ( empty( $band_forum_ids ) ) {
            // No valid band forums found, show no results
            $args['post__in'] = array( 0 );
            return $args;
        }

        // Set the query to only show topics from these forums
        $args['meta_query'] = array(
            array(
                'key'     => '_bbp_forum_id',
                'value'   => $band_forum_ids,
                'compare' => 'IN',
                'type'    => 'NUMERIC'
            )
        );

        // Ensure we're getting a reasonable number of posts
        $actual_posts_per_page = bbp_get_topics_per_page();
        if ( $actual_posts_per_page && is_numeric( $actual_posts_per_page ) ) {
            $args['posts_per_page'] = $actual_posts_per_page;
        }

        // Ensure proper ordering
        $args['orderby'] = 'date';
        $args['order'] = 'DESC';
    }

    return $args;
}

?>
