<?php

// this code is used to display contextual excerpts in search results

if ( ! function_exists( 'ec_get_contextual_excerpt' ) ) {
    function ec_get_contextual_excerpt( $content, $search_term, $word_limit = 30 ) {
        $position = stripos( $content, $search_term );
        if ( $position === false ) {
            // If no match, fallback to default trimmed content
            $excerpt = '...' . wp_trim_words( $content, $word_limit ) . '...';
        } else {
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

            $excerpt = $prefix . implode( ' ', $excerpt ) . $suffix;
        }

        // Highlight the search term
        return highlight_search_term( $excerpt, $search_term );
    }
}

