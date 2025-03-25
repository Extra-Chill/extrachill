<?php

/**
 * Add a custom 'daily' cron schedule.
 */
add_filter('cron_schedules', 'extra_chill_add_daily_cron_schedule');
function extra_chill_add_daily_cron_schedule($schedules) {
    if (!isset($schedules['daily'])) {
        $schedules['daily'] = array(
            'interval' => 86400, // 1 day in seconds
            'display'  => __('Once Daily', 'extrachill'),
        );
    }
    return $schedules;
}

/**
 * Schedule the daily event import if not already scheduled.
 */
add_action('after_switch_theme', 'extra_chill_register_daily_event_import');
function extra_chill_register_daily_event_import() {
    // Unschedule the old weekly event if it exists
    if (wp_next_scheduled('import_weekly_events_hook')) {
        wp_clear_scheduled_hook('import_weekly_events_hook');
        error_log('Cleared old weekly event schedule.');
    }

    // Schedule the new daily event
    if (!wp_next_scheduled('import_daily_events_hook')) {
        wp_schedule_event(time(), 'daily', 'import_daily_events_hook');
        error_log('Scheduled import_daily_events_hook at ' . date('Y-m-d H:i:s', time()));
    } else {
        $next_run = wp_next_scheduled('import_daily_events_hook');
        error_log('import_daily_events_hook is already scheduled to run at ' . date('Y-m-d H:i:s', $next_run));
    }
}

/**
 * Hook the automated event import function to the new daily scheduled event.
 */
add_action('import_daily_events_hook', 'extra_chill_automated_event_import');

function extra_chill_automated_event_import() {
    error_log('automated_event_import triggered at ' . date('Y-m-d H:i:s'));

    // Post locally scraped events
    $maxEvents = 100; // Adjust based on expected volume for local events
    $localEvents = post_aggregated_events_to_calendar($maxEvents);
    if (is_wp_error($localEvents)) {
        error_log('Error posting local events: ' . $localEvents->get_error_message());
    } else {
        $addedCount = count($localEvents);
        log_import_event('cron scraping', $addedCount);
    }

    // Post Ticketmaster events
    $maxTicketmasterEvents = 50; // Adjust based on how many Ticketmaster events you want to handle
    $ticketmasterEvents = post_ticketmaster_events_to_calendar($maxTicketmasterEvents, 'cron ticketmaster');
    if (is_wp_error($ticketmasterEvents)) {
        error_log('Error posting Ticketmaster events: ' . $ticketmasterEvents->get_error_message());
    } else {
        $totalPosted = count($ticketmasterEvents);
        log_import_event('cron ticketmaster', $totalPosted);
    }

    // **Post DICE.FM events (for Austin)**
    $maxDiceEvents = 50; // Adjust as needed based on your expected volume
    $diceEvents = post_dice_fm_events_to_calendar($maxDiceEvents);
    if (is_wp_error($diceEvents)) {
        error_log('Error posting DICE.FM events: ' . $diceEvents->get_error_message());
    } else {
        $diceCount = count($diceEvents);
        log_import_event('cron dice', $diceCount);
    }
}




