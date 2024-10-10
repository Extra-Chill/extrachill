<?php

function get_burgundy_lounge_events() {
    $url = 'https://www.starlightchs.com/events';

    error_log("Starting event scrape from: $url");

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $htmlContent = curl_exec($ch);
    curl_close($ch);

    if (!$htmlContent) {
        error_log("Failed to retrieve HTML content.");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $paragraphs = $xpath->query("//div[contains(@class, 'u_1058095290')]//p");

    if (!$paragraphs || $paragraphs->length === 0) {
        error_log("No paragraphs found with the specified class.");
        return [];
    }

    $events = [];
    $eventDetails = [];
    $currentDate = '';
    foreach ($paragraphs as $paragraph) {
        $text = trim($paragraph->textContent);

        if (empty($text)) {
            if (!empty($eventDetails)) {
                $event = processBurgundyLoungeDetails($eventDetails, $currentDate);
                if ($event) {
                    $events[] = $event;
                }
                $eventDetails = [];
            }
            continue;
        }

        if (preg_match('/^[A-Za-z]+, \w+ \d+$/', $text)) {
            $currentDate = DateTime::createFromFormat('l, F d Y', $text . ' ' . date('Y'))->format('Y-m-d');
        } else {
            $eventDetails[] = $text;
        }
    }

    if (!empty($eventDetails)) {
        $event = processBurgundyLoungeDetails($eventDetails, $currentDate);
        if ($event) {
            $events[] = $event;
        }
    }

    return $events;
}

function processBurgundyLoungeDetails($eventDetails, $currentDate) {
    // Extract event name and time details
    $eventName = implode(' ', array_slice($eventDetails, 0, -1));
    $eventTimeText = end($eventDetails);

// Extract start and end times with more flexible regex
if (!preg_match('/(\d+)-(\d+)([ap]m)?/', $eventTimeText, $matches)) {
    error_log("Invalid time format: '{$eventTimeText}' for event: '{$eventName}'");
    return false;
}

$startTime = (int)$matches[1];
$endTime = (int)$matches[2];
$endPeriod = strtolower($matches[3] ?? 'pm'); // Default to PM if not specified


    // Assume start time is always PM and adjust it accordingly
    if ($startTime < 12) $startTime += 12;

    // Adjust end time based on whether it's AM or PM
    if ($endPeriod === 'p' && $endTime < 12) {
        $endTime += 12; // Convert PM end time if it is not in 24-hour format
    } else if ($endPeriod === 'a' && $endTime === 12) {
        $endTime = 0; // Convert 12 AM to 00 hours
    }

    // Format start and end times
    $startDateFormatted = $currentDate . ' ' . str_pad($startTime, 2, '0', STR_PAD_LEFT) . ':00';
    $endDateFormatted = $currentDate . ' ' . str_pad($endTime, 2, '0', STR_PAD_LEFT) . ':00';

    // If the end time is less than the start time, assume the event spans to the next day
    if ($endTime <= $startTime) {
        $endDateFormatted = date('Y-m-d H:i:s', strtotime($endDateFormatted . ' +1 day'));
    }

    return [
        'title' => $eventName,
        'start_date' => $startDateFormatted,
        'end_date' => $endDateFormatted,
        'venue' => [
            'name' => 'The Burgundy Lounge',
            'address' => '3245 Rivers Ave.',
            'city' => 'North Charleston',
            'state' => 'SC',
            'zip' => '29405'
        ]
    ];
}


