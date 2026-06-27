<?php
/**
 * From Around the Extra Chill Network — Single Festival Wire Bridge (thin consumer)
 *
 * Wire (wire.extrachill.com) is the network's least-networked node. On a single
 * festival_wire post this bridge gives the reader a contextual path outward:
 * upcoming event coverage, blog coverage, and a community discussion entry
 * point — driven by the post's own festival/location terms (wire posts are
 * festival-driven; the `location` mapping is the only one that reaches the
 * community surface; see extrachill_get_taxonomy_site_map() in
 * extrachill-multisite).
 *
 * The bridge itself — terms resolution, transient caching, slot assembly, UTM
 * tagging, and render markup — lives in the shared primitive
 * `extrachill_render_network_bridge()` in extrachill-multisite (and the shared
 * stylesheet is registered there too). This file is a thin hook that decides
 * WHEN to render (single `festival_wire` views) and passes the wire per-site
 * arguments. Click instrumentation + UTM medium are owned by the shared
 * renderer (`extrachill_cross_site_link_button()`, extrachill-multisite#58).
 *
 * NOTE: same-festival wire→wire relevance is already handled inside
 * templates/single-festival_wire.php. This bridge is STRICTLY the cross-SITE
 * outward links.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "From Around the Extra Chill Network" section on single wire posts.
 *
 * Hooked on `extrachill_after_post_content`. Guarded to single `festival_wire`
 * views; the guard stays HERE in the consumer, not in the shared primitive
 * (layer purity — the primitive knows nothing about post types).
 *
 * Renders NOTHING when the post carries no festival/location terms or when no
 * cross-site content matches (no empty box) — that behavior lives in the
 * shared primitive.
 */
function ec_wire_network_bridge() {
	if ( ! is_singular( 'festival_wire' ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_render_network_bridge' ) ) {
		return;
	}

	extrachill_render_network_bridge(
		array(
			'post_id'           => get_the_ID(),
			'taxonomies'        => array( 'festival', 'location' ),
			'allowed_site_keys' => array( 'events', 'main', 'community' ),
			'slot_order'        => array( 'events', 'main', 'community' ),
			'utm_source'        => 'extrachill_wire',
			'cache_prefix'      => 'ec_wire_network_bridge_',
			'heading_id'        => 'wire-network-bridge-header',
		)
	);
}
add_action( 'extrachill_after_post_content', 'ec_wire_network_bridge', 6 );
