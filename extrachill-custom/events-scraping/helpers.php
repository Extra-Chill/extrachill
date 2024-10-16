<?php
/**
 * helpers.php
 * 
 * Contains helper functions for event aggregation and posting.
 */

/**
 * Check if an event already exists based on title and start date.
 *
 * @param string $title      The title of the event.
 * @param string $start_date The start date of the event in 'Y-m-d H:i:s' format.
 *
 * @return bool True if the event exists, false otherwise.
 */
function event_already_exists($title, $start_date) {
    global $wpdb;

    // Prepare title for case-insensitive search.
    $title_like = '%' . $wpdb->esc_like($title) . '%';
    $start_date_like = '%' . $wpdb->esc_like(date('Y-m-d', strtotime($start_date))) . '%';

    // SQL query to find matching events.
    $query = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'tribe_events' AND p.post_status = 'publish'
        AND pm.meta_key = '_EventStartDate' AND pm.meta_value LIKE %s
        AND p.post_title LIKE %s",
        $start_date_like,
        $title_like
    );

    $matches = $wpdb->get_var($query);

    return ($matches > 0);
}

/**
 * Filter events to include only future events.
 *
 * @param array $events An array of events.
 *
 * @return array An array of future events.
 */
function filter_future_events($events) {
    return array_filter($events, function($event) {
        $eventDate = strtotime($event['start_date']);
        return $eventDate > current_time('timestamp');
    });
}