<?php
// Function to customize the pagination count for topics (posts)
function extrachill_custom_topic_pagination_count( $retstr ) {
    $bbp = bbpress();

    // Set pagination values for the topic reply query
    $count_int = intval( $bbp->reply_query->post_count );
    $total_int = intval( $bbp->reply_query->found_posts );
    $ppp_int   = intval( $bbp->reply_query->posts_per_page );
    $start_int = intval( ( $bbp->reply_query->paged - 1 ) * $ppp_int ) + 1;
    $to_int    = intval( ( $start_int + ( $ppp_int - 1 ) > $total_int )
        ? $total_int
        : $start_int + ( $ppp_int - 1 ) );

    // Format numbers for display
    $from_num  = bbp_number_format( $start_int );
    $to_num    = bbp_number_format( $to_int );
    $total_num = bbp_number_format( $total_int );

    // Customize the pagination output to be more concise for posts
    if ( $total_int == 1 ) {
        // If there is only one post, keep it simple
        $retstr = sprintf( __( 'Viewing 1 post', 'bbpress' ) );
    } elseif ( $to_num == $from_num ) {
        // If we're viewing a single post on the page, avoid redundant text
        $retstr = sprintf( __( 'Viewing post %1$s of %2$s', 'bbpress' ), $from_num, $total_num );
    } else {
        // Concise output for multiple posts
        $retstr = sprintf( __( 'Viewing posts %1$s-%2$s of %3$s', 'bbpress' ), $from_num, $to_num, $total_num );
    }

    // Return the modified result
    return $retstr;
}
// add_filter( 'bbp_get_topic_pagination_count', 'extrachill_custom_topic_pagination_count' );

// Function to customize the pagination count for forums (topics)
function extrachill_custom_forum_pagination_count( $retstr ) {
    $bbp = bbpress();

    // Check if the topic query exists
    if ( ! empty( $bbp->topic_query ) ) {

        // Set pagination values for the forum query
        $count_int = intval( $bbp->topic_query->post_count );
        $start_num = intval( ( $bbp->topic_query->paged - 1 ) * $bbp->topic_query->posts_per_page ) + 1;
        $total_int = ! empty( $bbp->topic_query->found_posts ) ? (int) $bbp->topic_query->found_posts : $count_int;

        // Format numbers for display
        $from_num  = bbp_number_format( $start_num );
        $to_num    = bbp_number_format( ( $start_num + ( $bbp->topic_query->posts_per_page - 1 ) > $total_int )
            ? $total_int
            : $start_num + ( $bbp->topic_query->posts_per_page - 1 ) );
        $total_num = bbp_number_format( $total_int );

        // Customize the pagination output to be more concise for topics
        if ( $total_int == 1 ) {
            // If there is only one topic, keep it simple
            $retstr = sprintf( __( 'Viewing 1 topic', 'bbpress' ) );
        } elseif ( $to_num == $from_num ) {
            // If we're viewing a single topic on the page, avoid redundant text
            $retstr = sprintf( __( 'Viewing topic %1$s of %2$s', 'bbpress' ), $from_num, $total_num );
        } else {
            // Concise output for multiple topics
            $retstr = sprintf( __( 'Viewing topics %1$s-%2$s of %3$s', 'bbpress' ), $from_num, $to_num, $total_num );
        }
    }

    // Return the modified result
    return $retstr;
}

// Hook for customizing forum pagination count
// add_filter( 'bbp_get_forum_pagination_count', 'extrachill_custom_forum_pagination_count' );
