<?php
/**
 * ExtraChill Events Home Action Hooks
 *
 * Hook-based homepage component registration system. The homepage is a
 * location directory (grouped by state) — no calendar on the homepage.
 * Individual city pages have their own calendars.
 *
 * @package ExtraChillEvents
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the location directory data builder.
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/location-directory.php';
