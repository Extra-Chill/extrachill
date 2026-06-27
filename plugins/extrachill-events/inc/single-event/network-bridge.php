<?php
/**
 * From Around the Extra Chill Network — Single Event Bridge (thin consumer)
 *
 * The reverse bridge on the single-event page: events.extrachill.com is a large
 * attention pool whose single-event template otherwise dead-ends with zero
 * outbound links to the rest of the network. This bridge gives the reader a
 * contextual path OUTWARD — blog coverage, a wire story, a community entry
 * point — driven by the event's own artist/festival terms.
 *
 * The bridge itself — terms resolution, transient caching, slot assembly, UTM
 * tagging, and render markup — lives in the shared primitive
 * `extrachill_render_network_bridge()` in extrachill-multisite (and the shared
 * stylesheet is registered there too). This file is a thin hook that decides
 * WHEN to render (single `data_machine_events` views on the events site) and
 * passes the events per-site arguments.
 *
 * NOTE: same-site event→event relevance is already handled by
 * inc/single-event/related-events.php. This bridge is STRICTLY the cross-SITE
 * outward links.
 *
 * @package ExtraChillEvents
 * @since 0.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "From Around the Extra Chill Network" section on single events.
 *
 * Hooked on `extrachill_after_post_content` (single events route through the
 * theme's single-post template). Guarded to single `data_machine_events` views
 * on the events site; both guards stay HERE in the consumer, not in the shared
 * primitive (layer purity — the primitive knows nothing about post types/sites).
 *
 * Renders NOTHING when the event carries no artist/festival terms or when no
 * cross-site content matches (no empty box) — that behavior lives in the
 * shared primitive.
 */
function ec_events_network_bridge() {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	if ( ! function_exists( 'extrachill_render_network_bridge' ) ) {
		return;
	}

	extrachill_render_network_bridge(
		array(
			'post_id'           => get_the_ID(),
			'taxonomies'        => array( 'artist', 'festival' ),
			'allowed_site_keys' => array( 'main', 'wire', 'community' ),
			'slot_order'        => array( 'main', 'wire', 'community' ),
			'utm_source'        => 'extrachill_events',
			'cache_prefix'      => 'ec_events_network_bridge_',
			'heading_id'        => 'events-network-bridge-header',
		)
	);
}
add_action( 'extrachill_after_post_content', 'ec_events_network_bridge', 6 );
