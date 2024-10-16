<?php
/**
 * ticketmaster-event-imports.php
 * 
 * Handles fetching events from Ticketmaster and posting them to The Events Calendar.
 * Ensures venue data is correctly formatted as an array and location taxonomy is assigned.
 */

/**
 * Retrieve the list of event locations.
 *
 * @return array An array of associative arrays containing location details.
 */
function get_event_locations() {
    return [
        [
            'name'      => 'Charleston',
            'latitude'  => 32.7765,
            'longitude' => -79.9311,
            'slug'      => 'charleston',
            'term_id'   => 2693, // Known term ID for Charleston
        ],
        // Add more locations here as needed.
    ];
}

/**
 * Retrieve the Ticketmaster API key from wp-config.php.
 *
 * @return string The Ticketmaster API key.
 */
function get_ticketmaster_api_key() {
    return defined('TICKETMASTER_API_KEY') ? TICKETMASTER_API_KEY : '';
}

/**
 * Retrieve the Events Calendar API authorization from wp-config.php.
 *
 * @return string The Events Calendar API authorization string.
 */
function get_events_calendar_auth() {
    return defined('EVENTS_CALENDAR_AUTH') ? EVENTS_CALENDAR_AUTH : '';
}

/**
 * Fetch Ticketmaster events for a given location.
 *
 * @param array $location An associative array containing 'name', 'latitude', 'longitude', 'slug', 'term_id'.
 * @return WP_REST_Response|WP_Error
 */
function fetch_ticketmaster_events($location) {
    $api_key = get_ticketmaster_api_key();
    if (empty($api_key)) {
        error_log("Ticketmaster API key is not defined.");
        return new WP_Error('no_api_key', 'Ticketmaster API key is not defined.', ['status' => 500]);
    }

    $classificationName = 'Music';
    $startDateTime = gmdate('Y-m-d\TH:i:s\Z'); // Use GMT time as per Ticketmaster API requirements.

    $events = [];
    $processedEventIds = []; // Track IDs of processed events to handle duplicates.
    $page = 0;
    $size = 20;
    $totalPages = 1;

    while ($page < $totalPages) {
        // Original URL construction method
        $url = "https://app.ticketmaster.com/discovery/v2/events.json?apikey={$api_key}&classificationName={$classificationName}&startDateTime={$startDateTime}&size={$size}&page={$page}&geoPoint={$location['latitude']},{$location['longitude']}&radius=50&unit=miles&includeVenues=true";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            error_log("Failed to fetch events from Ticketmaster for location '{$location['name']}': " . $response->get_error_message());
            return new WP_Error('fetch_failed', 'Failed to fetch events from Ticketmaster', ['status' => 500]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['_embedded']['events'])) {
            error_log("Invalid JSON format from Ticketmaster API for location '{$location['name']}'.");
            return new WP_Error('invalid_json', 'Invalid JSON format from Ticketmaster API', ['status' => 500]);
        }

        foreach ($data['_embedded']['events'] as $event) {
            if (empty($event['dates']['start']['dateTime']) || $event['dates']['start']['dateTime'] === 'TBA' || in_array($event['id'], $processedEventIds)) {
                continue;
            }

            $processedEventIds[] = $event['id']; // Mark this event ID as processed.

            $dateTimeZoneNewYork = new DateTimeZone('America/New_York');
            $startTimeUTC = $event['dates']['start']['dateTime'];
            $startTime = new DateTime($startTimeUTC ? $startTimeUTC : 'now', new DateTimeZone('UTC'));
            $startTime->setTimezone($dateTimeZoneNewYork);

            $endTime = clone $startTime;
            $endTime->modify('+3 hours'); // Adjust duration as needed.

            $venue = $event['_embedded']['venues'][0] ?? [];
            $priceRange = $event['priceRanges'][0] ?? ['min' => 'N/A', 'max' => 'N/A', 'currency' => 'N/A'];

            // Ensure all necessary venue fields are present
            $venue_data = [
                'venue'    => $venue['name'] ?? 'N/A',
                'address'  => isset($venue['address']['line1']) ? $venue['address']['line1'] : 'N/A',
                'city'     => isset($venue['city']['name']) ? $venue['city']['name'] : 'N/A',
                'state'    => isset($venue['state']['name']) ? $venue['state']['name'] : 'N/A',
                'country'  => isset($venue['country']['name']) ? $venue['country']['name'] : 'N/A',
                'zip'      => isset($venue['postalCode']) ? $venue['postalCode'] : 'N/A',
                'website'  => isset($venue['url']) ? esc_url_raw($venue['url']) : '',
                'phone'    => isset($venue['boxOfficeInfo']['phoneNumberDetail']) ? $venue['boxOfficeInfo']['phoneNumberDetail'] : '',
            ];

            $events[] = [
                'id'             => $event['id'],
                'title'          => $event['name'],
                'start_date'     => $startTime->format('Y-m-d H:i:s'),
                'end_date'       => $endTime->format('Y-m-d H:i:s'),
                'url'            => isset($event['url']) ? esc_url_raw($event['url']) : '',
                'venue'          => $venue_data, // Now an array with required keys
                'price_range'    => [
                    'min'      => $priceRange['min'],
                    'max'      => $priceRange['max'],
                    'currency' => $priceRange['currency'],
                ],
                'ticket_link'    => isset($event['url']) ? esc_url_raw($event['url']) : '',
                'location_slug'  => $location['slug'], // Add location slug for taxonomy assignment.
                'location_term_id' => $location['term_id'], // Add location term ID
            ];
        }

        $totalPages = isset($data['page']['totalPages']) ? intval($data['page']['totalPages']) : 1;
        $page++;
    }

    return new WP_REST_Response($events, 200);
}

add_action('rest_api_init', function () {
    register_rest_route('ticketmaster/v1', '/events', [
        'methods'             => 'GET',
        'callback'            => 'fetch_ticketmaster_events_handler',
        'permission_callback' => '__return_true', // This line allows public access.
    ]);
});

/**
 * REST API handler to fetch events for a specific location.
 * Accepts a 'location_slug' parameter to specify the location.
 *
 * Example API call: /wp-json/ticketmaster/v1/events?location_slug=charleston
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function fetch_ticketmaster_events_handler(WP_REST_Request $request) {
    $location_slug = sanitize_text_field($request->get_param('location_slug'));

    if (empty($location_slug)) {
        error_log("No location specified in the API request.");
        return new WP_Error('no_location', 'No location specified.', ['status' => 400]);
    }

    $locations = get_event_locations();
    $location = null;
    foreach ($locations as $loc) {
        if ($loc['slug'] === $location_slug) {
            $location = $loc;
            break;
        }
    }

    if (!$location) {
        error_log("Invalid location specified: '{$location_slug}'.");
        return new WP_Error('invalid_location', 'Invalid location specified.', ['status' => 400]);
    }

    $events = fetch_ticketmaster_events($location);
    return $events;
}

/**
 * Post Ticketmaster events to The Events Calendar.
 *
 * @param int    $maxEvents Maximum number of events to post per location.
 * @param string $source    The source of the import (e.g., 'cron ticketmaster', 'manual ticketmaster').
 * @return array|WP_Error
 */
function post_ticketmaster_events_to_calendar($maxEvents = 5, $source = 'ticketmaster') {
    $locations = get_event_locations();
    if (empty($locations)) {
        error_log("No event locations defined.");
        return new WP_Error('no_locations', 'No event locations defined.', ['status' => 500]);
    }

    $postedEventIds = get_option('posted_ticketmaster_event_ids', []); // Retrieve posted event IDs from WP options.
    $api_endpoint  = get_site_url() . '/wp-json/tribe/events/v1/events';
    $posted_events = [];
    $total_posted  = 0;

    foreach ($locations as $location) {
        $fetched_events = fetch_ticketmaster_events($location);
        if (is_wp_error($fetched_events)) {
            error_log("Error fetching events for location '{$location['name']}': " . $fetched_events->get_error_message());
            continue;
        }

        $events_data = $fetched_events->get_data();
        if (empty($events_data)) {
            error_log("No events found for location '{$location['name']}'.");
            continue;
        }

        foreach ($events_data as $event) {
            if ($total_posted >= $maxEvents || in_array($event['id'], $postedEventIds)) {
                continue; // Skip this event if it's already been posted or maxEvents reached.
            }

            // Directly use the known term ID for 'charleston' (2693)
            $location_term_id = $event['location_term_id'];
            if (empty($location_term_id)) {
                error_log("Location term ID is missing for event '{$event['title']}'.");
                continue;
            }

            $eventData = [
                'title'      => $event['title'],
                'start_date' => $event['start_date'],
                'end_date'   => $event['end_date'],
                'url'        => $event['url'],
                'venue'      => $event['venue'], // Now an array with required keys
                'price_range'=> $event['price_range'],
                'website'    => $event['ticket_link'], // Assuming this is a URL
                // Remove 'tax_input' to assign taxonomy manually
            ];

            // Optional: Include 'description' if available
            if (isset($event['description'])) {
                $eventData['description'] = sanitize_textarea_field($event['description']);
            }

            // Debugging: Log the event data being sent
            error_log('Posting Event Data: ' . print_r($eventData, true));

            $response = wp_remote_post($api_endpoint, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(get_events_calendar_auth()), // Securely retrieve auth from wp-config.php
                    'Content-Type'  => 'application/json',
                ],
                'body'        => wp_json_encode($eventData),
                'method'      => 'POST',
                'data_format' => 'body',
            ]);

            if (is_wp_error($response)) {
                error_log("Failed to post event '{$event['title']}': " . $response->get_error_message());
                $posted_events[] = [
                    'error' => $response->get_error_message(),
                    'title' => $event['title'],
                ];
                continue;
            }

            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = wp_remote_retrieve_response_code($response);

            if ($responseCode >= 200 && $responseCode < 300) {
                // Parse the response to get the created event's ID
                $responseData = json_decode($responseBody, true);
                if (isset($responseData['id'])) {
                    $created_event_id = intval($responseData['id']);

                    // Assign the 'location' taxonomy to the created event using wp_set_object_terms()
                    $assign_taxonomy = wp_set_object_terms($created_event_id, [$location_term_id], 'location', false);
                    if (is_wp_error($assign_taxonomy)) {
                        error_log("Failed to assign location taxonomy to event ID {$created_event_id}: " . $assign_taxonomy->get_error_message());
                    } else {
                        error_log("Successfully assigned location taxonomy to event ID {$created_event_id}.");
                    }

                    // Update posted event IDs
                    $postedEventIds[] = $event['id'];
                    update_option('posted_ticketmaster_event_ids', $postedEventIds);

                    $posted_events[] = [
                        'title'      => $event['title'],
                        'venue'      => isset($event['venue']['venue']) ? $event['venue']['venue'] : 'N/A', // Handle both array and string
                        'start_date' => $event['start_date'],
                    ];
                    $total_posted++;
                } else {
                    error_log("Event ID not found in the response for event titled '{$event['title']}'. Response: {$responseBody}");
                    $posted_events[] = [
                        'error' => "Event ID not found in the response.",
                        'title' => $event['title'],
                    ];
                }
            } else {
                error_log("Failed to post event '{$event['title']}': Response Code: {$responseCode}; Response: {$responseBody}");
                $posted_events[] = [
                    'error' => "Failed posting: Response Code: {$responseCode}; Response: {$responseBody}",
                    'title' => $event['title'],
                ];
            }
        }
    }


    // Log the result
    log_import_event('ticketmaster', $total_posted);
    
    return $posted_events;
}

/**
 * Handle deletion of posts by removing their event IDs from the posted list.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function update_posted_event_ids_on_deletion($post_id) {
    // Check if the post is an event post.
    if (get_post_type($post_id) === 'tribe_events') {
        $posted_ids = get_option('posted_ticketmaster_event_ids', []);
        $event_id   = get_post_meta($post_id, '_EventID', true); // Assuming you store Ticketmaster's event ID as post meta.

        // Remove the event ID from the array of posted IDs.
        if (($key = array_search($event_id, $posted_ids)) !== false) {
            unset($posted_ids[$key]);
            update_option('posted_ticketmaster_event_ids', array_values($posted_ids)); // Update the posted IDs.

            // Delete all meta associated with the event.
            $meta_keys = ['_EventVenueID', '_EventOrganizerID', '_EventPrice']; // Add more meta keys as needed.
            foreach ($meta_keys as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }

            // Log the cleanup process.
            error_log("Cleaned up meta for deleted event ID: {$event_id}");
        }
    }
}
add_action('before_delete_post', 'update_posted_event_ids_on_deletion');
