<?php
// Add custom meta boxes to forum edit screen
function wp_surgeon_add_requirement_boxes() {
    $requirements = [
        '_require_artist'          => 'Require Artist to Post',
        '_require_professional'    => 'Require Industry Professional to Post',
        '_require_extrachill_team' => 'Require Extra Chill Team to Access',
    ];

    foreach ($requirements as $meta_key => $title) {
        add_meta_box(
            $meta_key . '_id',
            $title,
            function($post) use ($meta_key, $title) {
                $value = get_post_meta($post->ID, $meta_key, true);
                ?>
                <label for="<?php echo esc_attr($meta_key); ?>"><?php echo esc_html($title); ?>:</label>
                <input type="checkbox" name="<?php echo esc_attr($meta_key); ?>" id="<?php echo esc_attr($meta_key); ?>" value="1" <?php checked($value, '1'); ?>>
                <?php
            },
            'forum',
            'side',
            'high'
        );
    }
}
add_action('add_meta_boxes', 'wp_surgeon_add_requirement_boxes');


// Save the states of the checkboxes
function wp_surgeon_save_postdata($post_id) {
    $meta_keys = ['_require_artist', '_require_professional', '_require_extrachill_team'];

    foreach ($meta_keys as $meta_key) {
        if (array_key_exists($meta_key, $_POST)) {
            update_post_meta($post_id, $meta_key, $_POST[$meta_key]);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }
}
add_action('save_post', 'wp_surgeon_save_postdata');


// Restrict forum posting based on user meta
function wp_surgeon_restrict_forum_posting($can_post, $forum_id = null) {
    if (null === $forum_id) {
        $forum_id = bbp_get_forum_id();
    }

    if ($forum_id) {
        $requirements = [
            '_require_artist'       => 'user_is_artist',
            '_require_professional' => 'user_is_professional',
        ];

        foreach ($requirements as $meta_key => $user_meta_key) {
            $require_permission = (get_post_meta($forum_id, $meta_key, true) == '1');
            $has_permission = (get_user_meta(get_current_user_id(), $user_meta_key, true) == '1');

            // Restrict posting if permission is required but the user lacks it
            if ($require_permission && !$has_permission) {
                return false;
            }
        }
    }

    return $can_post;
}
add_filter('bbp_current_user_can_publish_topics', 'wp_surgeon_restrict_forum_posting', 10, 2);
add_filter('bbp_current_user_can_publish_replies', 'wp_surgeon_restrict_forum_posting', 10, 2);


// Extra Chill Team meta box (separate from the other requirement boxes)
function wp_surgeon_require_extrachill_team_meta_box_html($post) {
    $value = get_post_meta($post->ID, '_require_extrachill_team', true);
    ?>
    <label for="wp_surgeon_require_extrachill_team_field">Require Extra Chill Team:</label>
    <input type="checkbox" name="wp_surgeon_require_extrachill_team_field" id="wp_surgeon_require_extrachill_team_field" value="1" <?php checked($value, '1'); ?>>
    <?php
}

function wp_surgeon_save_extrachill_team_postdata($post_id) {
    if (array_key_exists('wp_surgeon_require_extrachill_team_field', $_POST)) {
        update_post_meta($post_id, '_require_extrachill_team', $_POST['wp_surgeon_require_extrachill_team_field']);
    }
}
add_action('save_post', 'wp_surgeon_save_extrachill_team_postdata');


// Redirect non-team members away from private forums
function wp_surgeon_restrict_forum_access() {
    if (bbp_is_single_forum() || bbp_is_single_topic() || bbp_is_single_reply()) {
        $forum_id = bbp_is_single_forum() ? bbp_get_forum_id() : 
                    (bbp_is_single_topic() ? bbp_get_topic_forum_id() : bbp_get_reply_forum_id());

        $private_forum_ids = extrachill_get_private_forum_ids();
        $is_private_forum = in_array($forum_id, $private_forum_ids);
        $is_user_extrachill_team = is_user_logged_in() && (get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1');

        if ($is_private_forum && !$is_user_extrachill_team) {
            wp_redirect(home_url());
            exit;
        }
    }
}
add_action('template_redirect', 'wp_surgeon_restrict_forum_access');


// Consolidated function for excluding private forums
function wp_surgeon_exclude_private_forums_simple($args_or_query) {
    // When $args_or_query is an array (for bbPress queries)
    if (is_array($args_or_query)) {
        // If we are on a single item page (topic, forum, reply), don't modify the query for these filters.
        // The redirect in wp_surgeon_restrict_forum_access handles access control for these pages.
        if ( function_exists('is_bbpress') && ( bbp_is_single_topic() || bbp_is_single_forum() || bbp_is_single_reply() ) ) {
            return $args_or_query;
        }

        // If the current user is an Extra Chill team member, no exclusion is needed.
        if (is_user_logged_in() && (get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1')) {
            return $args_or_query;
        }
        // Otherwise, exclude private forums from listings like main forum index, search, recent feed etc.
        $private_forum_ids = extrachill_get_private_forum_ids();
        if (!empty($private_forum_ids)) {
            // Ensure we don't accidentally overwrite an existing 'post_parent__not_in'
            if (isset($args_or_query['post_parent__not_in'])) {
                $args_or_query['post_parent__not_in'] = array_unique(array_merge((array) $args_or_query['post_parent__not_in'], $private_forum_ids));
            } else {
                $args_or_query['post_parent__not_in'] = $private_forum_ids;
            }
        }
        return $args_or_query;
    }

    // When $args_or_query is a WP_Query object (for main queries AND secondary queries)
    if ($args_or_query instanceof WP_Query) {

        // --- Check if we should bail early --- 
        
        // 1. Ignore admin
        if (is_admin()) { 
            return; 
        }

        // 2. Ignore if it's not a bbPress query context somehow (safety check)
        if (!function_exists('is_bbpress') || !is_bbpress()) {
            return;
        }

        // 3. Ignore if it's specifically the reply query (post_type = 'reply')
        //    The `is_array` block handles reply query *argument filtering* safely now.
        //    We don't want pre_get_posts adding post_parent__not_in to reply queries.
        $post_type = $args_or_query->get('post_type');
        if ($post_type === 'reply') {
            return;
        }

        // 4. Only apply exclusion logic to relevant bbPress *archive* main loops
        //    This ensures we don't affect the main query on single topic/reply pages.
        if (!($args_or_query->is_main_query() && !bbp_is_single_topic() && !bbp_is_single_reply())) {
             // If it's not the main query OR if it IS the main query but on a single page, bail.
             return; 
        }
        
        // --- Apply the exclusion logic --- 

        // Exclude private forums for non-team members on relevant archive main loops
        if (!(is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1')) {
            $private_forum_ids = extrachill_get_private_forum_ids();
            if (!empty($private_forum_ids)) {
                // Get current value
                $existing_not_in = $args_or_query->get('post_parent__not_in'); 
                if (!is_array($existing_not_in)) {
                    $existing_not_in = empty($existing_not_in) ? [] : [$existing_not_in];
                }
                // Merge and set
                $new_not_in = array_unique(array_merge($existing_not_in, $private_forum_ids));
                $args_or_query->set('post_parent__not_in', $new_not_in);
            }
        }
        
        // Explicit return for clarity, though pre_get_posts is an action
        return;
    }
}

// Attach the consolidated exclusion function to relevant filters
add_filter('bbp_before_has_topics_parse_args', 'wp_surgeon_exclude_private_forums_simple');
add_filter('bbp_before_has_search_results_parse_args', 'wp_surgeon_exclude_private_forums_simple');
add_filter('bbp_before_has_replies_parse_args', 'wp_surgeon_exclude_private_forums_simple');
add_action('pre_get_posts', 'wp_surgeon_exclude_private_forums_simple');


// Noindex meta for private forums
function wp_surgeon_noindex_private_forums() {
    if (bbp_is_single_forum() || bbp_is_single_topic() || bbp_is_single_reply()) {
        $forum_id = bbp_is_single_forum() ? bbp_get_forum_id() : (bbp_is_single_topic() ? bbp_get_topic_forum_id() : bbp_get_reply_forum_id());
        $private_forum_ids = extrachill_get_private_forum_ids();
        if (in_array($forum_id, $private_forum_ids)) {
            echo '<meta name="robots" content="noindex">';
        }
    }
}
add_action('wp_head', 'wp_surgeon_noindex_private_forums');


/**
 * Checks if a topic should be excluded from public listings due to forum privacy settings.
 *
 * @param int $topic_id The ID of the topic to check.
 * @return bool True if the topic should be excluded for the current user, false otherwise.
 */
function wp_surgeon_is_private_topic_excluded( $topic_id ) {
    $forum_id = bbp_get_topic_forum_id( $topic_id );
    $is_user_not_team_member = !( is_user_logged_in() && get_user_meta( get_current_user_id(), 'extrachill_team', true ) == '1' );
    $private_forum_ids = extrachill_get_private_forum_ids();

    // Return true if user is NOT team member AND forum is in the private list
    return $is_user_not_team_member && ! empty( $private_forum_ids ) && in_array( $forum_id, $private_forum_ids );
}


// Optionally, you could remove redundant main query exclusions if the consolidated function covers all cases.
?>
