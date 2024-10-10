<?php

require_once get_template_directory() . '/vendor/autoload.php';

use ICal\ICal;

function get_tin_roof_events() {
    $url = 'https://tockify.com/api/feeds/ics/tinroofschedule';

    try {
        $ical = new ICal($url, array(
            'defaultSpan' => 2,
            'defaultTimeZone' => 'America/New_York',
            'defaultWeekStart' => 'MO',
            'skipRecurrence' => false,
            'useTimeZoneWithRRules' => true,
        ));

        $events = $ical->eventsFromRange('now');
        $formatted_events = [];
        $excluded_keywords = ["Happy Hour", "Brunch", "Pop-Up", "Karaoke", "Comedy", "Open Mic"];

        foreach ($events as $event) {
            $includeEvent = true;
            foreach ($excluded_keywords as $keyword) {
                if (strpos($event->summary, $keyword) !== false) {
                    $includeEvent = false;
                    break;
                }
            }

            if ($includeEvent) {
                $title = preg_replace('/\.?\s*Doors\s*@\s*\d+(:\d+)?\s*.*$/i', '', $event->summary);
                
                // Create DateTime objects for start and end times
                $startDateTime = new DateTime($event->dtstart);
                $endDateTime = new DateTime($event->dtend);

                // Manually adjust time by subtracting four hours to align with local expected time
                // This adjustment is necessary due to persistent offset issues with the source data
                $startDateTime->modify('-4 hours');
                $endDateTime->modify('-4 hours');

                $formatted_events[] = [
                    'title'       => $title,
                    'description' => $event->description,
                    'start_date'  => $startDateTime->format('Y-m-d H:i:s'),
                    'end_date'    => $endDateTime->format('Y-m-d H:i:s'),
                    'url'         => $event->url,
                    'venue'       => [
                        'name' => 'Charleston Tin Roof',
                        'address' => '1117 Magnolia Road',
                        'city' => 'Charleston',
                        'state' => 'South Carolina',
                        'zip' => '29407',
                        'website' => 'https://charlestontinroof.com/schedule'
                    ],
                ];
            }
        }

        return $formatted_events;
    } catch (Exception $e) {
        error_log('Failed to fetch or parse iCalendar feed: ' . $e->getMessage());
        return [];
    }
}



