<?php

add_action('admin_menu', 'register_custom_menu_page');

function register_custom_menu_page() {
    add_menu_page(
        'Post Events', // Page title
        'Post Events', // Menu title
        'manage_options', // Capability
        'post-events', // Menu slug
        'post_events_admin_page', // Function to display the page
        '', // Icon URL
        6 // Position
    );
}

function post_events_admin_page() {
    echo '<div class="wrap"><h1>Post Events to Calendar</h1>';

    // Form for specifying max events to post for scraped events
    echo '<form method="get" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="post-events" />';
    echo '<label for="max_events">Maximum number of events to post (Scraped Events): </label>';
    echo '<input type="number" id="max_events" name="max_events" min="1" style="width: 80px;" value="5">';  // Default to 5
    echo '<input type="submit" name="action" value="post_scraped_events" class="button button-primary" />';
    echo '</form>';

    // New form for specifying max events to post for Ticketmaster events
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="post-events" />';
    echo '<label for="max_tm_events">Maximum number of events to post (Ticketmaster Events): </label>';
    echo '<input type="number" id="max_tm_events" name="max_tm_events" min="1" style="width: 80px;" value="5">'; // Default to 5
    echo '<input type="submit" name="action" value="post_tm_events" class="button button-primary" />';
    echo '</form>';

    // Handle posting scraped events
    if (isset($_GET['action']) && $_GET['action'] == 'post_scraped_events') {
        $maxEvents = isset($_GET['max_events']) ? intval($_GET['max_events']) : 5; // Default to 5 if not specified
        // Replace with your function to post scraped events
        $results = post_aggregated_events_to_calendar($maxEvents);
        echo get_posting_results($results);
    }
    // Handle posting Ticketmaster events
    elseif (isset($_GET['action']) && $_GET['action'] == 'post_tm_events') {
        $maxEvents = isset($_GET['max_tm_events']) ? intval($_GET['max_tm_events']) : 5; // Default to 5 if not specified
        // Replace with your function to fetch and post Ticketmaster events
        $results = post_ticketmaster_events_to_calendar($maxEvents);
        echo get_posting_results($results);
    } else {
        echo '<p>Enter the maximum number of events you wish to post and click the appropriate "Post Events" button.</p>';
    }

    // Display the last 5 import logs
    echo '<h2>Last 5 Imports</h2>';
    $logs = get_option('event_import_logs', []);
    if (!empty($logs)) {
        echo '<ul>';
        foreach (array_reverse($logs) as $log) {
            echo '<li>' . esc_html($log['timestamp']) . ' - ' . esc_html($log['source']) . ' - ' . esc_html($log['num_events']) . ' events</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>No import logs available.</p>';
    }
}


function get_posting_results($results) {
    if (is_wp_error($results)) {
        return '<p>Error: ' . $results->get_error_message() . '</p>';
    } else if (empty($results)) {
        return '<p>No new, non-duplicate events found.</p>';
    } else {
        $output = '<div>';
        foreach ($results as $result) {
            if (isset($result['error'])) {
                $eventName = $result['title'] ?? 'Unknown Event';
                $errorDescription = $result['error'] ?? 'No specific error message provided';
                $output .= '<p>Error posting event: <strong>' . htmlspecialchars($eventName) . '</strong> - ' . htmlspecialchars($errorDescription) . '</p>';
            } else {
                $title = $result['title'] ?? 'N/A';
                $venueName = is_array($result['venue']) ? $result['venue']['venue'] ?? 'N/A' : 'N/A';
                $startDate = $result['start_date'] ?? 'N/A';

                $output .= '<p>Event posted successfully:</p>';
                $output .= '<ul>';
                $output .= '<li>Title: ' . htmlspecialchars($title) . '</li>';
                $output .= '<li>Venue: ' . htmlspecialchars($venueName) . '</li>';
                $output .= '<li>Date: ' . htmlspecialchars($startDate) . '</li>';
                $output .= '</ul>';
            }
        }
        $output .= '</div>';
        return $output;
    }
}




function log_import_event($source, $num_events) {
    $logs = get_option('event_import_logs', []);

    // Add new log entry
    $logs[] = [
        'source' => $source,
        'num_events' => $num_events,
        'timestamp' => current_time('mysql')
    ];

    // Keep only the last 5 logs
    if (count($logs) > 5) {
        $logs = array_slice($logs, -5);
    }

    update_option('event_import_logs', $logs);
}





