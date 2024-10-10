<?php

// collect-scraped-events.php

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/all-events/', array(
        'methods' => 'GET',
        'callback' => 'get_scraped_events',
        'permission_callback' => '__return_true'  // Ensures the route is publicly accessible

    ));
});

function get_scraped_events(WP_REST_Request $request) {
    $postEvents = $request->get_param('post');

    if (!is_null($postEvents) && filter_var($postEvents, FILTER_VALIDATE_BOOLEAN)) {
        $postResults = post_aggregated_events_to_calendar();
        return rest_ensure_response($postResults);
    } else {
        $events = aggregate_venue_events();
        return rest_ensure_response($events);
    }
}


function aggregate_venue_events() {
    $allEvents = [];
    $venueScrapers = [
      'get_royal_american_events',
      'get_commodore_events',
     'get_burgundy_lounge_events',
     'get_tin_roof_events',
       'get_forte_jazz_lounge_events',
    ];

    foreach ($venueScrapers as $scraper) {
        if (function_exists($scraper)) {
            $venueEvents = call_user_func($scraper);
            if (is_string($venueEvents)) { // Check if the result is an error message
                error_log("Error in $scraper: $venueEvents");
            } elseif (is_array($venueEvents)) {
                $allEvents = array_merge($allEvents, $venueEvents);
            } else {
                error_log("Unexpected result type from $scraper.");
            }
        }
    }

    return $allEvents;
}

