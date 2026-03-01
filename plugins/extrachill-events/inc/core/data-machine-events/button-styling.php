<?php
/**
 * Button Styling
 *
 * Map data-machine-events buttons to theme button classes.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize button styling hooks
 */
function extrachill_events_init_button_styling() {
	add_filter( 'data_machine_events_modal_button_classes', 'extrachill_events_add_modal_button_classes', 10, 2 );
	add_filter( 'data_machine_events_ticket_button_classes', 'extrachill_events_add_ticket_button_classes', 10, 1 );
	add_filter( 'data_machine_events_more_info_button_classes', 'extrachill_events_add_more_info_button_classes', 10, 1 );
}

/**
 * Add theme button classes to modal buttons
 *
 * Maps WordPress admin button classes (button-primary/secondary) to theme
 * button styling classes. Primary buttons get button-1 (blue accent) with
 * large size, secondary buttons get button-3 (neutral) with medium size.
 *
 * @param array  $classes     Default button classes from data-machine-events.
 * @param string $button_type Button type ('primary' or 'secondary').
 * @return array Enhanced button classes with theme styling.
 */
function extrachill_events_add_modal_button_classes( $classes, $button_type ) {
	switch ( $button_type ) {
		case 'primary':
			$classes[] = 'button-1';
			$classes[] = 'button-large';
			break;
		case 'secondary':
			$classes[] = 'button-3';
			$classes[] = 'button-medium';
			break;
	}
	return $classes;
}

/**
 * Add theme button classes to ticket button
 *
 * Applies primary theme button styling (button-1) with large size
 * to ticket purchase links for prominent call-to-action appearance.
 *
 * @param array $classes Default button classes from data-machine-events.
 * @return array Enhanced button classes with theme styling.
 */
function extrachill_events_add_ticket_button_classes( $classes ) {
	$classes[] = 'button-1';
	$classes[] = 'button-large';
	return $classes;
}

/**
 * Add theme button classes to more info button
 *
 * Applies neutral theme button styling (button-3) with small size
 * to calendar card "More Info" links for secondary call-to-action appearance.
 *
 * @param array $classes Default button classes from data-machine-events.
 * @return array Enhanced button classes with theme styling.
 */
function extrachill_events_add_more_info_button_classes( $classes ) {
	$classes[] = 'button-3';
	$classes[] = 'button-small';
	return $classes;
}
