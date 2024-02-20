<?php
// social-networking.php

function extrachill_follow_button($user_id) {
    $current_user_id = get_current_user_id();
    $is_logged_in = is_user_logged_in();

    // Check if the profile belongs to the current user
    $is_own_profile = $current_user_id == $user_id;

    if ($is_own_profile) {
        // Get the BBPress profile URL
        $bbpress_profile_url = bbp_get_user_profile_url($current_user_id);

        // Check if we are on the edit profile page
        if (strpos($_SERVER['REQUEST_URI'], '/edit') !== false) {
            // View Profile Button
            echo '<button class="extrachill-view-profile-button" onclick="location.href=\'' . esc_url($bbpress_profile_url) . '\'">View Profile</button>';
        } else {
            // Edit Profile Button
            echo '<button class="extrachill-edit-profile-button" onclick="location.href=\'' . esc_url($bbpress_profile_url . '/edit') . '\'">Edit Profile</button>';
        }
    } else {
        if ($is_logged_in) {
            // Determine if the current user is following the profile
            $following = get_user_meta($current_user_id, 'extrachill_following', true);
            $is_following = is_array($following) && in_array($user_id, $following);

            // Generate a unique nonce for the follow/unfollow action
            $nonce = wp_create_nonce('extrachill_follow_nonce_' . $user_id);

            // Render the "Follow" button for logged-in users
            $button_text = $is_following ? 'Following' : 'Follow';
            echo '<button class="extrachill-follow-button" data-action="' . ($is_following ? 'unfollow' : 'follow') . '" data-user-id="' . esc_attr($user_id) . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html($button_text) . '</button>';
        } else {
            // Render a different button for non-logged-in users
            echo '<button class="extrachill-non-logged-in-follow-button" onclick="location.href=\'/login\'">Follow</button>';
        }
    }
}



add_action('wp_ajax_extrachill_follow_user', 'extrachill_handle_follow_ajax');
function extrachill_handle_follow_ajax() {
    $follower_id = get_current_user_id();
    $followed_id = isset($_POST['followed_id']) ? intval($_POST['followed_id']) : 0;

    // Check if the nonce is valid for the specific follow action
    check_ajax_referer('extrachill_follow_nonce_' . $followed_id, 'nonce');

    if (extrachill_follow_user($follower_id, $followed_id)) {
        wp_send_json_success('Followed successfully', true);
    } else {
        wp_send_json_error('Follow action failed', true);
    }
}

add_action('wp_ajax_extrachill_unfollow_user', 'extrachill_handle_unfollow_ajax');
function extrachill_handle_unfollow_ajax() {
    $follower_id = get_current_user_id();
    $followed_id = isset($_POST['followed_id']) ? intval($_POST['followed_id']) : 0;

    // Check if the nonce is valid for the specific unfollow action
    check_ajax_referer('extrachill_follow_nonce_' . $followed_id, 'nonce');

    if (extrachill_unfollow_user($follower_id, $followed_id)) {
        wp_send_json_success('Unfollowed successfully', true);
    } else {
        wp_send_json_error('Unfollow action failed', true);
    }
}


// User follow/unfollow functions
function extrachill_follow_user($follower_id, $followed_id) {
    if (empty($follower_id) || empty($followed_id)) {
        return false;
    }

    // Following logic
    $following = get_user_meta($follower_id, 'extrachill_following', true);
    if (!is_array($following)) {
        $following = [];
    }

    if (!in_array($followed_id, $following)) {
        $following[] = $followed_id;
        update_user_meta($follower_id, 'extrachill_following', $following);

        // Followers logic
        $followers = get_user_meta($followed_id, 'extrachill_followers', true);
        if (!is_array($followers)) {
            $followers = [];
        }
        if (!in_array($follower_id, $followers)) {
            $followers[] = $follower_id;
            update_user_meta($followed_id, 'extrachill_followers', $followers);

            // Generate follow notification
            $follower_data = get_userdata($follower_id);
            $notification_message = sprintf(
                '<a href="%s">%s</a> followed you.',
                esc_url(bbp_get_user_profile_url($follower_id)),
                esc_html($follower_data->display_name)
            );

            $notifications = get_user_meta($followed_id, 'extrachill_notifications', true) ?: [];
            $notifications[] = array(
                'type' => 'follow',
                'user_display_name' => $follower_data->display_name,
                'time' => current_time('mysql'),
                'read' => false,
                'follower_link' => bbp_get_user_profile_url($follower_id),
            );

            update_user_meta($followed_id, 'extrachill_notifications', $notifications);
        do_action('extrachill_followed_user', $follower_id, $followed_id);

            return true;
        }
    }

    return false;
}





function extrachill_unfollow_user($follower_id, $followed_id) {
    if (empty($follower_id) || empty($followed_id)) {
        return false;
    }

    // Following logic
    $following = get_user_meta($follower_id, 'extrachill_following', true);
    if (!is_array($following)) {
        return false;
    }

    if (($key = array_search($followed_id, $following)) !== false) {
        unset($following[$key]);
        update_user_meta($follower_id, 'extrachill_following', array_values($following));

        // Followers logic
        $followers = get_user_meta($followed_id, 'extrachill_followers', true);
        if (is_array($followers) && ($key = array_search($follower_id, $followers)) !== false) {
            unset($followers[$key]);
            update_user_meta($followed_id, 'extrachill_followers', array_values($followers));
                    do_action('extrachill_unfollowed_user', $follower_id, $followed_id);
        }

        return true;
    }

    return false;
}

// Utility function to get followers of a user
function extrachill_get_followers($user_id) {
    $followers = get_user_meta($user_id, 'extrachill_followers', true);
    return is_array($followers) ? $followers : [];
}

function load_social_section_ajax() {
    $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : 'followers';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    // Based on the section, query the respective list
    if ($section === 'followers') {
        $user_list = get_user_meta($user_id, 'extrachill_followers', true);
        $list_title = "Followers";
    } else {
        $user_list = get_user_meta($user_id, 'extrachill_following', true);
        $list_title = "Following";
    }

    echo '<h1>' . esc_html($list_title) . '</h1>';

    if (!empty($user_list)) {
        echo '<ul>';
        foreach ($user_list as $id) {
            $user = get_userdata($id);
            if ($user) {
                $profile_url = bbp_get_user_profile_url($id);
                $name = $user->display_name;
                $avatar = get_avatar($id, 32);

                echo '<li>';
                echo '<a href="' . esc_url($profile_url) . '">' . $avatar . ' ' . esc_html($name) . '</a>';
                echo '</li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<p>No ' . esc_html(strtolower($list_title)) . ' found.</p>';
    }

    wp_die(); // This is required to terminate immediately and return a proper response
}
add_action('wp_ajax_load_social_section', 'load_social_section_ajax');
add_action('wp_ajax_nopriv_load_social_section', 'load_social_section_ajax'); // Allow non-logged-in users to switch sections

?>


