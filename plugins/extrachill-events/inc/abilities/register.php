<?php
/**
 * Events-Domain Abilities Registration
 *
 * Registers the extrachill-events ability category and loads all
 * events-domain ability files.  Each file self-registers on the
 * wp_abilities_api_init hook.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_events_register_abilities_category' );

/**
 * Register the events-domain ability category.
 */
function extrachill_events_register_abilities_category(): void {
	wp_register_ability_category(
		'extrachill-events',
		array(
			'label'       => __( 'Extra Chill Events', 'extrachill-events' ),
			'description' => __( 'Events calendar, filters, geocoding, venue lookup, submissions, and upcoming-count queries.', 'extrachill-events' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/events-calendar.php';
require_once __DIR__ . '/events-filters.php';
require_once __DIR__ . '/events-geocode.php';
require_once __DIR__ . '/events-upcoming-counts.php';
require_once __DIR__ . '/events-submit.php';
require_once __DIR__ . '/events-list-venues.php';
require_once __DIR__ . '/events-get-venue.php';
require_once __DIR__ . '/events-check-venue-duplicate.php';
