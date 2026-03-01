<?php
/**
 * Archive Title
 *
 * Customize archive page titles for the events calendar.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize archive title hooks
 */
function extrachill_events_init_archive_title() {
	add_filter( 'data_machine_events_archive_title', 'extrachill_events_filter_archive_title', 10, 2 );
}

/**
 * Filter archive title to use "Live Music Calendar" instead of "Events Calendar"
 *
 * @param string $title   Archive title.
 * @param string $context Archive context.
 * @return string Modified archive title.
 */
function extrachill_events_filter_archive_title( $title, $context ) {
	$suffix = 'Events Calendar';

	if ( $title === $suffix ) {
		return 'Live Music Calendar';
	}

	if ( strlen( $title ) > strlen( $suffix ) && substr( $title, -strlen( $suffix ) ) === $suffix ) {
		$prefix = rtrim( substr( $title, 0, -strlen( $suffix ) ) );
		return $prefix . ' Live Music Calendar';
	}

	return $title;
}
