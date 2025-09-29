<?php

// Global cache variable
$GLOBALS['extrachill_notifications_cache'] = null;

/**
 * REMOVED: Duplicate notification bell hook function
 * 
 * This function was creating conflicts with the dedicated notification-bell-avatar.php template.
 * The actual notification bell is now handled by /forum-features/content/notification-bell-avatar.php
 * which is included directly in header.php for better performance and maintainability.
 */


// Mark notifications as read when viewed
function extrachill_mark_notifications_as_read() {
    // Security check: ensure user is logged in
    if ( ! is_user_logged_in() ) {
        return;
    }
    
    $current_user_id = get_current_user_id();
    $notifications = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];
    
    // Additional safety check
    if ( ! is_array( $notifications ) ) {
        return;
    }

    foreach ($notifications as &$notification) {
        if (!$notification['read']) {
            $notification['read'] = true;
            $notification['viewed_time'] = current_time('mysql');
        }
    }

    // Update the notifications after marking them as read
    update_user_meta($current_user_id, 'extrachill_notifications', $notifications);

    // After marking as read, proceed to clean up old notifications
    extrachill_cleanup_old_notifications_for_user($current_user_id);
}


add_action('bbp_new_reply', 'extrachill_capture_reply_notifications', 10, 5);
// Capture reply notifications
function extrachill_capture_reply_notifications($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author) {
    // Avoid notifying users of their own replies
    if ($reply_author == get_post_field('post_author', $topic_id)) return;

    // Fetching the topic title and reply author's display name
    $topic_title = get_the_title($topic_id);
    $reply_author_data = get_userdata($reply_author);
    $reply_author_display_name = $reply_author_data->display_name;

    // Construct the direct link to the reply
    $reply_permalink = bbp_get_reply_url($reply_id);

    // Adjust the profile link for bbPress profiles
    $user_profile_link = home_url('/u/' . $reply_author_data->user_nicename);

    // Create the reply notification array
    $notification = [
        'type'               => 'reply',
        'post_id'            => $topic_id,
        'user_id'            => $reply_author,
        'user_display_name'  => $reply_author_display_name,
        'topic_title'        => $topic_title,
        'time'               => current_time('mysql'),
        'read'               => false,
        'reply_link'         => $reply_permalink,
        'topic_link'         => get_permalink($topic_id),
        'user_profile_link'  => $user_profile_link,
    ];

    // Get the topic author (the one who will receive the notification)
    $topic_author  = get_post_field('post_author', $topic_id);
    $notifications = get_user_meta($topic_author, 'extrachill_notifications', true) ?: [];

    // 1) Check for a mention with the same post_id and user_id that happened at nearly the same time.
    //    Because timestamps can differ by a few milliseconds, allow for a small threshold (e.g. 2 seconds).
    $reply_time = strtotime($notification['time']);
    $time_threshold_seconds = 2; // Adjust as needed

    foreach ($notifications as $existing_notification) {
        if (
            $existing_notification['type'] === 'mention' &&
            isset($existing_notification['user_id']) &&
            isset($existing_notification['post_id']) &&
            isset($existing_notification['time']) &&
            // Same user who triggered it (the replier)
            $existing_notification['user_id'] == $reply_author &&
            // Same post/topic
            $existing_notification['post_id'] == $topic_id &&
            // Timestamps are close
            abs(strtotime($existing_notification['time']) - $reply_time) < $time_threshold_seconds
        ) {
            // There's already a mention notification for this event, so skip the reply notification
            return;
        }
    }

    // 2) If no near-simultaneous mention was found, proceed to add the reply notification
    $notifications[] = $notification;
    update_user_meta($topic_author, 'extrachill_notifications', $notifications);
}



add_action('bbp_new_reply', 'extrachill_capture_mention_notifications', 12, 5);
add_action('bbp_new_topic', 'extrachill_capture_mention_notifications', 12, 4);


function extrachill_capture_mention_notifications($post_id, $topic_id, $forum_id, $anonymous_data, $reply_author = 0) {
    // This function is used for both topics and replies
    $content = ($reply_author == 0) ? bbp_get_topic_content($post_id) : bbp_get_reply_content($post_id);
    $action_author_id = ($reply_author == 0) ? get_post_field('post_author', $post_id) : $reply_author; // Topic author or reply author

    // Determine the correct topic ID and title source based on context
    $actual_topic_id_for_context = ($reply_author == 0) ? $post_id : $topic_id; // If new topic, $post_id is the topic_id. If reply, $topic_id is correct.
    $actual_item_id_for_context = $post_id; // $post_id is always the item (topic or reply) where the mention occurs.

    preg_match_all('#@([0-9a-zA-Z-_]+)#i', $content, $matches);
    $usernames = array_unique($matches[1]); // Unique to avoid multiple notifications for the same mention

    foreach ($usernames as $username) {
        $user = get_user_by('slug', $username);

        if ($user && !bbp_is_user_inactive($user->ID) && $user->ID != $action_author_id) { // Check against action_author_id
            $mentioner_data = get_userdata($action_author_id);
            $mentioner_display_name = $mentioner_data ? $mentioner_data->display_name : 'Someone';
            $mentioner_profile_link = $mentioner_data ? bbp_get_user_profile_url($action_author_id) : '#';

            // Generate the mention notification with consistent actor fields
            $notification = [
                'type' => 'mention',
                'post_id' => $actual_topic_id_for_context, // The actual topic ID for overall context
                'item_id' => $actual_item_id_for_context,  // The specific post ID (topic or reply) of the mention
                'user_id' => $user->ID,        // The user being notified (mentioned user)
                'actor_id' => $action_author_id, // The user who made the mention (actor)
                'actor_display_name' => $mentioner_display_name,
                'actor_profile_link' => $mentioner_profile_link,
                'topic_title' => get_the_title($actual_topic_id_for_context), // Use actual topic ID for title
                'time' => current_time('mysql'),
                'read' => false,
                'link' => ($reply_author == 0) ? get_permalink($actual_item_id_for_context) : bbp_get_reply_url($actual_item_id_for_context), // Link to the specific mention
            ];

            // Save the notification for the mentioned user
            $notifications = get_user_meta($user->ID, 'extrachill_notifications', true) ?: [];
            $notifications[] = $notification;
            update_user_meta($user->ID, 'extrachill_notifications', $notifications);
        }
    }
}



function extrachill_format_notification_message($notification) {
    // Input validation
    if ( ! is_array( $notification ) || empty( $notification['type'] ) ) {
        return '';
    }
    
    // Set common defaults with proper sanitization
    $timeFormatted = isset($notification['time']) ? esc_html( date('n/j/y \\a\\t g:ia', strtotime($notification['time'])) ) : '';
    
    // Actor details - who performed the action
    $actor_id = $notification['actor_id'] ?? null;
    $actor_display_name = $notification['actor_display_name'] ?? 'Someone';
    $actor_profile_link = $notification['actor_profile_link'] ?? '#';

    // Recipient details (the user receiving the notification)
    // $recipient_id = $notification['user_id'] ?? get_current_user_id(); // Usually the one this notification is for

    // Avatar: Always of the actor. Fallback if actor_id is somehow missing.
    $avatar_user_id_for_message = $actor_id; 
    // Special handling for older notification types if actor_id is not set
    if (!$actor_id) {
        if (($notification['type'] === 'reply' || $notification['type'] === 'quote') && isset($notification['user_id'])) {
            // For old 'reply' and 'quote', 'user_id' was the actor.
            $avatar_user_id_for_message = $notification['user_id'];
            // Populate actor fields from old structure if not present
            $actor_display_name = $notification['user_display_name'] ?? $actor_display_name;
            $actor_profile_link = $notification['user_profile_link'] ?? $actor_profile_link;
        } elseif ($notification['type'] === 'mention' && isset($notification['mentioner_id'])) {
            // For old 'mention', 'mentioner_id' was the actor.
            // This case should ideally be covered by actor_id now, but as a fallback:
            $avatar_user_id_for_message = $notification['mentioner_id'];
            $actor_display_name = $notification['mentioner_display_name'] ?? $actor_display_name;
            // actor_profile_link would need to be derived if not explicitly set
        }
    }
    $avatar_user_id_for_message = $avatar_user_id_for_message ?: get_current_user_id(); // Ultimate fallback
    $avatar = get_avatar($avatar_user_id_for_message, 40);
    
    // Item-specific details
    $topic_title = $notification['topic_title'] ?? '';
    $link = $notification['link'] ?? ($notification['reply_link'] ?? ($notification['quote_link'] ?? '#')); // General link to the item

    switch ($notification['type']) {
        case 'reply':
            // For 'reply' type, actor_id should be the replier.
            // $notification['user_id'] is the topic author (recipient).
            // $notification['actor_id'] is the $reply_author
            // $notification['actor_display_name'] is $reply_author_display_name
            // $notification['actor_profile_link'] is replier's profile link.
            // The capture logic for 'reply' ('extrachill_capture_reply_notifications')
            // stores: 'user_id' => $reply_author, 'user_display_name' => $reply_author_display_name
            // 'user_profile_link' => $user_profile_link (of replier)
            // This means the initial actor_id, actor_display_name, actor_profile_link might be empty
            // if the notification was created *before* we added actor_id to 'reply' type.
            // The fallback logic above for !$actor_id handles this by using 'user_id' as actor.

            // Message: "[Actor] replied to your topic '[Topic Title]'"
            // Recipient: Topic Author
            // Actor: Replier (from $notification['user_id'] in old, or $notification['actor_id'] in new)

            $current_actor_id = $actor_id ?: ($notification['user_id'] ?? null);
            $current_actor_display_name = $actor_display_name ?: ($notification['user_display_name'] ?? 'Someone');
            $current_actor_profile_link = $actor_profile_link ?: ($notification['user_profile_link'] ?? '#');
            $current_avatar = get_avatar($current_actor_id, 40);


            return sprintf(
                '<div class="notification-card">
                    <div class="notification-card-header">
                        <span class="notification-type-icon"><i class="fas fa-reply"></i></span>
                        <span class="notification-timestamp">%s</span>
                    </div>
                    <div class="notification-card-body">
                        <div class="notification-avatar">%s</div>
                        <div class="notification-message">
                            <a href="%s">%s</a> replied to your topic "<a href="%s">%s</a>"
                        </div>
                    </div>
                </div>',
                $timeFormatted, // Already escaped above
                $current_avatar, // Avatar of the replier - already escaped by get_avatar()
                esc_url($current_actor_profile_link), // Link to replier's profile
                esc_html($current_actor_display_name), // Name of the replier
                esc_url($link), // Link to the reply itself
                esc_html($topic_title)
            );
        case 'quote':
            // Similar to reply, actor is the quoter.
            // Assume 'user_id' in old notifications is the quoter.
            // Message: "[Actor] quoted you in the thread '[Topic Title]'"
            $current_actor_id = $actor_id ?: ($notification['user_id'] ?? null);
            $current_actor_display_name = $actor_display_name ?: ($notification['user_display_name'] ?? 'Someone');
            $current_actor_profile_link = $actor_profile_link ?: ($notification['user_profile_link'] ?? '#');
            $current_avatar = get_avatar($current_actor_id, 40);

            return sprintf(
                '<div class="notification-card">
                    <div class="notification-card-header">
                        <span class="notification-type-icon"><i class="fas fa-quote-right"></i></span>
                        <span class="notification-timestamp">%s</span>
                    </div>
                    <div class="notification-card-body">
                        <div class="notification-avatar">%s</div>
                        <div class="notification-message">
                            <a href="%s">%s</a> quoted you in the thread "<a href="%s">%s</a>"
                        </div>
                    </div>
                </div>',
                $timeFormatted, // Already escaped above
                $current_avatar, // Avatar of the quoter - already escaped by get_avatar()
                esc_url($current_actor_profile_link), // Link to quoter's profile
                esc_html($current_actor_display_name), // Name of the quoter
                esc_url($link), // Link to the post with the quote
                esc_html($topic_title)
            );
        case 'mention':
            // Actor is the mentioner. $actor_id, $actor_display_name, $actor_profile_link are now set directly.
            // Message: "[Actor] mentioned you in '[Topic Title]'"
            return sprintf(
                '<div class="notification-card">
                    <div class="notification-card-header">
                        <span class="notification-type-icon"><i class="fas fa-at"></i></span>
                        <span class="notification-timestamp">%s</span>
                    </div>
                    <div class="notification-card-body">
                        <div class="notification-avatar">%s</div>
                        <div class="notification-message">
                            <a href="%s">%s</a> mentioned you in "<a href="%s">%s</a>"
                        </div>
                    </div>
                </div>',
                $timeFormatted, // Already escaped above
                $avatar, // Avatar of the mentioner (actor) - already escaped by get_avatar()
                esc_url($actor_profile_link), // Link to mentioner's profile
                esc_html($actor_display_name), // Name of the mentioner
                esc_url($link), // Link to the mention
                esc_html($topic_title)
            );
        case 'new_artist_topic':
            // Actor is the topic creator. $actor_id, $actor_display_name, $actor_profile_link are set.
            // Message: "[Actor] started a new topic '[Topic Title]' in your artist forum."
            return sprintf(
                '<div class="notification-card">
                    <div class="notification-card-header">
                        <span class="notification-type-icon"><i class="fas fa-comments"></i></span>
                        <span class="notification-timestamp">%s</span>
                    </div>
                    <div class="notification-card-body">
                        <div class="notification-avatar">%s</div>
                        <div class="notification-message">
                            <a href="%s">%s</a> started a new topic "<a href="%s">%s</a>" in your artist forum.
                        </div>
                    </div>
                </div>',
                $timeFormatted, // Already escaped above
                $avatar, // Avatar of the actor (topic creator) - already escaped by get_avatar()
                esc_url($actor_profile_link), // Link to actor's profile
                esc_html($actor_display_name), // Name of the actor
                esc_url($link), // Link to the topic
                esc_html($topic_title)
            );
        case 'new_artist_reply':
            // Actor is the replier. $actor_id, $actor_display_name, $actor_profile_link are set.
            // Message: "[Actor] replied in the topic '[Topic Title]' in your artist forum."
            return sprintf(
                '<div class="notification-card">
                    <div class="notification-card-header">
                        <span class="notification-type-icon"><i class="fas fa-comment-dots"></i></span>
                        <span class="notification-timestamp">%s</span>
                    </div>
                    <div class="notification-card-body">
                        <div class="notification-avatar">%s</div>
                        <div class="notification-message">
                            <a href="%s">%s</a> replied in the topic "<a href="%s">%s</a>" in your artist forum.
                        </div>
                    </div>
                </div>',
                $timeFormatted, // Already escaped above
                $avatar, // Avatar of the actor (replier) - already escaped by get_avatar()
                esc_url($actor_profile_link), // Link to actor's profile
                esc_html($actor_display_name), // Name of the actor
                esc_url($link), // Link to the reply
                esc_html($topic_title)
            );
        default:
            return ''; // Handle unknown types or extend with more cases as needed
    }
}




function extrachill_display_notifications() {
    global $extrachill_notifications_cache; // Access global cache

    $current_user_id = get_current_user_id();

    // Check if notifications are cached
    if ($extrachill_notifications_cache === null) {
        // Fallback: fetch notifications if not cached (should not happen ideally)
        $extrachill_notifications_cache = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];
    }
    $notifications = $extrachill_notifications_cache; // Use cached notifications

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
        echo '<h2>New Notifications</h2><div class="extrachill-notifications">';
        foreach ($new_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
        }
        echo '</div>';
    }

    extrachill_mark_notifications_as_read();

    $old_notifications = array_filter($filtered_notifications, function ($notification) {
        return $notification['read'];
    });

    if (!empty($old_notifications)) {
        echo '<h2>Previously Viewed</h2><div class="extrachill-notifications">';
        foreach ($old_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
        }
        echo '</div>';
    }
}


function extrachill_cleanup_old_notifications_for_user($user_id) {
    // Security check: validate user ID
    $user_id = (int) $user_id;
    if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
        return;
    }
    
    $notifications = get_user_meta($user_id, 'extrachill_notifications', true) ?: [];
    
    // Safety check
    if ( ! is_array( $notifications ) ) {
        return;
    }

    $currentTime = current_time('timestamp');
    $oneWeekAgo = strtotime('-1 week', $currentTime);

    $notifications = array_filter($notifications, function($notification) use ($oneWeekAgo) {
        if (!empty($notification['read'])) {
            if (!empty($notification['viewed_time'])) {
                $viewedTime = strtotime($notification['viewed_time']);
                return $viewedTime > $oneWeekAgo;
            }
            return false; // If read but no viewed_time, consider it for removal
        }
        return true; // Keep all unread notifications
    });

    // Update the notifications after cleaning up old ones
    update_user_meta($user_id, 'extrachill_notifications', $notifications);
}

/**
 * Notifies artist members of new topics in their artist forum.
 * Topic author will not be notified by this function.
 */
function bp_notify_artist_members_new_topic($topic_id, $forum_id, $anonymous_data, $topic_author_id) {
    // Check if $forum_id is an artist forum by looking for associated artist_profile_id
    $artist_profile_id = get_post_meta($forum_id, '_associated_artist_profile_id', true);
    error_log(sprintf("[Artist Notifications - New Topic] Forum ID: %s, Found Artist Profile ID: %s", $forum_id, $artist_profile_id ?: 'Not Found'));

    if (empty($artist_profile_id)) {
        return; // Not a band forum
    }

    // Get artist member IDs from the artist_profile CPT meta
    $artist_member_ids = get_post_meta($artist_profile_id, '_artist_member_ids', true);
    if (empty($artist_member_ids) || !is_array($artist_member_ids)) {
        error_log(sprintf("[Artist Notifications - New Topic] Artist Profile ID: %s. No artist_member_ids found or not an array. Value: %s", $artist_profile_id, print_r($artist_member_ids, true)));
        return; // No artist members found or meta is not an array
    }
    error_log(sprintf("[Artist Notifications - New Topic] Artist Profile ID: %s. Found %d artist member(s). IDs: %s", $artist_profile_id, count($artist_member_ids), implode(', ', $artist_member_ids)));

    // Get topic author data for the notification message
    $topic_author_data = get_userdata($topic_author_id);
    if (!$topic_author_data) {
        error_log(sprintf("[Artist Notifications - New Topic] Could not get userdata for topic author ID: %s. Artist Profile ID: %s", $topic_author_id, $artist_profile_id));
        return; 
    }
    $actor_display_name = $topic_author_data->display_name;
    $actor_profile_link = bbp_get_user_profile_url($topic_author_id);
    error_log(sprintf("[Artist Notifications - New Topic] Topic Author ID: %s, Display Name: %s. Artist Profile ID: %s", $topic_author_id, $actor_display_name, $artist_profile_id));


    $topic_title = get_the_title($topic_id);
    $topic_link = get_permalink($topic_id);

    // Loop through artist members and send notifications
    foreach ($artist_member_ids as $member_id) {
        $member_id = (int)$member_id; // Ensure it's an integer
        if ($member_id === 0) {
            error_log(sprintf("[Artist Notifications - New Topic] Skipped member_id 0. Original value before casting was part of: %s. Artist Profile ID: %s", print_r($artist_member_ids, true), $artist_profile_id));
            continue;
        }


        if ($member_id === (int)$topic_author_id) {
            error_log(sprintf("[Artist Notifications - New Topic] Member ID %d is the topic author %d. Skipping. Artist Profile ID: %s", $member_id, (int)$topic_author_id, $artist_profile_id));
            continue; // Don't notify the author of their own topic via this mechanism
        }

        $notification = [
            'type'               => 'new_artist_topic',
            'post_id'            => $topic_id,       
            'user_id'            => $member_id,      
            'actor_id'           => (int)$topic_author_id, 
            'actor_display_name' => $actor_display_name,
            'actor_profile_link' => $actor_profile_link,
            'topic_title'        => $topic_title,
            'time'               => current_time('mysql'),
            'read'               => false,
            'link'               => $topic_link,
        ];

        // Get current notifications for this artist member and add the new one
        $member_notifications = get_user_meta($member_id, 'extrachill_notifications', true) ?: [];
        $member_notifications[] = $notification;
        update_user_meta($member_id, 'extrachill_notifications', $member_notifications);
        error_log(sprintf("[Artist Notifications - New Topic] Added notification for Member ID: %d. Artist Profile ID: %s", $member_id, $artist_profile_id));
    }
}


/**
 * Notifies artist members of new replies in their artist forum topics.
 * Reply author will not be notified by this function.
 */
function bp_notify_artist_members_new_reply($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author_id) {
    // Check if $forum_id is an artist forum by looking for associated artist_profile_id
    $artist_profile_id = get_post_meta($forum_id, '_associated_artist_profile_id', true);
    error_log(sprintf("[Artist Notifications - New Reply] Forum ID: %s, Found Artist Profile ID: %s", $forum_id, $artist_profile_id ?: 'Not Found'));

    if (empty($artist_profile_id)) {
        return; // Not an artist forum
    }

    // Get artist member IDs from the artist_profile CPT meta
    $artist_member_ids = get_post_meta($artist_profile_id, '_artist_member_ids', true);
    if (empty($artist_member_ids) || !is_array($artist_member_ids)) {
        error_log(sprintf("[Artist Notifications - New Reply] Artist Profile ID: %s. No artist_member_ids found or not an array. Value: %s", $artist_profile_id, print_r($artist_member_ids, true)));
        return; // No artist members found or meta is not an array
    }
    error_log(sprintf("[Artist Notifications - New Reply] Artist Profile ID: %s. Found %d artist member(s). IDs: %s", $artist_profile_id, count($artist_member_ids), implode(', ', $artist_member_ids)));

    // Get reply author data for the notification message
    $reply_author_data = get_userdata($reply_author_id);
    if (!$reply_author_data) {
        error_log(sprintf("[Artist Notifications - New Reply] Could not get userdata for reply author ID: %s. Artist Profile ID: %s", $reply_author_id, $artist_profile_id));
        return; 
    }
    $actor_display_name = $reply_author_data->display_name;
    $actor_profile_link = bbp_get_user_profile_url($reply_author_id);
    error_log(sprintf("[Artist Notifications - New Reply] Reply Author ID: %s, Display Name: %s. Artist Profile ID: %s", $reply_author_id, $actor_display_name, $artist_profile_id));


    $topic_title = get_the_title($topic_id);
    $reply_link = bbp_get_reply_url($reply_id);

    // Loop through artist members and send notifications
    foreach ($artist_member_ids as $member_id) {
        $member_id = (int)$member_id; // Ensure it's an integer
        if ($member_id === 0) {
            error_log(sprintf("[Artist Notifications - New Reply] Skipped member_id 0. Original value before casting was part of: %s. Artist Profile ID: %s", print_r($artist_member_ids, true), $artist_profile_id));
            continue;
        }

        if ($member_id === (int)$reply_author_id) {
            error_log(sprintf("[Artist Notifications - New Reply] Member ID %d is the reply author %d. Skipping. Artist Profile ID: %s", $member_id, (int)$reply_author_id, $artist_profile_id));
            continue; // Don't notify the author of their own reply via this mechanism
        }

        $notification = [
            'type'               => 'new_artist_reply',
            'post_id'            => $topic_id,     
            'item_id'            => $reply_id,     
            'user_id'            => $member_id,     
            'actor_id'           => (int)$reply_author_id, 
            'actor_display_name' => $actor_display_name,
            'actor_profile_link' => $actor_profile_link,
            'topic_title'        => $topic_title,
            'time'               => current_time('mysql'),
            'read'               => false,
            'link'               => $reply_link,
        ];

        // Get current notifications for this artist member and add the new one
        $member_notifications = get_user_meta($member_id, 'extrachill_notifications', true) ?: [];
        $member_notifications[] = $notification;
        update_user_meta($member_id, 'extrachill_notifications', $member_notifications);
        error_log(sprintf("[Artist Notifications - New Reply] Added notification for Member ID: %d. Artist Profile ID: %s", $member_id, $artist_profile_id));
    }
}


// Hook artist notification functions to bbPress actions
add_action('bbp_new_topic', 'bp_notify_artist_members_new_topic', 20, 4);
add_action('bbp_new_reply', 'bp_notify_artist_members_new_reply', 20, 5);