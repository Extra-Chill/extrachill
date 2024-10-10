<?php

// ticketmaster-event-imports.php

function fetch_ticketmaster_events() {
    $api_key = 'v5wKistoszPIZpmK4z0w1Ysf2qhbGb0T';
    $classificationName = 'Music';
    $startDateTime = date('Y-m-d\TH:i:s\Z');

    $events = [];
    $processedEventIds = []; // Track IDs of processed events to handle duplicates
    $page = 0;
    $size = 20;
    $totalPages = 1;

    while ($page < $totalPages) {
        $url = "https://app.ticketmaster.com/discovery/v2/events.json?apikey={$api_key}&classificationName={$classificationName}&startDateTime={$startDateTime}&size={$size}&page={$page}&geoPoint=32.7765,-79.9311&radius=50&unit=miles&includeVenues=true";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch events from Ticketmaster', ['status' => 500]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['_embedded']['events'])) {
            return new WP_Error('invalid_json', 'Invalid JSON format from Ticketmaster API', ['status' => 500]);
        }

        foreach ($data['_embedded']['events'] as $event) {
            if (empty($event['dates']['start']['dateTime']) || $event['dates']['start']['dateTime'] === 'TBA' || in_array($event['id'], $processedEventIds)) {
                continue;
            }

            $processedEventIds[] = $event['id']; // Mark this event ID as processed

            $dateTimeZoneNewYork = new DateTimeZone('America/New_York');
            $startTimeUTC = $event['dates']['start']['dateTime'];
            $startTime = new DateTime($startTimeUTC ? $startTimeUTC : 'now', new DateTimeZone('UTC'));
            $startTime->setTimezone($dateTimeZoneNewYork);

            $endTime = clone $startTime;
            $endTime->modify('+3 hours');

            $venue = $event['_embedded']['venues'][0] ?? [];
            $priceRange = $event['priceRanges'][0] ?? ['min' => 'N/A', 'max' => 'N/A', 'currency' => 'N/A'];

            $events[] = [
                'id' => $event['id'],
                'title' => $event['name'],
                'start_date' => $startTime->format('Y-m-d H:i:s'),
                'end_date' => $endTime->format('Y-m-d H:i:s'),
                'url' => $event['url'],
                'venue' => [
                    'name' => $venue['name'] ?? 'N/A',
                    'address' => $venue['address']['line1'] ?? 'N/A',
                    'city' => $venue['city']['name'] ?? 'N/A',
                    'state' => $venue['state']['stateCode'] ?? 'N/A',
                    'country' => $venue['country']['name'] ?? 'N/A',
                ],
                'price_range' => [
                    'min' => $priceRange['min'],
                    'max' => $priceRange['max'],
                    'currency' => $priceRange['currency']
                ],
                'ticket_link' => $event['url'], // Assumed ticket link is same as event URL
            ];
        }

        $totalPages = $data['page']['totalPages'];
        $page++;
    }

    return new WP_REST_Response($events, 200);
}

add_action('rest_api_init', function () {
    register_rest_route('ticketmaster/v1', '/events', [
        'methods' => 'GET',
        'callback' => 'fetch_ticketmaster_events',
        'permission_callback' => '__return_true'  // This line allows public access.
    ]);
});

function post_ticketmaster_events_to_calendar($maxEvents = 5) {
    $fetched_events = fetch_ticketmaster_events();
    if (is_wp_error($fetched_events)) {
        return $fetched_events;
    }

    $events_data = $fetched_events->get_data();
    if (empty($events_data)) {
        return new WP_Error('no_events', 'No events found to post.', ['status' => 404]);
    }

    $postedEventIds = get_option('posted_ticketmaster_event_ids', []); // Retrieve posted event IDs from WP options
    $api_endpoint = get_site_url() . '/wp-json/tribe/events/v1/events';
    $posted_events = [];
    $count = 0;

    foreach ($events_data as $event) {
        if ($count >= $maxEvents || in_array($event['id'], $postedEventIds)) {
            continue; // Skip this event if it's already been posted
        }

        $venueDetails = isset($event['venue']) ? [
            'venue' => $event['venue']['name'],
            'address' => $event['venue']['address'],
            'city' => $event['venue']['city'],
            'country' => $event['venue']['country'],
            'state' => $event['venue']['state'],
            'zip' => $event['venue']['zip'] ?? '',
            'website' => $event['venue']['website'] ?? '',
            'phone' => $event['venue']['phone'] ?? '',
        ] : [];

        $decodedUrl = urldecode($event['ticket_link']);
        $properUrl = esc_url_raw($decodedUrl); // Ensure the URL is properly encoded

        $eventData = [
            'title' => $event['title'],
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'url' => $properUrl, // Ensure URL is properly encoded for posting
            'venue' => $venueDetails,
            'price_range' => $event['price_range'],
            'website' => $properUrl, // Use the encoded URL
        ];
        error_log('Posting URL: ' . $eventData['website']);

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
            $posted_events[] = [
                'error' => $response->get_error_message(),
                'title' => $event['title'],
            ];
        } else {
            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = wp_remote_retrieve_response_code($response);
            if ($responseCode >= 200 && $responseCode < 300) {
                $postedEventIds[] = $event['id']; // Add this event ID to the array of posted IDs
                update_option('posted_ticketmaster_event_ids', $postedEventIds); // Update the option with the new list
                $posted_events[] = [
                    'title' => $event['title'],
                    'venue' => $event['venue']['name'],
                    'start_date' => $event['start_date'],
                ];
                $count++;
            } else {
                $posted_events[] = [
                    'error' => "Failed posting: Response Code: $responseCode; Response: $responseBody",
                    'title' => $event['title'],
                ];
            }
        }
    }

    // Log the result
    log_import_event('ticketmaster', $count);

    return $posted_events;
}




function update_posted_event_ids_on_deletion($post_id) {
    // Check if the post is an event post (adjust the type as necessary)
    if (get_post_type($post_id) === 'tribe_events') {
        $posted_ids = get_option('posted_ticketmaster_event_ids', []);
        $event_id = get_post_meta($post_id, '_EventID', true); // Assuming you store Ticketmaster's event ID as post meta

        // Remove the event ID from the array of posted IDs
        if (($key = array_search($event_id, $posted_ids)) !== false) {
            unset($posted_ids[$key]);
            update_option('posted_ticketmaster_event_ids', array_values($posted_ids)); // Update the posted IDs

            // Delete all meta associated with the event
            $meta_keys = ['_EventVenueID', '_EventOrganizerID', '_EventPrice']; // Add more meta keys as needed
            foreach ($meta_keys as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }

            // Optionally log the cleanup process
            error_log("Cleaned up meta for deleted event ID: {$event_id}");
        }
    }
}
add_action('before_delete_post', 'update_posted_event_ids_on_deletion');