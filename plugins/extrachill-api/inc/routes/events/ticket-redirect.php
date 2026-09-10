<?php
/**
 * Ticket Redirect Endpoint
 *
 * Ticketmaster affiliate compliance: first-party redirect that keeps the
 * affiliate URL and affiliate ID out of both page HTML and any shipped JS
 * bundle. The button that reaches this endpoint carries no destination in
 * its markup; a frontend script (shipped in data-machine-events) sets the
 * href to this route on pointerdown and lets native navigation proceed.
 *
 * Thin transport only. This plugin never learns an affiliate host, an
 * affiliate ID, or a URL-template shape — it asks the hidden
 * data-machine-events/resolve-ticket-destination ability "what is the
 * destination for this event" and relays the answer as a 302.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_TICKET_REDIRECT_ABILITY = 'data-machine-events/resolve-ticket-destination';

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_ticket_redirect_route' );

/**
 * Register the public ticket redirect endpoint.
 */
function extrachill_api_register_ticket_redirect_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/tickets/(?P<id>\d+)/go',
		array(
			'methods'               => WP_REST_Server::READABLE,
			'callback'              => 'extrachill_api_handle_ticket_redirect',
			'permission_callback'   => '__return_true',
			'args'                  => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Event post ID.',
				),
			),
			'_extrachill_abilities' => array( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ),
		)
	);
}

/**
 * Resolve the ticket destination and 302-redirect, or 404.
 *
 * Every failure mode below — blocked by the bot gate, rate limited,
 * unregistered ability, unknown event, ticketless event — converges on a
 * plain 404. A scraper that guessed this URL learns nothing about why it
 * failed, and the sibling ability PR can land before or after this one
 * without either side fataling.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_ticket_redirect( WP_REST_Request $request ) {
	$gate = extrachill_api_ticket_redirect_bot_gate( $request );
	if ( is_wp_error( $gate ) ) {
		return extrachill_api_ticket_redirect_not_found();
	}

	$rate_limit = extrachill_api_ticket_redirect_rate_limit( $request );
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$event_id = (int) $request->get_param( 'id' );
	if ( $event_id < 1 ) {
		return extrachill_api_ticket_redirect_not_found();
	}

	// The ability ships in a sibling PR that may merge after this one, and is
	// registered show_in_rest:false on purpose — resolve it through the
	// abilities runtime, and guard for it being absent entirely. Reading via
	// wp_get_abilities() (not wp_get_ability()) is deliberate: core's
	// wp_get_ability() calls _doing_it_wrong() when the name isn't
	// registered, which is the expected, not exceptional, state here while
	// the sibling PR is unmerged.
	$ability = wp_get_abilities()[ EXTRACHILL_API_TICKET_REDIRECT_ABILITY ] ?? null;
	if ( ! $ability instanceof WP_Ability || false !== $ability->get_meta_item( 'show_in_rest' ) ) {
		return extrachill_api_ticket_redirect_not_found();
	}

	$result = $ability->execute( array( 'event_id' => $event_id ) );
	if ( is_wp_error( $result ) ) {
		return extrachill_api_ticket_redirect_not_found();
	}
	if ( ! is_array( $result ) || ! isset( $result['url'] ) || ! is_string( $result['url'] ) || '' === $result['url'] ) {
		return extrachill_api_ticket_redirect_not_found();
	}

	$url = esc_url_raw( $result['url'] );
	if ( '' === $url ) {
		return extrachill_api_ticket_redirect_not_found();
	}

	$response = new WP_REST_Response( null, 302 );
	$response->header( 'Location', $url );
	$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
	$response->header( 'Cache-Control', 'no-store' );

	return $response;
}

/**
 * Three-tier bot gate: Sec-Fetch-Site, then Referer, then rate limit.
 *
 * The rate limit is applied separately by the caller — this function only
 * covers the two header-based tiers.
 *
 * Tier 1 (primary): Sec-Fetch-Site. Present on every modern browser
 * navigation, NOT stripped by a page's Referrer-Policy, and absent from
 * curl/wget/most scripted clients. same-origin and same-site are admitted
 * (same-site covers the case where a ticket button renders on a different
 * Extra Chill subdomain via extrachill-events-network-blocks but still
 * targets this endpoint on events.extrachill.com). cross-site and none are
 * rejected outright.
 *
 * Tier 2 (secondary, only when Sec-Fetch-Site is entirely absent): Referer.
 * Present-but-off-network is rejected. Referer is treated as authoritative
 * only in this fallback role, and only in the reject direction — its
 * complete absence does not itself imply a bot. Referrer-Policy: no-referrer,
 * privacy-extension stripping, and some iOS in-app browsers routinely strip
 * Referer on legitimate clicks, so a Referer-only gate would silently drop
 * real revenue. When both signals are absent we allow the request through
 * and count it in a separate "ambiguous" bucket instead of blocking it: a
 * false negative here costs real affiliate revenue, a false positive only
 * costs a bot click we were never going to get paid for anyway. The
 * remaining backstop against that bucket is the per-IP rate limit.
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function extrachill_api_ticket_redirect_bot_gate( WP_REST_Request $request ) {
	$sec_fetch_site = strtolower( trim( (string) $request->get_header( 'Sec-Fetch-Site' ) ) );
	if ( '' !== $sec_fetch_site ) {
		return in_array( $sec_fetch_site, array( 'same-origin', 'same-site' ), true )
			? true
			: new WP_Error( 'ticket_redirect_blocked', '', array( 'status' => 404 ) );
	}

	$referer = trim( (string) $request->get_header( 'Referer' ) );
	if ( '' === $referer ) {
		extrachill_api_ticket_redirect_count_ambiguous();
		return true;
	}

	$host = wp_parse_url( $referer, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host || ! extrachill_api_ticket_redirect_host_is_network( $host ) ) {
		return new WP_Error( 'ticket_redirect_blocked', '', array( 'status' => 404 ) );
	}

	return true;
}

/**
 * Check one host against the Extra Chill network's own domains.
 *
 * Exact match or subdomain of a registered network domain. Falls back to
 * the current site's own host when extrachill-network is unavailable (e.g.
 * an isolated test/dev runtime).
 *
 * @param string $host Candidate host, already lowercased where relevant.
 * @return bool
 */
function extrachill_api_ticket_redirect_host_is_network( $host ) {
	$host = strtolower( rtrim( $host, '.' ) );
	foreach ( extrachill_api_ticket_redirect_network_hosts() as $network_host ) {
		$network_host = strtolower( rtrim( (string) $network_host, '.' ) );
		if ( '' === $network_host ) {
			continue;
		}
		if ( $host === $network_host || substr( $host, -strlen( '.' . $network_host ) ) === '.' . $network_host ) {
			return true;
		}
	}

	return false;
}

/**
 * Return the network's registered domains, with a single-site fallback.
 *
 * @return string[]
 */
function extrachill_api_ticket_redirect_network_hosts() {
	if ( function_exists( 'ec_get_domain_map' ) ) {
		return array_keys( ec_get_domain_map() );
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) && '' !== $host ? array( $host ) : array();
}

/**
 * Tally one "no Sec-Fetch-Site, no Referer" admitted request.
 *
 * A simple daily-bucketed transient. This is a measurement counter, not an
 * admission decision, so approximate (non-atomic) counting under heavy
 * concurrent traffic is an acceptable tradeoff for staying dependency-free.
 * Inspect a day's count with:
 *   wp transient get extrachill_api_ticket_redirect_ambiguous_YYYY-MM-DD
 */
function extrachill_api_ticket_redirect_count_ambiguous() {
	$key   = 'extrachill_api_ticket_redirect_ambiguous_' . gmdate( 'Y-m-d' );
	$count = (int) get_transient( $key );
	set_transient( $key, $count + 1, 2 * DAY_IN_SECONDS );
}

/**
 * Apply the shared per-IP atomic limiter to admitted redirect requests.
 *
 * 30/minute/IP. A real visitor clicking through a festival lineup might
 * open several ticket links in a burst; a scraper enumerating IDs sequentially
 * will blow past this in seconds. Filterable for tuning without a release.
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function extrachill_api_ticket_redirect_rate_limit( WP_REST_Request $request ) {
	$limit = (int) apply_filters( 'extrachill_api_ticket_redirect_rate_limit', 30 );

	return extrachill_api_check_public_read_rate_limit( $request, 'ticket-redirect', $limit );
}

/**
 * Return the one fixed public not-found response.
 *
 * @return WP_Error
 */
function extrachill_api_ticket_redirect_not_found() {
	return new WP_Error( 'ticket_redirect_not_found', __( 'Ticket link not found.', 'extrachill-api' ), array( 'status' => 404 ) );
}
