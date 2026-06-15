<?php
/**
 * From Around the Extra Chill Network — Single Event Bridge (reverse bridge)
 *
 * The mirror image of the blog network bridge (extrachill-blog#7). The blog
 * bridge routes attention from high-traffic evergreen blog posts INTO the live
 * platform surfaces. This bridge does the reverse on the single-event page:
 * events.extrachill.com is the network's second-largest attention pool (~52k
 * sessions) but its single-event template only links breadcrumb-up and to
 * same-site related events — it dead-ends with ZERO outbound links to blog
 * coverage, the festival news wire, or the community. Now that ~78k events are
 * crawlable, that traffic is set to grow.
 *
 * This section gives the reader on a single event a contextual path OUTWARD
 * into the rest of the network:
 *   1. Blog coverage for the event's artist/festival (main site)
 *   2. A relevant wire story for the festival/artist (wire site)
 *   3. A community discussion entry point for the artist/festival
 *
 * Relevance is driven entirely by the event's own taxonomy terms. Events, blog
 * posts, and wire posts share the network-wide `artist` and `festival`
 * taxonomies, so "is there blog/wire coverage for this artist or festival?" is
 * answerable without any new matching logic.
 *
 * This file is a THIN CONSUMER of the existing cross-site linking engine in
 * extrachill-multisite (`extrachill_get_cross_site_term_links()` +
 * `extrachill_cross_site_link_button()`). It does not reimplement per-site REST
 * calls — it reuses the same engine that powers the blog bridge and archive
 * cross-site links, and adds: single-event placement, a guaranteed community
 * entry point, per-post transient caching, and UTM tagging so the cross-site
 * clicks are measurable.
 *
 * NOTE: same-site event→event relevance is already handled by
 * inc/single-event/related-events.php (venue/location related events). This
 * bridge is STRICTLY the cross-SITE outward links that do not exist today.
 *
 * @package ExtraChillEvents
 * @since 0.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the network bridge stylesheet.
 *
 * Registered (not enqueued) here; the render function enqueues it only when the
 * section actually has cards to show, so no CSS loads on events without
 * cross-site matches. Depends on `extrachill-root` for the design tokens.
 *
 * @since 0.28.0
 */
function ec_events_network_bridge_register_style() {
	$css_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/network-bridge.css';
	if ( ! file_exists( $css_path ) ) {
		return;
	}

	wp_register_style(
		'extrachill-events-network-bridge',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/network-bridge.css',
		array( 'extrachill-root' ),
		(string) filemtime( $css_path )
	);
}
add_action( 'wp_enqueue_scripts', 'ec_events_network_bridge_register_style', 5 );

/**
 * Render the "From Around the Extra Chill Network" section on single events.
 *
 * Hooked on `extrachill_after_post_content` (the same theme hook the blog
 * bridge uses; single events route through the theme's single-post template).
 * Guarded to single `data_machine_events` views on the events site, and placed
 * near the related-events section for visual consistency by reusing the
 * theme's `.related-tax-section` / `.related-tax-header` classes.
 *
 * Renders NOTHING when the event carries no artist/festival terms or when no
 * cross-site content matches (no empty box).
 */
function ec_events_network_bridge() {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
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

	$cards = ec_events_network_bridge_get_cards( $post_id );
	if ( empty( $cards ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-events-network-bridge' );

	echo '<div class="network-bridge-section related-tax-section" aria-labelledby="events-network-bridge-header">';
	echo '<h3 class="network-bridge-header related-tax-header" id="events-network-bridge-header">From Around the Extra Chill Network</h3>';
	echo '<div class="network-bridge-links ec-cross-site-links">';

	foreach ( $cards as $card ) {
		// Reuse the canonical cross-site button renderer (button-3 button-small).
		extrachill_cross_site_link_button( $card, 'network-bridge-link' );
	}

	echo '</div>';
	echo '</div>';
}
add_action( 'extrachill_after_post_content', 'ec_events_network_bridge', 6 );

/**
 * Build the (cached) set of cross-site cards for a single event.
 *
 * Resolves up to three contextual destinations from the event's artist and
 * festival terms:
 *   1. Relevant blog coverage (extrachill.com)
 *   2. A relevant wire story (wire.extrachill.com)
 *   3. A community entry point (community.extrachill.com)
 *
 * Mirrors the 1-hour transient pattern used by the blog bridge, keyed by post
 * ID plus a signature of the event's matching terms so the cache invalidates
 * if the event's terms change. Cross-site queries do not run on cache hits.
 *
 * @param int $post_id Event post ID.
 * @return array List of link arrays consumable by extrachill_cross_site_link_button().
 */
function ec_events_network_bridge_get_cards( $post_id ) {
	$post_id = (int) $post_id;

	$artist_terms   = ec_events_network_bridge_terms( $post_id, 'artist' );
	$festival_terms = ec_events_network_bridge_terms( $post_id, 'festival' );

	// No matchable terms — nothing to do, and nothing to cache.
	if ( empty( $artist_terms ) && empty( $festival_terms ) ) {
		return array();
	}

	$term_signature = md5(
		(string) wp_json_encode(
			array(
				'artist'   => wp_list_pluck( $artist_terms, 'term_id' ),
				'festival' => wp_list_pluck( $festival_terms, 'term_id' ),
			)
		)
	);

	$cache_key = 'ec_events_network_bridge_' . $post_id . '_' . $term_signature;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : array();
	}

	$cards = ec_events_network_bridge_build_cards( $artist_terms, $festival_terms );

	/**
	 * Filters the lifetime of the per-event network bridge cache.
	 *
	 * @since 0.28.0
	 *
	 * @param int $ttl     Cache lifetime in seconds. Default 1 hour.
	 * @param int $post_id Event post ID.
	 */
	$ttl = (int) apply_filters( 'ec_events_network_bridge_cache_ttl', HOUR_IN_SECONDS, $post_id );

	set_transient( $cache_key, $cards, $ttl );

	return $cards;
}

/**
 * Get the event's terms for a taxonomy, safely.
 *
 * @param int    $post_id  Event post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term[] Array of term objects (possibly empty).
 */
function ec_events_network_bridge_terms( $post_id, $taxonomy ) {
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
 * Assemble up to three contextual cards from the event's terms.
 *
 * Destinations are the network surfaces OTHER than events itself (events is the
 * current site and is excluded by the cross-site engine automatically):
 *   - Blog:      relevant main-site coverage for the artist/festival.
 *   - Wire:      relevant festival/artist wire story.
 *   - Community: contextual discussion entry point. Always present as a
 *                guaranteed path into the community.
 *
 * Each cross-site lookup is delegated to the existing
 * `extrachill_get_cross_site_term_links()` engine. Outbound URLs are UTM-tagged
 * so cross-site journeys are measurable.
 *
 * @param WP_Term[] $artist_terms   Artist terms on the event.
 * @param WP_Term[] $festival_terms Festival terms on the event.
 * @return array Up to three link arrays.
 */
function ec_events_network_bridge_build_cards( $artist_terms, $festival_terms ) {
	// Gather candidate cross-site links from every matchable term, keyed by
	// site so we only ever show one card per destination site.
	$by_site = array();

	foreach ( $artist_terms as $term ) {
		ec_events_network_bridge_collect( $by_site, $term, 'artist' );
	}
	foreach ( $festival_terms as $term ) {
		ec_events_network_bridge_collect( $by_site, $term, 'festival' );
	}

	$cards = array();

	// Slot 1 — relevant blog coverage.
	if ( isset( $by_site['main'] ) ) {
		$cards['main'] = $by_site['main'];
	}

	// Slot 2 — a relevant wire story.
	if ( isset( $by_site['wire'] ) ) {
		$cards['wire'] = $by_site['wire'];
	}

	// Slot 3 — a community entry point, but ONLY when the cross-site engine
	// resolves a real community destination. We deliberately do NOT synthesize a
	// live community search URL (`/?s=<term>`) as a fallback: those URLs are
	// crawlable, unbounded, and each one triggers an expensive full-text search.
	// Emitting them across tens of thousands of event pages turned the community
	// search endpoint into a crawl/DB-load sink (see extrachill-events#172). No
	// community card is better than a fake search-result destination.
	if ( isset( $by_site['community'] ) ) {
		$cards['community'] = $by_site['community'];
	}

	// UTM-tag every outbound link so cross-site clicks are measurable.
	foreach ( $cards as $site_key => &$card ) {
		$card['url'] = ec_events_network_bridge_tag_url( $card['url'], $site_key );
	}
	unset( $card );

	return array_values( $cards );
}

/**
 * Collect the best cross-site link per destination site for a single term.
 *
 * Calls the existing cross-site engine for the term and folds the results into
 * the $by_site accumulator, keeping the highest-count link per site (so the
 * most relevant artist/festival wins when an event has several terms).
 *
 * @param array   $by_site  Accumulator keyed by site_key (passed by reference).
 * @param WP_Term $term     Term object.
 * @param string  $taxonomy Taxonomy slug.
 */
function ec_events_network_bridge_collect( &$by_site, $term, $taxonomy ) {
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	foreach ( $links as $link ) {
		// Surface only the outward destinations relevant to a single event:
		// the blog (main), the wire, and the community. Events itself is the
		// current page's site and is excluded by the engine; artist/shop are
		// out of scope for this reverse bridge.
		$site_key = isset( $link['site_key'] ) ? $link['site_key'] : '';
		if ( ! in_array( $site_key, array( 'main', 'wire', 'community' ), true ) ) {
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
 * Append UTM parameters to a cross-site outbound URL.
 *
 * Tags cross-site journeys so the event→platform reverse bridge's effectiveness
 * is measurable in analytics. Source = events, medium = the bridge section,
 * campaign = the destination surface.
 *
 * @param string $url      Destination URL.
 * @param string $site_key Destination site key (main|wire|community).
 * @return string UTM-tagged URL.
 */
function ec_events_network_bridge_tag_url( $url, $site_key ) {
	if ( empty( $url ) ) {
		return $url;
	}

	return add_query_arg(
		array(
			'utm_source'   => 'extrachill_events',
			'utm_medium'   => 'network_bridge',
			'utm_campaign' => $site_key,
		),
		$url
	);
}
