<?php

add_filter('cron_schedules', 'add_weekly_cron_schedule');
function add_weekly_cron_schedule($schedules) {
    $schedules['weekly'] = array(
        'interval' => 604800, // 1 week in seconds
        'display'  => __('Once Weekly'),
    );
    return $schedules;
}

add_action('wp_loaded', 'register_weekly_event_import'); // Changed from 'wp' to 'wp_loaded' for better clarity
function register_weekly_event_import() {
    if (!wp_next_scheduled('import_weekly_events_hook')) {
        wp_schedule_event(time(), 'weekly', 'import_weekly_events_hook');
    }
}

add_action('import_weekly_events_hook', 'automated_event_import');
function automated_event_import() {
    $maxEvents = 100; // Adjust based on expected volume for local events
    $localEvents = post_aggregated_events_to_calendar($maxEvents);
    if (is_wp_error($localEvents)) {
        error_log('Error posting local events: ' . $localEvents->get_error_message());
    } else {
        error_log('Local events posted successfully.');
    }

    $maxTicketmasterEvents = 50; // Adjust based on how many Ticketmaster events you want to handle
    $ticketmasterEvents = post_ticketmaster_events_to_calendar($maxTicketmasterEvents);
    if (is_wp_error($ticketmasterEvents)) {
        error_log('Error posting Ticketmaster events: ' . $ticketmasterEvents->get_error_message());
    } else {
        error_log('Ticketmaster events posted successfully.');
    }
}


