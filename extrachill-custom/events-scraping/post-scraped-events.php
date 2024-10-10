<?php

// post-scraped-events.php

function post_aggregated_events_to_calendar($maxEvents = 5) {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    $events = aggregate_venue_events();
    if (!is_array($events) || empty($events)) {
        return new WP_Error('no_events', 'No events found to post.', array('status' => 404));
    }

    $events = filter_future_events($events); // Get future events without slicing.

    $api_endpoint = get_site_url() . '/wp-json/tribe/events/v1/events';
    $results = [];
    $addedCount = 0; // Counter for successfully added events

    foreach ($events as $event) {
        // Stop trying if we've added enough events.
        if ($addedCount >= $maxEvents) {
            break;
        }

        if (event_already_exists($event['title'], $event['start_date'])) {
            continue; // Skip to the next event without depleting the count.
        }

        $eventData = [
            'title' => $event['title'],
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'url' => $event['url'] ?? '',
            'venue' => [
                'venue' => $event['venue']['name'],
                'address' => $event['venue']['address'],
                'city' => $event['venue']['city'],
                'country' => $event['venue']['country'] ?? '',
                'state' => $event['venue']['state'],
                'zip' => $event['venue']['zip'],
                'website' => $event['venue']['website'] ?? '',
                'phone' => $event['venue']['phone'] ?? '',
            ]
        ];

        if (isset($event['description'])) {
            $eventData['description'] = $event['description'];
        }

        $response = wp_remote_post($api_endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('chubes:7qpX gQty xGKO Id1x StsM KuOD'),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($eventData),
            'method' => 'POST',
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
        } else {
            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = wp_remote_retrieve_response_code($response);
            if ($responseCode >= 200 && $responseCode < 300) {
                $addedCount++; // Increment only on successful addition.
                $results[] = json_decode($responseBody, true);
            } else {
            }
        }
    }

    // If no events were added and all were duplicates.
    if ($addedCount == 0 && !empty($events)) {
    }
    // Log the result
    log_import_event('scraping', $addedCount);

    return $results;
}




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

    if ($matches > 0) {
        return true;
    } else {
        return false;
    }
}






function filter_future_events($events) {
    return array_filter($events, function($event) {
        $eventDate = strtotime($event['start_date']);
        return $eventDate > current_time('timestamp');
    });
}


