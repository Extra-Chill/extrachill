<?php
/** Optional ticketing integrations for the isolated publication-email suite. */

function extrachill_events_user_can_manage_event( $user_id, $event_id ) {
	return ! empty( $GLOBALS['event_mail_managers'][ $user_id ][ $event_id ] );
}

function extrachill_events_guest_list_host_tools_url( $event_id, $anchor, $args = array() ) {
	return 'https://events.example/event/' . $event_id . '/guest-list/?ec_host_tools=1#' . $anchor;
}

function data_machine_events_is_gated_ticket_url( $url ) {
	return in_array( parse_url( $url, PHP_URL_HOST ), array( 'www.ticketmaster.com', 'www.ticketweb.com' ), true );
}

function ec_commerce_fee_settings( $context = array() ) {
	$GLOBALS['event_mail_fee_context'] = $context;
	return $GLOBALS['event_mail_fees'];
}
