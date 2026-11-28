<?php
/**
 * Ad-free event utility pages.
 *
 * Pages that work like a ticket or a tool, not like content: the guest list
 * page (/events/{slug}/guest-list) and the door's pass check-in page
 * (/rsvp-verify/). A guest pulling up a pass in a dark venue line, or a
 * host scanning at the door, should never get an ad.
 *
 * Event managers also never see ads on the event pages of shows they manage:
 * it is their own show, and they are working it, not browsing it.
 *
 * Uses the network ad policy's `extrachill_should_block_ads` filter, which
 * both the policy (extrachill_get_ad_policy) and the ad blocklist output
 * honor. Every event gets this automatically; a new ad-free event surface
 * adds its request check to extrachill_events_is_ad_free_page().
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is an event utility page that never shows ads.
 *
 * @return bool
 */
function extrachill_events_is_ad_free_page(): bool {
	if ( function_exists( 'extrachill_events_ticket_return_state' ) && '' !== extrachill_events_ticket_return_state() ) {
		return true;
	}

	if ( function_exists( 'extrachill_events_is_guest_list_request' ) && extrachill_events_is_guest_list_request() ) {
		return true;
	}

	if ( function_exists( 'extrachill_events_is_rsvp_verify_page' ) && extrachill_events_is_rsvp_verify_page() ) {
		return true;
	}

	if ( extrachill_events_is_managed_event_page() ) {
		return true;
	}

	return false;
}

/**
 * Whether the current request is an event page the current user manages.
 *
 * @return bool
 */
function extrachill_events_is_managed_event_page(): bool {
	if ( ! is_singular( 'data_machine_events' ) || ! function_exists( 'extrachill_events_user_can_manage_event' ) ) {
		return false;
	}

	$user_id  = (int) get_current_user_id();
	$event_id = (int) get_queried_object_id();

	return $user_id > 0 && $event_id > 0 && extrachill_events_user_can_manage_event( $user_id, $event_id );
}

/**
 * Block ads on event utility pages.
 *
 * @hook extrachill_should_block_ads
 * @param bool  $should_block_ads Whether ads are already blocked.
 * @param array $context          Ad request context.
 * @return bool
 */
function extrachill_events_block_ads_on_utility_pages( $should_block_ads, $context = array() ) {
	if ( $should_block_ads ) {
		return true;
	}

	// Only judge the live request; the policy can also be asked about other blogs.
	if ( isset( $context['blog_id'] ) && get_current_blog_id() !== (int) $context['blog_id'] ) {
		return (bool) $should_block_ads;
	}

	return extrachill_events_is_ad_free_page();
}
add_filter( 'extrachill_should_block_ads', 'extrachill_events_block_ads_on_utility_pages', 10, 2 );
