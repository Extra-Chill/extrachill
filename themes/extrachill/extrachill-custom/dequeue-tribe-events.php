<?php
/**
 * Detect Tribe Events page
 * @link https://wordpress.stackexchange.com/questions/340515/writing-a-function-to-detect-an-event
 */
function is_tribe_calendar() {
	if ((function_exists('tribe_is_event') && tribe_is_event()) || 
	    (function_exists('tribe_is_event_category') && tribe_is_event_category()) || 
	    (function_exists('tribe_is_in_main_loop') && tribe_is_in_main_loop()) || 
	    (function_exists('tec_is_view') && tec_is_view()) || 
	    'tribe_events' == get_post_type() || 
	    is_singular( 'tribe_events' )) {
		
		return true;
		
	}
	else {
		return false;
	}
}

/**
 * Dequque styles unless Tribe events
 */
function tribe_dequeue_styles() {
	if ( is_tribe_calendar() ) {
		// Do nothing
	}
	else {
		// Calendar styles
		wp_dequeue_style('dashicons');
		wp_dequeue_style('tribe-accessibility-css');
		wp_dequeue_style('tribe-events-full-calendar-style');
		wp_dequeue_style('tribe-events-custom-jquery-styles');
		wp_dequeue_style('tribe-events-bootstrap-datepicker-css');
		wp_dequeue_style('tribe-events-calendar-style');
		wp_dequeue_style('tribe-events-calendar-full-mobile-style');
		wp_dequeue_style('tribe-tooltip');
		wp_dequeue_style('event-tickets-plus-tickets-css');
		// Ticket styles
		wp_dequeue_style('event-tickets-rsvp');
		wp_dequeue_style('event-tickets-tpp-css');
	}
}
add_action( 'wp_print_styles', 'tribe_dequeue_styles', 100 );

/**
 * Dequque scripts unless Tribe events
 */
function tribe_dequeue_scripts() {
	if ( is_tribe_calendar() ) {
		// Do nothing
	}
	else {
		// Calendar scripts
		wp_dequeue_script('tribe-events-bootstrap-datepicker');
		wp_dequeue_script('tribe-events-jquery-resize');
		wp_dequeue_script('tribe-events-calendar-script');
		wp_dequeue_script('tribe-events-php-date-formatter');
		wp_dequeue_script('tribe-moment');
		wp_dequeue_script('tribe-events-dynamic');
		wp_dequeue_script('tribe-events-bar');
		wp_dequeue_script('the-events-calendar');
		wp_dequeue_script('jquery-deparam');
		wp_dequeue_script('event-tickets-attendees-list-js');
		wp_dequeue_script('event-tickets-plus-attendees-list-js');
		wp_dequeue_script('jquery-cookie');
		wp_dequeue_script('event-tickets-plus-meta-js');
		// Ticket scripts
		wp_dequeue_script('event-tickets-rsvp');
		wp_dequeue_script('event-tickets-tpp-js');
		wp_dequeue_script('jquery-ui-core');
		wp_dequeue_script('jquery-ui-datepicker');
	
	}
}
add_action( 'wp_print_scripts', 'tribe_dequeue_scripts', 100 );

