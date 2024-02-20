<?php
// Add custom meta boxes to forum edit screen
function wp_surgeon_add_requirement_boxes() {
    // Require Artist to Post
    add_meta_box(
        'wp_surgeon_require_artist_id',
        'Require Artist to Post',
        'wp_surgeon_require_artist_meta_box_html',
        'forum',
        'side',
        'high'
    );

    // Require Industry Professional to Post
    add_meta_box(
        'wp_surgeon_require_professional_id',
        'Require Industry Professional to Post',
        'wp_surgeon_require_professional_meta_box_html',
        'forum',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'wp_surgeon_add_requirement_boxes');

function wp_surgeon_require_artist_meta_box_html($post) {
    $value = get_post_meta($post->ID, '_require_artist', true);
    ?>
    <label for="wp_surgeon_require_artist_field">Require Artist:</label>
    <input type="checkbox" name="wp_surgeon_require_artist_field" id="wp_surgeon_require_artist_field" value="1" <?php checked($value, '1'); ?>>
    <?php
}

function wp_surgeon_require_professional_meta_box_html($post) {
    $value = get_post_meta($post->ID, '_require_professional', true);
    ?>
    <label for="wp_surgeon_require_professional_field">Require Industry Professional:</label>
    <input type="checkbox" name="wp_surgeon_require_professional_field" id="wp_surgeon_require_professional_field" value="1" <?php checked($value, '1'); ?>>
    <?php
}

// Save the states of the checkboxes
function wp_surgeon_save_postdata($post_id) {
    if (array_key_exists('wp_surgeon_require_artist_field', $_POST)) {
        update_post_meta(
            $post_id,
            '_require_artist',
            $_POST['wp_surgeon_require_artist_field']
        );
    }

    if (array_key_exists('wp_surgeon_require_professional_field', $_POST)) {
        update_post_meta(
            $post_id,
            '_require_professional',
            $_POST['wp_surgeon_require_professional_field']
        );
    }
}
add_action('save_post', 'wp_surgeon_save_postdata');

// Restrict forum posting based on user meta
function wp_surgeon_restrict_forum_posting($can_post, $forum_id = null) {
    // If forum_id is not provided, try to get the current forum ID
    if (null === $forum_id) {
        $forum_id = bbp_get_forum_id();
    }

    // Proceed only if we have a valid forum ID
    if ($forum_id) {
        $require_artist = get_post_meta($forum_id, '_require_artist', true) == '1';
        $require_professional = get_post_meta($forum_id, '_require_professional', true) == '1';

        // If neither is required, everyone can post
        if (!$require_artist && !$require_professional) {
            return $can_post;
        }

        $current_user = wp_get_current_user();
        $is_artist = get_user_meta($current_user->ID, 'user_is_artist', true) == '1';
        $is_professional = get_user_meta($current_user->ID, 'user_is_professional', true) == '1';

        // If "Require Artist" is checked but user is not an artist, restrict posting
        if ($require_artist && !$is_artist) {
            return false;
        }

        // If "Require Industry Professional" is checked but user is not a professional, restrict posting
        if ($require_professional && !$is_professional) {
            return false;
        }
    }

    // User meets the required criteria or forum ID is not valid
    return true;
}

add_filter('bbp_current_user_can_publish_topics', 'wp_surgeon_restrict_forum_posting', 10, 2);
add_filter('bbp_current_user_can_publish_replies', 'wp_surgeon_restrict_forum_posting', 10, 2);

function wp_surgeon_add_require_extrachill_team_box() {
    add_meta_box(
        'wp_surgeon_require_extrachill_team_id',
        'Require Extra Chill Team to Access',
        'wp_surgeon_require_extrachill_team_meta_box_html',
        'forum',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'wp_surgeon_add_require_extrachill_team_box');

function wp_surgeon_require_extrachill_team_meta_box_html($post) {
    $value = get_post_meta($post->ID, '_require_extrachill_team', true);
    ?>
    <label for="wp_surgeon_require_extrachill_team_field">Require Extra Chill Team:</label>
    <input type="checkbox" name="wp_surgeon_require_extrachill_team_field" id="wp_surgeon_require_extrachill_team_field" value="1" <?php checked($value, '1'); ?>>
    <?php
}

function wp_surgeon_save_extrachill_team_postdata($post_id) {
    if (array_key_exists('wp_surgeon_require_extrachill_team_field', $_POST)) {
        update_post_meta(
            $post_id,
            '_require_extrachill_team',
            $_POST['wp_surgeon_require_extrachill_team_field']
        );
    }
}
add_action('save_post', 'wp_surgeon_save_extrachill_team_postdata');

function wp_surgeon_restrict_forum_access() {
    // Check if it's a single forum, topic, or reply page
    if (bbp_is_single_forum() || bbp_is_single_topic() || bbp_is_single_reply()) {
        // Determine the forum ID
        if (bbp_is_single_forum()) {
            $forum_id = bbp_get_forum_id();
        } else if (bbp_is_single_topic()) {
            $forum_id = bbp_get_topic_forum_id();
        } else if (bbp_is_single_reply()) {
            $forum_id = bbp_get_reply_forum_id();
        }

        // Check if the forum requires 'extrachill_team'
        $require_extrachill_team = get_post_meta($forum_id, '_require_extrachill_team', true) == '1';
        $is_user_extrachill_team = is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1';

        // Redirect if the user doesn't have 'extrachill_team' access
        if ($require_extrachill_team && !$is_user_extrachill_team) {
            wp_redirect(home_url());
            exit;
        }
    }
}
add_action('template_redirect', 'wp_surgeon_restrict_forum_access');


function wp_surgeon_exclude_private_forums($args) {
    // If the user is part of the Extra Chill team, no need to modify the query
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1') {
        return $args;
    }

    // Find forums that require the Extra Chill team
    $args_query = array(
        'post_type' => bbp_get_forum_post_type(),
        'meta_query' => array(
            array(
                'key'     => '_require_extrachill_team',
                'value'   => '1',
                'compare' => '='
            )
        ),
        'fields' => 'ids',
    );
    $private_forum_ids = get_posts($args_query);

    // If there are private forums, exclude their topics and replies from the query
    if (!empty($private_forum_ids)) {
        $args['post_parent__not_in'] = $private_forum_ids;
    }

    return $args;
}
add_filter('bbp_before_has_topics_parse_args', 'wp_surgeon_exclude_private_forums');
add_filter('bbp_before_has_search_results_parse_args', 'wp_surgeon_exclude_private_forums');
add_filter('bbp_before_has_replies_parse_args', 'wp_surgeon_exclude_private_forums');

function wp_surgeon_exclude_private_forums_from_all_queries($query) {
    if (!is_admin() && $query->is_main_query()) {
        // Check if the user is part of the Extra Chill team
        $is_user_extrachill_team = is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1';
        
        if (!$is_user_extrachill_team) {
            // Find forums that require the Extra Chill team
            $args_query = array(
                'post_type' => bbp_get_forum_post_type(),
                'meta_query' => array(
                    array(
                        'key'     => '_require_extrachill_team',
                        'value'   => '1',
                        'compare' => '='
                    )
                ),
                'fields' => 'ids',
                'posts_per_page' => -1,
            );
            $private_forum_ids = get_posts($args_query);

            // If there are private forums, exclude their topics and replies from all queries
            if (!empty($private_forum_ids)) {
                $query->set('post_parent__not_in', $private_forum_ids);
            }
        }
    }
}
add_action('pre_get_posts', 'wp_surgeon_exclude_private_forums_from_all_queries');

function wp_surgeon_noindex_private_forums() {
    // Check if it's a single forum, topic, or reply page
    if (bbp_is_single_forum() || bbp_is_single_topic() || bbp_is_single_reply()) {
        // Determine the forum ID
        $forum_id = bbp_is_single_forum() ? bbp_get_forum_id() : (bbp_is_single_topic() ? bbp_get_topic_forum_id() : bbp_get_reply_forum_id());

        // Check if the forum requires 'extrachill_team'
        $require_extrachill_team = get_post_meta($forum_id, '_require_extrachill_team', true) == '1';

        // Add noindex if the forum is private
        if ($require_extrachill_team) {
            echo '<meta name="robots" content="noindex">';
        }
    }
}
add_action('wp_head', 'wp_surgeon_noindex_private_forums');

add_action('pre_get_posts', 'extrachill_exclude_private_forums_from_main_query');

function extrachill_exclude_private_forums_from_main_query($query) {
    // Check if this is the main query, not admin, and for the correct post type (topic or reply)
    if ($query->is_main_query() && !$query->is_admin && (bbp_get_topic_post_type() == $query->get('post_type') || bbp_get_reply_post_type() == $query->get('post_type'))) {
        // Get IDs of private forums
        $private_forum_ids = extrachill_get_private_forum_ids();
        
        if (!empty($private_forum_ids)) {
            // If looking at topics, exclude them directly
            if (bbp_get_topic_post_type() == $query->get('post_type')) {
                $query->set('post_parent__not_in', $private_forum_ids);
            }
            // If looking at replies, get IDs of topics in private forums to exclude
            elseif (bbp_get_reply_post_type() == $query->get('post_type')) {
                $private_topic_ids = get_posts([
                    'post_type' => bbp_get_topic_post_type(),
                    'post_parent__in' => $private_forum_ids,
                    'fields' => 'ids',
                    'posts_per_page' => -1, // Get all topics
                    'no_found_rows' => true // Skip pagination for performance
                ]);
                if (!empty($private_topic_ids)) {
                    $query->set('post_parent__not_in', $private_topic_ids);
                }
            }
        }
    }
}
