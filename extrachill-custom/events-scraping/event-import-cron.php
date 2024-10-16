<?php
/**
 * Add a custom 'weekly' cron schedule.
 */
add_filter('cron_schedules', 'extra_chill_add_weekly_cron_schedule');
function extra_chill_add_weekly_cron_schedule($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array(
            'interval' => 604800, // 1 week in seconds
            'display'  => __('Once Weekly', 'textdomain'),
        );
    }
    return $schedules;
}

/**
 * Schedule the weekly event import if not already scheduled.
 */
// add_action('init', 'extra_chill_register_weekly_event_import');
function extra_chill_register_weekly_event_import() {
    if (!wp_next_scheduled('import_weekly_events_hook')) {
        wp_schedule_event(time(), 'weekly', 'import_weekly_events_hook');
        error_log('Scheduled import_weekly_events_hook at ' . date('Y-m-d H:i:s', time()));
    } else {
        $next_run = wp_next_scheduled('import_weekly_events_hook');
        error_log(message: 'import_weekly_events_hook is already scheduled to run at ' . date('Y-m-d H:i:s', $next_run));
    }
}

/**
 * Hook the automated event import function to the scheduled event.
 */
add_action('import_weekly_events_hook', 'extra_chill_automated_event_import');
function extra_chill_automated_event_import() {
    error_log('automated_event_import triggered at ' . date('Y-m-d H:i:s'));
    
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

