<?php
/**
 * Register a custom endpoint to fetch BBPress topics and replies,
 * excluding post ID = 1494, only published, and expose additional fields.
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'ec/v1', '/bbpress-search', array(
        'methods'  => 'GET',
        'callback' => 'ec_bbpress_search_handler',
        'permission_callback' => '__return_true',
    ) );
});


/**
 * Callback for the custom endpoint
 */
function ec_bbpress_search_handler( WP_REST_Request $request ) {
    $search_term = $request->get_param( 'search' );
    $limit       = $request->get_param( 'per_page' ) ?: 10;
    $page        = max( 1, (int) $request->get_param( 'page' ) );

    // Caching key based on search, limit, and page
    $cache_key = 'ec_forum_search_' . md5( strtolower( trim( $search_term ) ) ) . "_{$limit}_{$page}";
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $args = array(
        'post_type'      => array( 'topic', 'reply' ),
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'paged'          => $page,
        's'              => $search_term,
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'key'     => '_bbp_forum_id',
                'value'   => array( 1494, 547 ),
                'compare' => 'NOT IN',
            ),
        ),
    );

    $query = new WP_Query( $args );
    $results = [];

    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();

            $type = get_post_type();
            $forum_id = null;
            $forum_title = 'Unknown Forum';
            $forum_link = '#';

            if ( $type === 'reply' ) {
                $parent_topic_id = get_post_meta( get_the_ID(), '_bbp_topic_id', true );
                $forum_id = get_post_meta( $parent_topic_id, '_bbp_forum_id', true );
            } elseif ( $type === 'topic' ) {
                $forum_id = get_post_meta( get_the_ID(), '_bbp_forum_id', true );
            }

            if ( $forum_id ) {
                $forum_title = get_the_title( $forum_id );
                $forum_link = get_permalink( $forum_id );
            }

            $results[] = array(
                'id'         => get_the_ID(),
                'guid'       => $type === 'reply' ? bbp_get_reply_url( get_the_ID() ) : get_permalink(),
                'type'       => $type,
                'title'      => get_the_title(),
                'link'       => $type === 'reply' ? bbp_get_reply_url( get_the_ID() ) : get_permalink(),
                'excerpt'    => ec_get_contextual_excerpt( wp_strip_all_tags( get_the_content() ), $search_term, 30 ),
                'author'     => get_the_author_meta( 'display_name' ),
                'date'       => get_the_date( 'c' ),
                'forum'      => array(
                    'title' => $forum_title,
                    'link'  => $forum_link,
                ),
                'upvotes'    => get_post_meta( get_the_ID(), 'upvote_count', true ) ?: 0,
            );
        }
        wp_reset_postdata();
    }

    set_transient( $cache_key, $results, 10 * MINUTE_IN_SECONDS );
    return $results;
}





/**
 * Generate a contextual excerpt for the first match of a search term
 *
 * @param string $content The content to search.
 * @param string $search_term The term to find in the content.
 * @param int $word_limit Number of words to include around the match.
 * @return string The contextual excerpt.
 */
function ec_get_contextual_excerpt( $content, $search_term, $word_limit = 30 ) {
    $position = stripos( $content, $search_term );
    if ( $position === false ) {
        // If no match, fallback to default trimmed content
        return '...' . wp_trim_words( $content, $word_limit ) . '...';
    }

    // Get surrounding text
    $words = explode( ' ', $content );
    $match_position = 0;

    // Count words until we find the match
    foreach ( $words as $index => $word ) {
        if ( stripos( $word, $search_term ) !== false ) {
            $match_position = $index;
            break;
        }
    }

    // Get the range of words around the match
    $start = max( 0, $match_position - floor( $word_limit / 2 ) );
    $length = min( count( $words ) - $start, $word_limit );

    // Extract the excerpt
    $excerpt = array_slice( $words, $start, $length );

    // Add ellipses based on whether we're truncating at the start or end
    $prefix = $start > 0 ? '...' : '';
    $suffix = ($start + $length) < count( $words ) ? '...' : '';

    return $prefix . implode( ' ', $excerpt ) . $suffix;
}

