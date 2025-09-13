<?php
// home/community-activity.php - Community Activity for 3x3 Grid

// Set a transient name
$transient_name = 'extrachill_recent_activity';
$activities = get_transient($transient_name);

// If the transient does not exist, fetch the data from the API
if ($activities === false) {
    $request_url = 'https://community.extrachill.com/wp-json/extrachill/v1/recent-activity';
    $response = wp_remote_get($request_url);

    if (is_wp_error($response)) {
        echo '<div class="home-3x3-card home-3x3-empty">Could not retrieve recent activity.</div>';
        return;
    }

    $activities = json_decode(wp_remote_retrieve_body($response), true);

    // Set a transient for 10 minutes to cache the API response
    set_transient($transient_name, $activities, 10 * MINUTE_IN_SECONDS);
}

if (!empty($activities)) {
    $counter = 0;
    foreach ($activities as $activity) {
        if (!is_array($activity) || $counter >= 3) {
            continue;
        }
        
        $dateFormatted = custom_human_time_diff(strtotime($activity['date_time']));
        $counter++;
        
        if ($activity['type'] === 'Reply') {
            printf(
                '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> replied to <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                esc_url($activity['user_profile_url']),
                esc_html($activity['username']),
                $counter,
                esc_url($activity['topic_url']),
                esc_html($activity['topic_title']),
                esc_url($activity['forum_url']),
                esc_html($activity['forum_title']),
                $dateFormatted
            );
        } else { // Topic
            printf(
                '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> posted <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                esc_url($activity['user_profile_url']),
                esc_html($activity['username']),
                $counter,
                esc_url($activity['topic_url']),
                esc_html($activity['topic_title']),
                esc_url($activity['forum_url']),
                esc_html($activity['forum_title']),
                $dateFormatted
            );
        }
    }
} else {
    echo '<div class="home-3x3-card home-3x3-empty">No recent activity.</div>';
}
?>