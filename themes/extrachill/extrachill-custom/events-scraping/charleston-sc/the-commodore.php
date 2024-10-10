<?php

require_once get_template_directory() . '/vendor/autoload.php';

use ICal\ICal;

function get_commodore_events() {
    $url = 'https://tockify.com/api/feeds/ics/thecommodorechs';

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

        foreach ($events as $event) {
            // Create DateTime objects for start and end times
            $startDateTime = new DateTime($event->dtstart);
            $endDateTime = new DateTime($event->dtend);

            // Manually adjust time by subtracting four hours to align with local expected time
            $startDateTime->modify('-4 hours');
            $endDateTime->modify('-4 hours');

            // Convert title to have only the first letter of each word capitalized
            $title = ucwords(strtolower($event->summary));

            // Check if the event already exists
            if (!event_already_exists($title, $startDateTime->format('Y-m-d H:i:s'))) {
                $formatted_events[] = [
                    'title'       => $title,
                    'description' => $event->description,
                    'start_date'  => $startDateTime->format('Y-m-d H:i:s'),
                    'end_date'    => $endDateTime->format('Y-m-d H:i:s'),
                    'url'         => $event->url,
                    'venue'       => [
                        'name' => 'The Commodore',
                        'address' => '504 Meeting St Suite C',
                        'city' => 'Charleston',
                        'state' => 'South Carolina',
                        'zip' => '29403',
                        'website' => 'https://thecommodorechs.com/music.php'
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

