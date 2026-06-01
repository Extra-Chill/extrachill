<?php
/**
 * From Around the Extra Chill Network — Single Post Bridge
 *
 * Routes attention from high-traffic evergreen blog posts (the residual
 * song-meaning / music-history catalog) into the live platform surfaces
 * that breathe daily: the artist's profile hub, the events calendar, the
 * festival news wire, the shop, and the community. The vast majority of
 * network search traffic lands on these single posts and dead-ends there;
 * this section gives the reader a contextual path one click deeper into the
 * network.
 *
 * Relevance is driven entirely by the post's own taxonomy terms. Blog posts,
 * events, and wire posts share the network-wide `artist` and `festival`
 * taxonomies, so "is there an upcoming event / wire story for this artist or
 * festival?" is answerable without any new matching logic.
 *
 * This file is a THIN CONSUMER of the existing cross-site linking engine in
 * extrachill-multisite (`extrachill_get_cross_site_term_links()` +
 * `extrachill_cross_site_link_button()`). It does not reimplement per-site
 * REST calls — it reuses the engine that already powers cross-site taxonomy
 * links on archive pages, and adds: single-post placement, a guaranteed
 * community entry point, per-post transient caching, and UTM tagging so the
 * cross-site clicks are measurable.
 *
 * @package ExtraChillBlog
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "From Around the Extra Chill Network" section on single posts.
 *
 * Hooked at priority 6 on `extrachill_after_post_content` so it renders just
 * after the share card (priority 5). Guarded to single `post` views.
 *
 * Renders NOTHING when the post carries no artist/festival terms or when no
 * cross-site content matches (no empty box).
 */
function extrachill_blog_network_bridge() {
	if ( ! is_singular( 'post' ) ) {
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

	$cards = extrachill_blog_network_bridge_get_cards( $post_id );
	if ( empty( $cards ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-blog-network-bridge' );

	echo '<div class="network-bridge-section related-tax-section" aria-labelledby="network-bridge-header">';
	echo '<h3 class="network-bridge-header related-tax-header" id="network-bridge-header">From Around the Extra Chill Network</h3>';
	echo '<div class="network-bridge-links ec-cross-site-links">';

	foreach ( $cards as $card ) {
		// Reuse the canonical cross-site button renderer (button-3 button-small).
		extrachill_cross_site_link_button( $card, 'network-bridge-link' );
	}

	echo '</div>';
	echo '</div>';
}
add_action( 'extrachill_after_post_content', 'extrachill_blog_network_bridge', 6 );

/**
 * Build the (cached) set of cross-site cards for a single post.
 *
 * Resolves up to five contextual destinations from the post's artist and
 * festival terms:
 *   1. The artist's profile hub (artist.extrachill.com)
 *   2. A relevant upcoming event (events.extrachill.com)
 *   3. A relevant wire story (wire.extrachill.com)
 *   4. Relevant merch (shop.extrachill.com)
 *   5. A community entry point (community.extrachill.com)
 *
 * Mirrors the 1-hour transient pattern used by the theme's related-posts
 * helper, keyed by post ID plus a signature of the post's matching terms so
 * the cache invalidates if the post's terms change. Cross-site queries do not
 * run on cache hits.
 *
 * @param int $post_id Post ID.
 * @return array List of link arrays consumable by extrachill_cross_site_link_button().
 */
function extrachill_blog_network_bridge_get_cards( $post_id ) {
	$post_id = (int) $post_id;

	$artist_terms   = extrachill_blog_network_bridge_terms( $post_id, 'artist' );
	$festival_terms = extrachill_blog_network_bridge_terms( $post_id, 'festival' );

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

	$cache_key = 'ec_blog_network_bridge_' . $post_id . '_' . $term_signature;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : array();
	}

	$cards = extrachill_blog_network_bridge_build_cards( $artist_terms, $festival_terms );

	/**
	 * Filters the lifetime of the per-post network bridge cache.
	 *
	 * @since 0.4.0
	 *
	 * @param int $ttl     Cache lifetime in seconds. Default 1 hour.
	 * @param int $post_id Post ID.
	 */
	$ttl = (int) apply_filters( 'extrachill_blog_network_bridge_cache_ttl', HOUR_IN_SECONDS, $post_id );

	set_transient( $cache_key, $cards, $ttl );

	return $cards;
}

/**
 * Get the post's terms for a taxonomy, safely.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term[] Array of term objects (possibly empty).
 */
function extrachill_blog_network_bridge_terms( $post_id, $taxonomy ) {
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
 * Assemble up to five contextual cards from the post's terms.
 *
 * Order of preference for the slots:
 *   - Profile:   the artist's profile hub on the artist platform (the live
 *                destination the #62 term↔profile binding hardens). Resolved by
 *                the engine via the artist term, surfaced here for the first time.
 *   - Event:     first artist term with upcoming events, else first festival term.
 *   - Wire:      first festival term with wire coverage, else first artist term.
 *   - Shop:      relevant merch for the artist term, when the shop has products.
 *   - Community: contextual entry point (artist-first, festival fallback). Always
 *                present as a guaranteed path into the community.
 *
 * Each cross-site lookup is delegated to the existing
 * `extrachill_get_cross_site_term_links()` engine. Outbound URLs are UTM-tagged
 * so cross-site journeys are measurable.
 *
 * @param WP_Term[] $artist_terms   Artist terms on the post.
 * @param WP_Term[] $festival_terms Festival terms on the post.
 * @return array Up to five link arrays.
 */
function extrachill_blog_network_bridge_build_cards( $artist_terms, $festival_terms ) {
	// Gather candidate cross-site links from every matchable term, keyed by
	// site so we only ever show one card per destination site.
	$by_site = array();

	foreach ( $artist_terms as $term ) {
		extrachill_blog_network_bridge_collect( $by_site, $term, 'artist' );
	}
	foreach ( $festival_terms as $term ) {
		extrachill_blog_network_bridge_collect( $by_site, $term, 'festival' );
	}

	$cards = array();

	// Slot 1 — the artist's profile hub. The engine resolves this from the
	// artist term (the #62 term↔profile binding); it is the highest-value
	// destination because the profile is the artist's live home on the network.
	if ( isset( $by_site['artist'] ) ) {
		$cards['artist'] = $by_site['artist'];
	}

	// Slot 2 — a relevant upcoming event.
	if ( isset( $by_site['events'] ) ) {
		$cards['events'] = $by_site['events'];
	}

	// Slot 3 — a relevant wire story.
	if ( isset( $by_site['wire'] ) ) {
		$cards['wire'] = $by_site['wire'];
	}

	// Slot 4 — relevant merch, when the shop has products for the artist term.
	if ( isset( $by_site['shop'] ) ) {
		$cards['shop'] = $by_site['shop'];
	}

	// Slot 5 — a guaranteed community entry point. Prefer a real
	// community match from the engine; otherwise synthesize a contextual link
	// into the community keyed on the post's primary artist/festival term.
	if ( isset( $by_site['community'] ) ) {
		$cards['community'] = $by_site['community'];
	} else {
		$primary_term = ! empty( $artist_terms ) ? reset( $artist_terms ) : reset( $festival_terms );
		$community    = $primary_term instanceof WP_Term ? extrachill_blog_network_bridge_community_card( $primary_term ) : null;
		if ( $community ) {
			$cards['community'] = $community;
		}
	}

	// UTM-tag every outbound link so cross-site clicks are measurable.
	foreach ( $cards as $site_key => &$card ) {
		$card['url'] = extrachill_blog_network_bridge_tag_url( $card['url'], $site_key );
	}
	unset( $card );

	return array_values( $cards );
}

/**
 * Collect the best cross-site link per destination site for a single term.
 *
 * Calls the existing cross-site engine for the term and folds the results into
 * the $by_site accumulator, keeping the highest-count link per site (so the
 * most relevant artist/festival wins when a post has several terms).
 *
 * @param array   $by_site  Accumulator keyed by site_key (passed by reference).
 * @param WP_Term $term     Term object.
 * @param string  $taxonomy Taxonomy slug.
 */
function extrachill_blog_network_bridge_collect( &$by_site, $term, $taxonomy ) {
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	foreach ( $links as $link ) {
		// Surface the live platform surfaces plus the artist's own profile hub
		// and shop. The main blog itself is the current page's site and is never
		// a "from around the network" destination, so it stays filtered out.
		$site_key = isset( $link['site_key'] ) ? $link['site_key'] : '';
		if ( ! in_array( $site_key, array( 'artist', 'events', 'wire', 'shop', 'community' ), true ) ) {
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
 * Build a contextual community entry-point card for a term.
 *
 * Used when the cross-site engine returns no direct community match. Links the
 * reader into the community's network-wide search scoped to the term name, so
 * there is always a path into the community from a song-meaning post.
 *
 * @param WP_Term|null $term Primary term (artist or festival).
 * @return array|null Link array, or null if community is unavailable.
 */
function extrachill_blog_network_bridge_community_card( $term ) {
	if ( ! $term || ! function_exists( 'ec_get_site_url' ) ) {
		return null;
	}

	$community_url = ec_get_site_url( 'community' );
	if ( empty( $community_url ) ) {
		return null;
	}

	$search_url = add_query_arg(
		's',
		rawurlencode( $term->name ),
		trailingslashit( $community_url )
	);

	return array(
		'site_key'  => 'community',
		'url'       => $search_url,
		'label'     => __( 'in the Community', 'extrachill-blog' ),
		'term_name' => $term->name,
		'count'     => 0,
	);
}

/**
 * Append UTM parameters to a cross-site outbound URL.
 *
 * Tags cross-site journeys so the blog→platform bridge's effectiveness is
 * measurable in analytics later (the open measurement gap flagged in the
 * proposal). Source = blog, medium = the bridge section, campaign = the
 * destination surface.
 *
 * @param string $url      Destination URL.
 * @param string $site_key Destination site key (events|wire|community).
 * @return string UTM-tagged URL.
 */
function extrachill_blog_network_bridge_tag_url( $url, $site_key ) {
	if ( empty( $url ) ) {
		return $url;
	}

	return add_query_arg(
		array(
			'utm_source'   => 'extrachill_blog',
			'utm_medium'   => 'network_bridge',
			'utm_campaign' => $site_key,
		),
		$url
	);
}
