<?php
/**
 * Buyer-facing paid ticket checkout composition.
 *
 * @package ExtraChillEvents
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
/**
 * Stripe return state. Informational only; fulfillment stays webhook-owned,
 * so these read-only query args need no nonce.
 *
 * @return string 'success', 'cancelled' or ''.
 */
function extrachill_events_ticket_return_state(): string {
	if ( ! is_singular( 'data_machine_events' ) || ! isset( $_GET['tickets'] ) || ! is_string( $_GET['tickets'] ) ) {
		return '';
	}
	$state = sanitize_text_field( wp_unslash( $_GET['tickets'] ) );
	if ( 'cancelled' === $state ) {
		return $state;
	}
	if ( 'success' === $state && isset( $_GET['session_id'] ) && is_string( $_GET['session_id'] ) && '' !== trim( $_GET['session_id'] ) ) {
		return $state;
	}
	return '';
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

/** Read the public offer through its owner ability. */
function extrachill_events_checkout_offer( $post_id ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-event-ticket-offer' ) : null;
	return $ability ? $ability->execute( array( 'event' => (int) $post_id ) ) : null;
}

/** Add to, rather than replace, the external ticket link. */
function extrachill_events_render_ticket_checkout( $post_id, $ticket_url = '', $timing = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature; the external link renders separately.
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}
	$state = extrachill_events_ticket_return_state();
	if ( 'success' === $state ) {
		echo '<div class="notice notice-success" role="status"><strong>' . esc_html__( 'Your tickets are on the way', 'extrachill-events' ) . '</strong><p>' . esc_html__( 'Your passes arrive by email. Please check your inbox.', 'extrachill-events' ) . '</p></div>';
		return;
	}
	if ( 'cancelled' === $state ) {
		echo '<div class="notice notice-info" role="status">' . esc_html__( 'Checkout was cancelled. You can try again when you are ready.', 'extrachill-events' ) . '</div>';
	}
	$offer = extrachill_events_checkout_offer( $post_id );
	if ( ! is_array( $offer ) || empty( $offer['enabled'] ) ) {
		return;
	}
	if ( empty( $offer['sale_open'] ) ) {
		// Closed for other reasons (the show is over) renders nothing, not "Sold out".
		if ( 0 === $offer['remaining'] ) {
			echo '<p class="notice notice-info">' . esc_html__( 'Sold out', 'extrachill-events' ) . '</p>';
		}
		return;
	}
	$maximum = null === $offer['remaining'] ? 10 : min( 10, (int) $offer['remaining'] );
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/ticket-checkout.php';
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_ticket_checkout', 7, 3 );

/**
 * First-party tickets replace the external ticket link, never sit beside it.
 *
 * @param bool $show    Whether Data Machine Events would show its ticket button.
 * @param int  $post_id Event post ID.
 * @return bool
 */
function extrachill_events_hide_external_ticket_button( $show, $post_id ) {
	if ( ! $show || ! class_exists( \ExtraChillEvents\Abilities\TicketAbilities::class ) ) {
		return $show;
	}
	return empty( \ExtraChillEvents\Abilities\TicketAbilities::settings( (int) $post_id )['tickets_enabled'] );
}
add_filter( 'data_machine_events_show_ticket_button', 'extrachill_events_hide_external_ticket_button', 10, 2 );

/**
 * Advertise the first-party ticket offer instead of the scraped vendor.
 *
 * @param array $offers     Incoming Offer schema.
 * @param int   $post_id    Event post ID.
 * @param array $event_data Event data supplied by Data Machine Events.
 * @return array
 */
function extrachill_events_ticket_offer_schema( $offers, $post_id, $event_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
	if ( ! class_exists( \ExtraChillEvents\Abilities\TicketAbilities::class ) || empty( \ExtraChillEvents\Abilities\TicketAbilities::settings( (int) $post_id )['tickets_enabled'] ) ) {
		return $offers;
	}
	$offer = extrachill_events_checkout_offer( $post_id );
	if ( ! is_array( $offer ) ) {
		return $offers;
	}
	$schema = array(
		'@type'         => 'Offer',
		'url'           => get_permalink( $post_id ),
		'price'         => $offer['price_minor'] / 100,
		'priceCurrency' => strtoupper( $offer['currency'] ),
		'availability'  => 0 === $offer['remaining'] ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
	);
	if ( isset( $offers['validFrom'] ) ) {
		$schema['validFrom'] = $offers['validFrom'];
	}
	return $schema;
}
add_filter( 'data_machine_events_offer_schema', 'extrachill_events_ticket_offer_schema', 10, 3 );

/** Checkout runs on Shop, where the protected payment ability lives. */
function extrachill_events_enqueue_ticket_checkout() {
	if ( ! is_singular( 'data_machine_events' ) || ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() || 'success' === extrachill_events_ticket_return_state() ) {
		return;
	}
	$offer = extrachill_events_checkout_offer( get_queried_object_id() );
	if ( ! is_array( $offer ) || empty( $offer['sale_open'] ) || ! function_exists( 'ec_get_site_url' ) ) {
		return;
	}
	wp_enqueue_script( 'ec-ticket-checkout', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/ticket-checkout.js', array(), EXTRACHILL_EVENTS_VERSION, true );
	wp_localize_script( 'ec-ticket-checkout', 'ecTicketCheckout', array(
		'url'   => trailingslashit( ec_get_site_url( 'shop' ) ) . 'wp-json/extrachill/v1/events/tickets/checkout',
		'error' => __( 'Unable to start checkout. Please try again.', 'extrachill-events' ),
	) );
	if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_ticket_checkout' );
