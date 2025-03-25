<?php

function get_royal_american_events() {
    $url = 'http://www.theroyalamerican.com/schedule';

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $htmlContent = curl_exec($ch);
    curl_close($ch);

    if (!$htmlContent) {
        error_log("Failed to fetch Royal American events.");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $eventsNodes = $xpath->query("//article[contains(@class, 'eventlist-event')]");

    $events = [];
    $currentYear = date('Y');

    foreach ($eventsNodes as $node) {
        $eventUrl = $xpath->evaluate("string(.//a[@class='eventlist-title-link']/@href)", $node);
        $title = $xpath->evaluate("string(.//h1[@class='eventlist-title']/a)", $node);
        
        // Extract the month and day, fallback to empty if missing
        $month = trim($xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--month'])", $node));
        $day = trim($xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--day'])", $node));

        // Check if the date components are valid
        if (empty($month) || empty($day)) {
            error_log("Skipping event due to missing date: " . $title);
            continue;
        }

        // Extract and format start date
        $startDateText = "$month $day $currentYear";
        $startTimeText = trim($xpath->evaluate("string(.//time[@class='event-time-12hr'][1])", $node));

        // Set a default time if missing
        if (empty($startTimeText)) {
            error_log("Missing event time for: $title. Assigning default 8:00 PM.");
            $startTimeText = "8:00 PM";
        }

        // Convert to datetime format
        $startDate = date('Y-m-d H:i:s', strtotime("$startDateText $startTimeText"));
        $endDate = date('Y-m-d H:i:s', strtotime($startDate) + 3600 * 5);

        // Extract and clean description
        $descriptionHtml = $xpath->evaluate("string(.//div[@class='eventlist-description'])", $node);
        $description = html_entity_decode(strip_tags($descriptionHtml, '<a><br>'));

        // Skip past events
        if (new DateTime($startDate) < new DateTime()) {
            error_log("Skipping past event: $title on $startDate");
            continue;
        }

        // Define venue details
        $venueDetails = [
            'venue' => [
                'id' => 'the-royal-american',
                'name' => 'The Royal American',
                'address' => '970 Morrison Drive',
                'city' => 'Charleston',
                'country' => 'USA',
                'state' => 'SC',
                'zip' => '29403',
                'website' => 'http://www.theroyalamerican.com',
                'phone' => '(843) 817-6925',
            ]
        ];

        // Build the event array
        $event = [
            'title' => trim($title),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'description' => trim($description),
            'url' => 'http://www.theroyalamerican.com' . $eventUrl,
            'venue' => $venueDetails['venue']
        ];

        // Log for debugging
        error_log("Scraped event: " . print_r($event, true));

        $events[] = $event;
    }

    return $events;
}

