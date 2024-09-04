<?php

// Global variable to store online user count
global $online_users_count;

function record_user_activity() {
    global $online_users_count;

    $user_id = get_current_user_id();
    if ($user_id) {
        // Update last_active for logged-in users using prepared statements
        $current_time = current_time('timestamp');
        update_user_meta_prepared($user_id, 'last_active', $current_time);
    }

    // Fetch the current online user count
    if (!isset($online_users_count)) {
        $online_users_count = get_online_users_count();
    }

    // Retrieve or initialize the most ever online record
    $most_ever_online = get_option('most_ever_online', ['count' => 0, 'date' => '']);

    // Correcting type casting to ensure proper comparison
    $most_ever_online_count = intval($most_ever_online['count']);

    if ($online_users_count > $most_ever_online_count) {
        // Update only if the current online count exceeds the "Most Ever Online"
        $most_ever_online = [
            'count' => $online_users_count,
            'date' => current_time('m/d/Y')
        ];
        update_option('most_ever_online', $most_ever_online);
    }
}
add_action('wp', 'record_user_activity');

function update_user_meta_prepared($user_id, $meta_key, $meta_value) {
    global $wpdb;
    $table = $wpdb->usermeta;

    $query = $wpdb->prepare(
        "UPDATE $table SET meta_value = %s WHERE user_id = %d AND meta_key = %s",
        $meta_value, $user_id, $meta_key
    );

    $wpdb->query($query);
}

function get_online_users_count() {
    global $wpdb;

    // Use transient to cache the result
    $transient_key = 'online_users_count';
    $online_users_count = get_transient($transient_key);

    if ($online_users_count === false) {
        $time_limit = 15 * MINUTE_IN_SECONDS; // 15 minutes ago
        $time_threshold = current_time('timestamp') - $time_limit;

        $online_users_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = 'last_active' AND meta_value > %d",
            $time_threshold
        ));

        // Cache the result for 5 minutes
        set_transient($transient_key, intval($online_users_count), 5 * MINUTE_IN_SECONDS);
    }

    return intval($online_users_count);
}

function display_online_users_stats() {
    global $online_users_count;

    if (!isset($online_users_count)) {
        $online_users_count = get_online_users_count();
    }

    $most_ever_online = get_option('most_ever_online', ['count' => 0, 'date' => '']);

    // Ensure we have the 'count' as an integer and 'date' as a string
    $most_ever_online_count = isset($most_ever_online['count']) ? (int)$most_ever_online['count'] : 0;
    $most_ever_online_date = isset($most_ever_online['date']) ? date('m/d/Y', strtotime($most_ever_online['date'])) : 'N/A';

    // Use WP_User_Query to get a total count of users
    $user_query = new WP_User_Query(['count_total' => true, 'fields' => 'ID']);
    $total_members = $user_query->get_total();

    echo "<div class='online-stats'>";
    echo "<p><span class='label'>Users Currently Online:</span> <span class='count'>" . $online_users_count . "</span></p>";
    echo "<p><span class='label'>Most Ever Online:</span> <span class='count'>" . $most_ever_online_count . "</span> on <span class='date'>" . $most_ever_online_date . "</span></p>";
    // Add Total Members to the output
    echo "<p><span class='label'>Total Members:</span> <span class='count'>" . $total_members . "</span></p>";
    echo "</div>";
}
