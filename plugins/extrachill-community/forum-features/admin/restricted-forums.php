<?php
/**
 * Forum Access Restrictions
 * 
 * Adds admin interface for setting forum posting requirements based on
 * user roles (artists, industry professionals). Controls who can post
 * in specific forums through custom meta fields.
 * 
 * @package ExtraChillCommunity
 */
function extrachill_add_requirement_boxes() {
    $requirements = [
        '_require_artist'          => 'Require Artist to Post',
        '_require_professional'    => 'Require Industry Professional to Post',
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
add_action('add_meta_boxes', 'extrachill_add_requirement_boxes');


// Save the states of the checkboxes
function extrachill_save_postdata($post_id) {
    $meta_keys = ['_require_artist', '_require_professional'];

    foreach ($meta_keys as $meta_key) {
        if (array_key_exists($meta_key, $_POST)) {
            update_post_meta($post_id, $meta_key, $_POST[$meta_key]);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }
}
add_action('save_post', 'extrachill_save_postdata');


// Restrict forum posting based on user meta
function extrachill_restrict_forum_posting($can_post, $forum_id = null) {
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
add_filter('bbp_current_user_can_publish_topics', 'extrachill_restrict_forum_posting', 10, 2);
add_filter('bbp_current_user_can_publish_replies', 'extrachill_restrict_forum_posting', 10, 2);


// Optionally, you could remove redundant main query exclusions if the consolidated function covers all cases.
