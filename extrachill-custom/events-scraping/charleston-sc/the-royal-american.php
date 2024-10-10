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
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $eventsNodes = $xpath->query("//article[contains(@class, 'eventlist-event')]");

    $events = [];
    $currentYear = date('Y'); // Assuming events are for the current year, adjust as needed

    foreach ($eventsNodes as $node) {
        $eventUrl = $xpath->evaluate("string(.//a[@class='eventlist-title-link']/@href)", $node);
        $title = $xpath->evaluate("string(.//h1[@class='eventlist-title']/a)", $node);
        $startDateText = $xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--month'])", $node) . " " .
                         $xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--day'])", $node) . " " .
                         $currentYear;
        $startTimeText = $xpath->evaluate("string(.//time[@class='event-time-12hr'][1])", $node);

        $startDate = date('Y-m-d H:i:s', strtotime($startDateText . ' ' . $startTimeText));
        $endDate = date('Y-m-d H:i:s', strtotime($startDate) + 3600 * 5); // Assuming the event ends the same day, adjust as needed

        $descriptionHtml = $xpath->evaluate("string(.//div[@class='eventlist-description'])", $node);
        $description = html_entity_decode(strip_tags($descriptionHtml, '<a><br>')); // Allow only links and line breaks

        // Skip events that have already occurred
        if (new DateTime($startDate) < new DateTime()) {
            continue;
        }

        // Venue details properly structured for the API's expected format
        $venueDetails = [
            'venue' => [
                'id' => 'the-royal-american', // Example ID, replace with actual ID if available
                'name' => 'The Royal American',
                'address' => '970 Morrison Drive',
                'city' => 'Charleston',
                'country' => 'USA',
                'state' => 'SC',
                'zip' => '29403',
                'website' => 'http://www.theroyalamerican.com',
                'phone' => '(843) 817-6925',
                // Add or adjust fields to match your API's expected venue structure
            ]
        ];

        $event = [
            'title' => trim($title),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'description' => trim($description),
            'url' => 'http://www.theroyalamerican.com' . $eventUrl,
            'venue' => $venueDetails['venue'] // Assigning the structured venue details here
        ];

        $events[] = $event;
    }

    return $events;
}
