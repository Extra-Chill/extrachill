<?php
/**
 * DataMachine Events Integration Loader
 *
 * Loads all integration modules and initializes their hooks.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/taxonomy-registration.php';
require_once __DIR__ . '/badge-styling.php';
require_once __DIR__ . '/button-styling.php';
require_once __DIR__ . '/archive-title.php';
require_once __DIR__ . '/post-meta.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/promoter-badges.php';
require_once __DIR__ . '/venue-promos.php';

/**
 * Initialize all DataMachine Events integrations
 */
function extrachill_events_init_data_machine_integration() {
	extrachill_events_init_taxonomy_registration();
	extrachill_events_init_badge_styling();
	extrachill_events_init_button_styling();
	extrachill_events_init_archive_title();
	extrachill_events_init_post_meta();
	extrachill_events_init_assets();
	extrachill_events_init_promoter_badges();
	extrachill_events_init_venue_promos();
}
