<?php
// Add notification bell icon with unread notification count
add_action('generate_menu_bar_items', 'wp_surgeon_add_notification_bell_icon');
function wp_surgeon_add_notification_bell_icon() {
    if (is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        $notifications = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];

        // Filter unread notifications for the count
        $unread_count = count(array_filter($notifications, function ($notification) {
            return !$notification['read'];
        }));

        echo '<div class="notification-bell-icon">';
        echo '<a href="/notifications" title="Notifications"><i class="fa-solid fa-bell"></i>';
        if ($unread_count > 0) {
            echo '<span class="notification-count">' . $unread_count . '</span>';
        }
        echo '</a></div>';
    }
}

// Mark notifications as read when viewed
function extrachill_mark_notifications_as_read() {
    $current_user_id = get_current_user_id();
    $notifications = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];

    foreach ($notifications as &$notification) {
        if (!$notification['read']) {
            $notification['read'] = true;
            $notification['viewed_time'] = current_time('mysql');
        }
    }

    update_user_meta($current_user_id, 'extrachill_notifications', $notifications);
}

add_action('bbp_new_reply', 'extrachill_capture_reply_notifications', 10, 5);
// Capture reply notifications
function extrachill_capture_reply_notifications($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author) {
    // Avoid notifying users of their own replies
    if ($reply_author == get_post($topic_id)->post_author) return;

    // Fetching the topic title and reply author's display name
    $topic_title = get_the_title($topic_id);
    $reply_author_data = get_userdata($reply_author);
    $reply_author_display_name = $reply_author_data->display_name;

    // Construct the direct link to the reply within the thread context
    // Ensure the reply link is formatted as /topic#post-ID
    $reply_permalink = bbp_get_reply_url($reply_id); // Check this function returns the expected URL format

    // Adjust the profile link for bbPress profiles
    $user_profile_link = home_url('/u/' . $reply_author_data->user_nicename);

    // Notification array
    $notification = [
        'type' => 'reply',
        'post_id' => $topic_id,
        'user_id' => $reply_author,
        'user_display_name' => $reply_author_display_name,
        'topic_title' => $topic_title,
        'time' => current_time('mysql'),
        'read' => false,
        'reply_link' => $reply_permalink,
        'topic_link' => get_permalink($topic_id), // Retained, may be useful elsewhere
        'user_profile_link' => $user_profile_link,
    ];

    // Save the notification
    $topic_author = get_post_field('post_author', $topic_id);
    $notifications = get_user_meta($topic_author, 'extrachill_notifications', true) ?: [];
    $notifications[] = $notification;

    update_user_meta($topic_author, 'extrachill_notifications', $notifications);
}


add_action('bbp_new_reply', 'extrachill_capture_mention_notifications', 12, 5);
add_action('bbp_new_topic', 'extrachill_capture_mention_notifications', 12, 4);
add_action('bbp_edit_reply', 'extrachill_capture_mention_notifications', 12, 5);
add_action('bbp_edit_topic', 'extrachill_capture_mention_notifications', 12, 4);


function extrachill_capture_mention_notifications($post_id, $topic_id, $forum_id, $anonymous_data, $reply_author = 0) {
    // This function will be called for both topics and replies, so we need to handle both
    $content = ($reply_author == 0) ? bbp_get_topic_content($post_id) : bbp_get_reply_content($post_id);

    preg_match_all('#@([0-9a-zA-Z-_]+)#i', $content, $matches);
    $usernames = array_unique($matches[1]); // Unique to avoid multiple notifications for the same mention

    foreach ($usernames as $username) {
        $user = get_user_by('slug', $username);

        if ($user && !bbp_is_user_inactive($user->ID) && $user->ID != $reply_author) {
            // Generate the notification
            $notification = [
                'type' => 'mention',
                'post_id' => $topic_id,
                'user_id' => $user->ID,
                'mentioner_display_name' => get_userdata($reply_author)->display_name,
                'topic_title' => get_the_title($topic_id),
                'time' => current_time('mysql'),
                'read' => false,
                'reply_link' => $reply_author == 0 ? get_permalink($topic_id) : bbp_get_reply_url($post_id),
                'user_profile_link' => home_url('/u/' . $user->user_nicename),
            ];

            // Save the notification for the mentioned user
            $notifications = get_user_meta($user->ID, 'extrachill_notifications', true) ?: [];
            $notifications[] = $notification;
            update_user_meta($user->ID, 'extrachill_notifications', $notifications);
        }
    }
}


function extrachill_format_notification_message($notification) {
    switch ($notification['type']) {
        case 'reply':
            $time = date('n/j/y \a\t g:ia', strtotime($notification['time']));
            return sprintf(
                '<li><a href="%s">%s</a> replied to your topic "<a href="%s">%s</a>" - <small>%s</small></li>',
                esc_url($notification['user_profile_link']),
                esc_html($notification['user_display_name']),
                esc_url($notification['reply_link']), // Link directly to the reply
                esc_html($notification['topic_title']),
                esc_html($time)
            );
        case 'follow':
            $time = date('n/j/y \a\t g:ia', strtotime($notification['time']));
            return sprintf(
                '<li>%s followed you - <small>%s</small></li>',
                '<a href="'.esc_url($notification['follower_link']).'">'.esc_html($notification['user_display_name']).'</a>',
                esc_html($time)
            );
        case 'quote':
            $time = date('n/j/y \a\t g:ia', strtotime($notification['time']));
            return sprintf(
                '<li><a href="%s">%s</a> quoted you in the thread "<a href="%s">%s</a>" - <small>%s</small></li>',
                esc_url($notification['user_profile_link']), // Link to the quoting user's profile
                esc_html($notification['user_display_name']), // Display name of the quoting user
                esc_url($notification['quote_link']), // Link to the quoted post/thread
                esc_html($notification['topic_title']), // The title of the quoted post/thread as anchor text
                esc_html($time) // Time of the quote
            );
        case 'mention':
            $time = date('n/j/y \a\t g:ia', strtotime($notification['time']));
            return sprintf(
                '<li><a href="%s">%s</a> mentioned you in "<a href="%s">%s</a>" - <small>%s</small></li>',
                esc_url($notification['user_profile_link']),
                esc_html($notification['mentioner_display_name']),
                esc_url($notification['reply_link']),
                esc_html($notification['topic_title']),
                esc_html($time)
            );
        default:
            return ''; // Handle unknown types or extend with more cases as needed
    }
}



function extrachill_display_notifications() {
    $current_user_id = get_current_user_id();
    $notifications = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];

    if (empty($notifications)) {
        echo '<p>No notifications found.</p>';
        return;
    }

    // Create a structure to track which posts have quote notifications
    $posts_with_quotes = [];

    // Identify posts that have a quote notification
    foreach ($notifications as $notification) {
        if ($notification['type'] === 'quote') {
            $posts_with_quotes[$notification['post_id']] = true;
        }
    }

    // Filter notifications, prioritizing quotes over replies for the same post
    $filtered_notifications = array_filter($notifications, function($notification) use ($posts_with_quotes) {
        // If it's a reply and there's a quote for the same post, exclude the reply
        if ($notification['type'] === 'reply' && isset($posts_with_quotes[$notification['post_id']])) {
            return false;
        }
        // Include quote notifications and replies that don't have an overriding quote
        return true;
    });

    // Sort notifications by time in descending order (newest to oldest)
    usort($filtered_notifications, function ($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });

    $new_notifications = array_filter($filtered_notifications, function ($notification) {
        return !$notification['read'];
    });

    if (!empty($new_notifications)) {
        echo '<h2>New Notifications</h2><ul class="extrachill-notifications">';
        foreach ($new_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
            if (!$notification['read']) {
                echo ' <strong>New!</strong>';
            }
        }
        echo '</ul>';
    }

    extrachill_mark_notifications_as_read();

    $old_notifications = array_filter($filtered_notifications, function ($notification) {
        return $notification['read'];
    });

    if (!empty($old_notifications)) {
        echo '<h2>Previously Viewed</h2><ul class="extrachill-notifications">';
        foreach ($old_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
        }
        echo '</ul>';
    }
}


function extrachill_cleanup_old_notifications() {
    $users = get_users();

    foreach ($users as $user) {
        $notifications = get_user_meta($user->ID, 'extrachill_notifications', true);

        $updated_notifications = array_filter($notifications, function($notification) {
            return strtotime($notification['viewed_time']) > strtotime('-7 days');
        });

        if (count($updated_notifications) !== count($notifications)) {
            update_user_meta($user->ID, 'extrachill_notifications', $updated_notifications);
        }
    }
}

if (!wp_next_scheduled('extrachill_cleanup_notifications_hook')) {
    wp_schedule_event(time(), 'daily', 'extrachill_cleanup_notifications_hook');
}

add_action('extrachill_cleanup_notifications_hook', 'extrachill_cleanup_old_notifications');
