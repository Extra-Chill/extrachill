<?php

/**
 * FETCH FORUM RESULTS FROM THE SUBDOMAIN ENDPOINT
 */
function ec_fetch_forum_results( $search_term, $limit = 10 ) {
    $url = add_query_arg(
        array(
            'search'   => $search_term,
            'per_page' => $limit,
        ),
        'https://community.extrachill.com/wp-json/ec/v1/bbpress-search'
    );

    $response = wp_remote_get( $url );
    if ( is_wp_error( $response ) ) {
        return array();
    }
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    // Validate and return data
    if ( ! is_array( $data ) ) {
        return array();
    }

    // Ensure upvote count is numeric and set default to 1 if missing
    foreach ( $data as &$item ) {
        $item['upvotes'] = isset( $item['upvotes'] ) && is_numeric( $item['upvotes'] ) ? (int) $item['upvotes'] : 1;
    }

    return $data;
}


/**
 * 2) MERGE REMOTE RESULTS INTO THE MAIN WP SEARCH
 */
add_filter( 'the_posts', 'ec_merge_remote_forum_into_search', 10, 2 );
function ec_merge_remote_forum_into_search( $posts, $query ) {
    // Only in front-end, main query, search
    if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
        return $posts;
    }

    // Determine pagination variables
    $posts_per_page = $query->get( 'posts_per_page' );
    $paged          = max( 1, $query->get( 'paged' ) );

    // Fetch remote results
    $search_term = $query->get( 's' );
    $remote_data = ec_fetch_forum_results( $search_term, $posts_per_page * $paged );
    if ( empty( $remote_data ) ) {
        return $posts; // No remote items
    }

    // Process and deduplicate topics
    $topics = [];
    foreach ( $remote_data as $item ) {
        // Skip invalid items
        if ( empty( $item['id'] ) || empty( $item['title'] ) ) {
            continue;
        }

        $topic_id = 'topic' === $item['type']
            ? $item['id']
            : ( isset( $item['forum']['link'] ) ? md5( $item['forum']['link'] ) : null );

        if ( ! $topic_id ) {
            continue;
        }

        // If it's a reply, ensure it links to the most recent reply
        if ( 'reply' === $item['type'] ) {
            if ( ! isset( $topics[ $topic_id ] ) || strtotime( $topics[ $topic_id ]['date'] ) < strtotime( $item['date'] ) ) {
                $topics[ $topic_id ] = $item; // Replace with the latest reply
            }
        } else {
            // Add topics only if not already present
            $topics[ $topic_id ] = $item;
        }
    }

    // Create virtual posts for the deduplicated results
    $fake_posts = [];
    foreach ( $topics as $item ) {
        $forum_title = isset( $item['forum']['title'] ) ? $item['forum']['title'] : 'Unknown Forum';
        $forum_link = isset( $item['forum']['link'] ) ? $item['forum']['link'] : '#';
        $upvotes = isset( $item['upvotes'] ) ? (int) $item['upvotes'] : 1;

        $post_obj = (object) array(
            'ID'             => 900000000 + (int) $item['id'],
            'post_title'     => $item['title'],
            'post_content'   => $item['excerpt'],
            'post_excerpt'   => $item['excerpt'],
            'post_date'      => $item['date'],
            'post_date_gmt'  => get_gmt_from_date( $item['date'] ),
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'guid'           => $item['link'], // Link to the most relevant reply
            'post_author'    => 1,
            'filter'         => 'raw',
            '_is_forum_post' => true,
            '_author'        => $item['author'],
            '_forum'         => array(
                'title' => $forum_title,
                'link'  => $forum_link,
            ),
            '_upvotes'       => $upvotes,
        );

        $fake_posts[] = new WP_Post( $post_obj );
    }

    // Merge and sort by date
    $merged = array_merge( $posts, $fake_posts );
    usort( $merged, function( $a, $b ) {
        return strcmp( $b->post_date, $a->post_date );
    });

    return $merged;
}





/**
 * 3) OVERRIDE the_permalink FOR REMOTE ITEMS
 */
add_filter( 'post_link', 'ec_override_permalink_for_remote_posts', 10, 2 );
function ec_override_permalink_for_remote_posts( $url, $post ) {
    if ( $post->ID >= 900000000 ) { // Remote items have high ID offsets
        return $post->guid ? $post->guid : $url;
    }
    return $url;
}

/**
 * Highlight search term in the excerpt.
 *
 * @param string $excerpt The excerpt to modify.
 * @param string $search_term The search term to highlight.
 * @return string Modified excerpt with the search term wrapped in <b> tags.
 */
function highlight_search_term( $excerpt, $search_term ) {
    if ( empty( $search_term ) ) {
        return $excerpt; // Return original if no search term provided.
    }
    $escaped_search_term = preg_quote( $search_term, '/' ); // Escape special characters for regex.
    return preg_replace( "/($escaped_search_term)/i", '<b>$1</b>', $excerpt ); // Highlight the term.
}
