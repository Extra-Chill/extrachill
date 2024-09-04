<?php
/**
 * Independent Artists Tracking Functions
 */

/**
 * Check if a user has created any independent artist boards.
 *
 * @param int $user_id The user ID.
 * @return bool True if the user has at least one independent artist board, false otherwise.
 */
function has_independent_artist_boards($user_id) {
    $boards = get_user_meta($user_id, 'independent_artist_boards', true);
    return !empty($boards);
}

/**
 * Get the list of independent artist board IDs for a user.
 *
 * @param int $user_id The user ID.
 * @return array The list of board IDs.
 */
function get_independent_artist_boards($user_id) {
    $boards = get_user_meta($user_id, 'independent_artist_boards', true);
    return !empty($boards) ? $boards : array();
}

/**
 * Add an independent artist board for a user.
 *
 * @param int $user_id The user ID.
 * @param int $board_id The board (topic) ID.
 */
function add_independent_artist_board($user_id, $board_id) {
    $boards = get_user_meta($user_id, 'independent_artist_boards', true);
    if (empty($boards)) {
        $boards = array();
    }
    if (!in_array($board_id, $boards)) {
        $boards[] = $board_id;
        update_user_meta($user_id, 'independent_artist_boards', $boards);
    }
}

/**
 * Populate existing independent artist boards for all users.
 * This function can be called once to backfill data for existing boards.
 */
function populate_existing_independent_artist_boards() {
    $args = array(
        'post_type' => 'topic',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_bbp_forum_id',
                'value' => '5432', // Independent Artists forum ID
                'compare' => '='
            )
        )
    );
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $user_id = get_the_author_meta('ID');
            $board_id = get_the_ID();
            add_independent_artist_board($user_id, $board_id);
        }
    }

    wp_reset_postdata();
}

// Uncomment the following line to run the population function once
//populate_existing_independent_artist_boards();
