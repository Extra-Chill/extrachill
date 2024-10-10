<?php

function get_forte_jazz_lounge_events() {
    $url = 'https://forte-jazz-lounge.turntabletickets.com';

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $htmlContent = curl_exec($ch);
    curl_close($ch);

    if (!$htmlContent) {
        return ['error' => 'Failed to fetch HTML content'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $eventsNodes = $xpath->query("//div[contains(@class, 'details')]");

    if ($eventsNodes->length === 0) {
        return ['error' => 'No event nodes found'];
    }

    $events = [];
    $currentYear = date('Y'); // Assuming events are for the current year, adjust as needed

    foreach ($eventsNodes as $node) {
        $title = $xpath->evaluate("string(.//h3[contains(@class, 'font-heading')])", $node);
        $eventUrl = $xpath->evaluate("string(.//a/@href)", $node);
        $dateText = $xpath->evaluate("string(.//h4[@class='day-of-week'])", $node);

        if (empty($title) || empty($eventUrl) || empty($dateText)) {
            continue; // Skip if any required field is missing
        }

        // Remove the day of the week from dateText
        $dateText = str_replace(['Mon, ', 'Tue, ', 'Wed, ', 'Thu, ', 'Fri, ', 'Sat, ', 'Sun, '], '', $dateText);
        $date = date('Y-m-d', strtotime($dateText . ' ' . $currentYear));

        // Extract start times
        $startTimeNodes = $xpath->query(".//button[contains(@class, 'performance-btn')]", $node);
        if ($startTimeNodes->length === 0) {
            continue; // Skip if no start time is found
        }

        $startTimes = [];
        foreach ($startTimeNodes as $timeNode) {
            $startTimeText = trim($timeNode->nodeValue);
            if (preg_match('/(\d{1,2}:\d{2}\s?(AM|PM))/i', $startTimeText, $matches)) {
                $startTimes[] = date('H:i:s', strtotime($matches[0]));
            }
        }

        if (empty($startTimes)) {
            continue; // Skip if no valid start times are found
        }

        // Calculate start and end times
        $startDateTime = $date . ' ' . $startTimes[0];
        $endDateTime = isset($startTimes[1]) ? date('Y-m-d H:i:s', strtotime($date . ' ' . $startTimes[1]) + 3600 * 2) : date('Y-m-d H:i:s', strtotime($startDateTime) + 3600 * 2);

        $descriptionHtml = $xpath->evaluate("string(.//p[@id[contains(., 'description')]])", $node);
        $description = html_entity_decode(strip_tags($descriptionHtml, '<a><br>')); // Allow only links and line breaks

        // Skip events that have already occurred
        if (new DateTime($startDateTime) < new DateTime()) {
            continue;
        }

        // Venue details properly structured for the API's expected format
        $venueDetails = [
            'venue' => [
                'id' => 'forte-jazz-lounge',
                'name' => 'Forte Jazz Lounge',
                'address' => '477 King St',
                'city' => 'Charleston',
                'country' => 'USA',
                'state' => 'SC',
                'zip' => '29403',
                'website' => 'https://forte-jazz-lounge.turntabletickets.com',
                'phone' => '', // Add phone number if available
            ]
        ];

        $event = [
            'title' => trim($title),
            'start_date' => $startDateTime,
            'end_date' => $endDateTime,
            'description' => trim($description),
            'url' => 'https://forte-jazz-lounge.turntabletickets.com' . $eventUrl,
            'venue' => $venueDetails['venue'] // Assigning the structured venue details here
        ];

        $events[] = $event;
    }

    return $events;
}
