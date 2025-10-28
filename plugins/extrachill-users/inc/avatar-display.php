<?php
/**
 * Custom Avatar Display System
 *
 * Filters pre_get_avatar to provide custom avatars before Gravatar fallback.
 * Uses custom_avatar_id user meta (WordPress attachment ID).
 * Multisite-aware via get_user_option() for network-wide availability.
 *
 * @package ExtraChill\Users
 */

/**
 * Provide custom avatars with multisite support before Gravatar fallback.
 *
 * @param string|null  $avatar Avatar HTML or null
 * @param mixed        $id_or_email User ID, email, or object
 * @param array        $args Avatar arguments
 * @return string|null Avatar HTML or null for Gravatar fallback
 */
function extrachill_custom_avatar($avatar, $id_or_email, $args) {
    $user = false;

    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', (int) $id_or_email);
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user = get_user_by('id', (int) $id_or_email->user_id);
    } elseif (is_object($id_or_email)) {
        $user = $id_or_email; // Potentially user object
    } else {
        $user = get_user_by('email', $id_or_email);
    }

    if ($user && is_object($user)) {
        // Switch to community site where avatars are stored
        switch_to_blog(2);

            try {
                $custom_avatar_id = get_user_option('custom_avatar_id', $user->ID);

                if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)) {
                    $thumbnail_src = wp_get_attachment_image_url($custom_avatar_id, 'thumbnail');

                    if ($thumbnail_src) {
                        $size = isset($args['size']) ? (int) $args['size'] : 96;
                        $alt = isset($args['alt']) ? $args['alt'] : '';

                        $avatar_html = sprintf(
                            '<img src="%1$s" alt="%2$s" width="%3$d" height="%3$d" class="avatar avatar-%3$d photo" />',
                            esc_url($thumbnail_src),
                            esc_attr($alt),
                            $size
                        );

                        return $avatar_html;
                    }
                }
            } finally {
                restore_current_blog();
            }
    }

    return null;
}
add_filter('pre_get_avatar', 'extrachill_custom_avatar', 10, 3);

/**
 * Legacy migration: Generate custom_avatar_id from custom_avatar URL.
 */
function generate_custom_avatar_ids() {
    $users_with_custom_avatars = get_users(array(
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'custom_avatar_id',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => 'custom_avatar',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    foreach ($users_with_custom_avatars as $user) {
        $custom_avatar_url = get_user_meta($user->ID, 'custom_avatar', true);
        $attachment_id = attachment_url_to_postid($custom_avatar_url);

        if ($attachment_id && wp_attachment_is_image($attachment_id)) {
            add_user_meta($user->ID, 'custom_avatar_id', $attachment_id, true);
            echo "User {$user->ID}: Added custom avatar ID.\n";
        } else {
            echo "User {$user->ID}: Failed to add custom avatar ID.\n";
        }
    }

    echo "Custom avatar ID generation completed.\n";
}

/**
 * Handle admin trigger for avatar ID migration.
 */
add_action('admin_init', 'handle_custom_avatar_id_generation');
function handle_custom_avatar_id_generation() {
    if (isset($_GET['generate_custom_avatar_ids']) && current_user_can('administrator')) {
        generate_custom_avatar_ids();
        exit;
    }
}
