<?php

// this code is used to display recent activity from the community site
function custom_human_time_diff($from, $to = '') {
    if (empty($to)) {
        $to = time();
    }
    $diff = (int) abs($to - $from);

    if ($diff < MINUTE_IN_SECONDS) {
        $since = sprintf(_n('%ss', '%ss', $diff), $diff);
    } elseif ($diff < HOUR_IN_SECONDS) {
        $minutes = floor($diff / MINUTE_IN_SECONDS);
        $since = sprintf(_n('%sm', '%sm', $minutes), $minutes);
    } elseif ($diff < DAY_IN_SECONDS) {
        $hours = floor($diff / HOUR_IN_SECONDS);
        $since = sprintf(_n('%sh', '%sh', $hours), $hours);
    } elseif ($diff < WEEK_IN_SECONDS) {
        $days = floor($diff / DAY_IN_SECONDS);
        $since = sprintf(_n('%sd', '%sd', $days), $days);
    } elseif ($diff < MONTH_IN_SECONDS) {
        $weeks = floor($diff / WEEK_IN_SECONDS);
        $since = sprintf(_n('%sw', '%sw', $weeks), $weeks);
    } elseif ($diff < YEAR_IN_SECONDS) {
        $months = floor($diff / MONTH_IN_SECONDS);
        $since = sprintf(_n('%smon', '%smon', $months), $months);
    } else {
        $years = floor($diff / YEAR_IN_SECONDS);
        $since = sprintf(_n('%syr', '%syr', $years), $years);
    }
    return $since . __(' ago');
}

function extrachill_recent_activity_shortcode() {
    // Set a transient name
    $transient_name = 'extrachill_recent_activity';
    $activities = get_transient($transient_name);

    // If the transient does not exist, fetch the data from the API
    if ($activities === false) {
        $request_url = 'https://community.extrachill.com/wp-json/extrachill/v1/recent-activity';
        $response = wp_remote_get($request_url);

        if (is_wp_error($response)) {
            return 'Could not retrieve recent activity.';
        }

        $activities = json_decode(wp_remote_retrieve_body($response), true);

        // Set a transient for 10 minutes to cache the API response
        set_transient($transient_name, $activities, 10 * MINUTE_IN_SECONDS);
    }

    $output = '<div class="extrachill-recent-activity">';
    if (!empty($activities)) {
        $output .= '<ul>';
        $counter = 0; // Initialize counter for unique ID
        foreach ($activities as $activity) {
            $dateFormatted = custom_human_time_diff(strtotime($activity['date_time']));
            $counter++; // Increment counter for each activity
            if ($activity['type'] === 'Reply') {
                $output .= sprintf(
                    '<li><a href="%s">%s</a> replied to <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</li>',
                    esc_url($activity['user_profile_url']),
                    esc_html($activity['username']),
                    $counter, // Unique ID for the topic link
                    esc_url($activity['topic_url']),
                    esc_html($activity['topic_title']),
                    esc_url($activity['forum_url']),
                    esc_html($activity['forum_title']),
                    $dateFormatted
                );
            } else { // Topic
                $output .= sprintf(
                    '<li><a href="%s">%s</a> posted <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</li>',
                    esc_url($activity['user_profile_url']),
                    esc_html($activity['username']),
                    $counter, // Unique ID for the topic link
                    esc_url($activity['topic_url']),
                    esc_html($activity['topic_title']),
                    esc_url($activity['forum_url']),
                    esc_html($activity['forum_title']),
                    $dateFormatted
                );
            }
        }
        $output .= '</ul>';
    } else {
        $output .= 'No recent activity found.';
    }
    
    // Ensure the "Visit Community" button has a unique ID as well
    $output .= '<a id="visit-community" href="https://community.extrachill.com"><button>Visit Community</button></a>';
    $output .= '</div>';

    return $output;
}

add_shortcode('extrachill_recent_activity', 'extrachill_recent_activity_shortcode');
