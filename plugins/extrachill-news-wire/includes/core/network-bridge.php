<?php
/**
 * From Around the Extra Chill Network — Single Festival Wire Bridge
 *
 * Wire (wire.extrachill.com) has the most-human engagement on the network but
 * is its least-networked node: it sends only a handful of cross-site referrals
 * and receives a handful back — a dead-end island. The blog bridge
 * (extrachill-blog#7) and the events bridge (extrachill-events) already gave
 * their surfaces a contextual path OUTWARD into the rest of the network. This
 * is the third bridge: the single-festival_wire equivalent.
 *
 * On a single festival_wire post it gives the reader a contextual path outward:
 *   1. Upcoming EVENT coverage for the festival (events.extrachill.com)
 *   2. BLOG coverage for the festival (extrachill.com)
 *   3. A COMMUNITY discussion entry point (community.extrachill.com)
 *
 * Relevance is driven entirely by the post's own taxonomy terms. Wire posts are
 * FESTIVAL-DRIVEN — ~90% carry a `festival` term and 0% carry `artist` terms —
 * so the bridge resolves from the post's `festival` terms (and `location` terms,
 * which is the only mapping that reaches the community surface; see
 * extrachill_get_taxonomy_site_map() in extrachill-multisite). Events, blog
 * posts, and wire posts share the network-wide `festival` and `location`
 * taxonomies, so "is there event/blog/community coverage for this festival?" is
 * answerable without any new matching logic.
 *
 * This file is a THIN CONSUMER of the existing cross-site linking engine in
 * extrachill-multisite (`extrachill_get_cross_site_term_links()` +
 * `extrachill_cross_site_link_button()`). It does not reimplement per-site
 * resolution — it reuses the same engine that powers the blog bridge, the
 * events bridge, and archive cross-site links, and adds: single-post placement,
 * per-post transient caching, and the wire UTM source so cross-site clicks are
 * measurable.
 *
 * NOTE: same-festival wire→wire relevance is already handled inside
 * templates/single-festival_wire.php (the "Related {Festival} News" aside).
 * This bridge is STRICTLY the cross-SITE outward links that do not exist today.
 *
 * Click-event instrumentation + UTM medium tagging lives in the SHARED renderer
 * `extrachill_cross_site_link_button()` (extrachill-multisite#58). Because this
 * bridge renders every card through that shared function, it INHERITS that
 * instrumentation automatically — it deliberately adds no bespoke tracking of
 * its own, only the wire-specific `utm_source`.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the network bridge stylesheet.
 *
 * Registered (not enqueued) here; the render function enqueues it only when the
 * section actually has cards to show, so no CSS loads on wire posts without
 * cross-site matches. Depends on `extrachill-root` for the design tokens.
 *
 * @since 0.4.0
 */
function ec_wire_network_bridge_register_style() {
	$css_path = FESTIVAL_WIRE_PLUGIN_DIR . 'assets/network-bridge.css';
	if ( ! file_exists( $css_path ) ) {
		return;
	}

	wp_register_style(
		'extrachill-wire-network-bridge',
		plugins_url( 'assets/network-bridge.css', FESTIVAL_WIRE_PLUGIN_DIR . 'extrachill-news-wire.php' ),
		array( 'extrachill-root' ),
		(string) filemtime( $css_path )
	);
}
add_action( 'wp_enqueue_scripts', 'ec_wire_network_bridge_register_style', 5 );

/**
 * Render the "From Around the Extra Chill Network" section on single wire posts.
 *
 * Hooked on `extrachill_after_post_content` (the same theme hook the blog and
 * events bridges use; the single-festival_wire template fires it after the
 * entry content). Guarded to single `festival_wire` views.
 *
 * Renders NOTHING when the post carries no festival/location terms or when no
 * cross-site content matches (no empty box).
 */
function ec_wire_network_bridge() {
	if ( ! is_singular( 'festival_wire' ) ) {
		return;
	}

	// The cross-site linking engine lives in extrachill-multisite. If it's not
	// available, render nothing rather than fataling.
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' )
		|| ! function_exists( 'extrachill_cross_site_link_button' ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$cards = ec_wire_network_bridge_get_cards( $post_id );
	if ( empty( $cards ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-wire-network-bridge' );

	echo '<div class="network-bridge-section related-tax-section" aria-labelledby="wire-network-bridge-header">';
	echo '<h3 class="network-bridge-header related-tax-header" id="wire-network-bridge-header">From Around the Extra Chill Network</h3>';
	echo '<div class="network-bridge-links ec-cross-site-links">';

	foreach ( $cards as $card ) {
		// Reuse the canonical cross-site button renderer (button-3 button-small).
		// Click instrumentation lives in this shared function (multisite#58).
		extrachill_cross_site_link_button( $card, 'network-bridge-link' );
	}

	echo '</div>';
	echo '</div>';
}
add_action( 'extrachill_after_post_content', 'ec_wire_network_bridge', 6 );

/**
 * Build the (cached) set of cross-site cards for a single wire post.
 *
 * Resolves up to three contextual destinations from the post's festival and
 * location terms:
 *   1. Upcoming event coverage (events.extrachill.com)
 *   2. Relevant blog coverage (extrachill.com)
 *   3. A community entry point (community.extrachill.com)
 *
 * Mirrors the 1-hour transient pattern used by the events bridge, keyed by post
 * ID plus a signature of the post's matching terms so the cache invalidates if
 * the post's terms change. Cross-site queries do not run on cache hits.
 *
 * @param int $post_id Wire post ID.
 * @return array List of link arrays consumable by extrachill_cross_site_link_button().
 */
function ec_wire_network_bridge_get_cards( $post_id ) {
	$post_id = (int) $post_id;

	$festival_terms = ec_wire_network_bridge_terms( $post_id, 'festival' );
	$location_terms = ec_wire_network_bridge_terms( $post_id, 'location' );

	// No matchable terms — nothing to do, and nothing to cache.
	if ( empty( $festival_terms ) && empty( $location_terms ) ) {
		return array();
	}

	$term_signature = md5(
		(string) wp_json_encode(
			array(
				'festival' => wp_list_pluck( $festival_terms, 'term_id' ),
				'location' => wp_list_pluck( $location_terms, 'term_id' ),
			)
		)
	);

	$cache_key = 'ec_wire_network_bridge_' . $post_id . '_' . $term_signature;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : array();
	}

	$cards = ec_wire_network_bridge_build_cards( $festival_terms, $location_terms );

	/**
	 * Filters the lifetime of the per-post wire network bridge cache.
	 *
	 * @since 0.4.0
	 *
	 * @param int $ttl     Cache lifetime in seconds. Default 1 hour.
	 * @param int $post_id Wire post ID.
	 */
	$ttl = (int) apply_filters( 'ec_wire_network_bridge_cache_ttl', HOUR_IN_SECONDS, $post_id );

	set_transient( $cache_key, $cards, $ttl );

	return $cards;
}

/**
 * Get the post's terms for a taxonomy, safely.
 *
 * @param int    $post_id  Wire post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term[] Array of term objects (possibly empty).
 */
function ec_wire_network_bridge_terms( $post_id, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return array();
	}

	return $terms;
}

/**
 * Assemble up to three contextual cards from the post's terms.
 *
 * Destinations are the network surfaces OTHER than wire itself (wire is the
 * current site and is excluded by the cross-site engine automatically):
 *   - Events:    upcoming event coverage for the festival.
 *   - Blog:      relevant main-site coverage for the festival.
 *   - Community: contextual discussion entry point.
 *
 * The `festival` taxonomy is mapped to main/events/wire in the multisite engine;
 * the `location` taxonomy is the only mapping that reaches the community surface
 * (main/events/wire/community). So community cards resolve from the post's
 * location terms while event/blog cards resolve from either taxonomy — whichever
 * yields the highest-count destination wins.
 *
 * Each cross-site lookup is delegated to the existing
 * `extrachill_get_cross_site_term_links()` engine. Outbound URLs carry the wire
 * `utm_source` so cross-site journeys are attributable to the wire bridge; the
 * shared renderer adds the click instrumentation + UTM medium.
 *
 * @param WP_Term[] $festival_terms Festival terms on the post.
 * @param WP_Term[] $location_terms Location terms on the post.
 * @return array Up to three link arrays.
 */
function ec_wire_network_bridge_build_cards( $festival_terms, $location_terms ) {
	// Gather candidate cross-site links from every matchable term, keyed by
	// site so we only ever show one card per destination site.
	$by_site = array();

	foreach ( $festival_terms as $term ) {
		ec_wire_network_bridge_collect( $by_site, $term, 'festival' );
	}
	foreach ( $location_terms as $term ) {
		ec_wire_network_bridge_collect( $by_site, $term, 'location' );
	}

	$cards = array();

	// Slot 1 — upcoming event coverage for the festival.
	if ( isset( $by_site['events'] ) ) {
		$cards['events'] = $by_site['events'];
	}

	// Slot 2 — relevant blog coverage.
	if ( isset( $by_site['main'] ) ) {
		$cards['main'] = $by_site['main'];
	}

	// Slot 3 — a community entry point, but ONLY when the cross-site engine
	// resolves a real community destination from a location term. We do NOT
	// synthesize a live community search URL as a fallback: those URLs are
	// crawlable, unbounded, and each one triggers an expensive full-text search
	// (see extrachill-events#172). No community card is better than a fake
	// search-result destination.
	if ( isset( $by_site['community'] ) ) {
		$cards['community'] = $by_site['community'];
	}

	// Tag every outbound link with the wire UTM source so cross-site clicks are
	// attributable to this bridge. The shared renderer owns the medium + click
	// instrumentation (extrachill-multisite#58); we only set the source.
	foreach ( $cards as $site_key => &$card ) {
		$card['url'] = ec_wire_network_bridge_tag_url( $card['url'], $site_key );
	}
	unset( $card );

	return array_values( $cards );
}

/**
 * Collect the best cross-site link per destination site for a single term.
 *
 * Calls the existing cross-site engine for the term and folds the results into
 * the $by_site accumulator, keeping the highest-count link per site (so the
 * most relevant festival/location wins when a post has several terms).
 *
 * @param array   $by_site  Accumulator keyed by site_key (passed by reference).
 * @param WP_Term $term     Term object.
 * @param string  $taxonomy Taxonomy slug.
 */
function ec_wire_network_bridge_collect( &$by_site, $term, $taxonomy ) {
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	foreach ( $links as $link ) {
		// Surface only the outward destinations relevant to a single wire post:
		// events, the blog (main), and the community. Wire itself is the current
		// page's site and is excluded by the engine; shop/artist are out of
		// scope for this festival-driven bridge.
		$site_key = isset( $link['site_key'] ) ? $link['site_key'] : '';
		if ( ! in_array( $site_key, array( 'events', 'main', 'community' ), true ) ) {
			continue;
		}

		if ( empty( $link['url'] ) ) {
			continue;
		}

		$count = isset( $link['count'] ) ? (int) $link['count'] : 0;

		// Keep the highest-count link per destination site.
		if ( ! isset( $by_site[ $site_key ] ) || $count > (int) $by_site[ $site_key ]['count'] ) {
			$by_site[ $site_key ] = array(
				'site_key'  => $site_key,
				'url'       => $link['url'],
				'label'     => isset( $link['label'] ) ? $link['label'] : ucfirst( $site_key ),
				'term_name' => isset( $link['term_name'] ) ? $link['term_name'] : $term->name,
				'count'     => $count,
			);
		}
	}
}

/**
 * Append the wire UTM source to a cross-site outbound URL.
 *
 * Tags cross-site journeys so the wire→network bridge's effectiveness is
 * measurable in analytics. Source = wire, campaign = the destination surface.
 * The shared renderer (`extrachill_cross_site_link_button()`) owns the medium
 * tag and the click-event instrumentation (extrachill-multisite#58), so this
 * bridge deliberately sets only `utm_source` and does not duplicate either.
 *
 * @param string $url      Destination URL.
 * @param string $site_key Destination site key (events|main|community).
 * @return string UTM-tagged URL.
 */
function ec_wire_network_bridge_tag_url( $url, $site_key ) {
	if ( empty( $url ) ) {
		return $url;
	}

	return add_query_arg(
		array(
			'utm_source'   => 'extrachill_wire',
			'utm_medium'   => 'network_bridge',
			'utm_campaign' => $site_key,
		),
		$url
	);
}
