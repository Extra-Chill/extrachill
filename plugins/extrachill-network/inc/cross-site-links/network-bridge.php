<?php
/**
 * From Around the Extra Chill Network — Shared Single-Post Bridge Primitive
 *
 * One primitive that powers the "From Around the Extra Chill Network" section
 * on single posts across every network surface (blog, events, wire, …). It
 * routes attention from a single post into the other live surfaces of the
 * network using the post's own taxonomy terms.
 *
 * Three plugins (extrachill-blog, extrachill-events, extrachill-news-wire)
 * previously each carried a byte-for-byte structural copy of this bridge —
 * only the prefix, source taxonomies, allowed destination site-keys, slot
 * order, and UTM source differed. Every one of those differences is now a
 * parameter; nothing site-specific lives in this file.
 *
 * This primitive is a THIN CONSUMER of the existing cross-site linking engine
 * in this plugin (`extrachill_get_cross_site_term_links()` +
 * `extrachill_cross_site_link_button()`). It does not reimplement per-site
 * resolution — it reuses the engine that already powers archive cross-site
 * links, and adds: single-post placement, per-post transient caching, slot
 * ordering, and UTM tagging so cross-site clicks are measurable.
 *
 * LAYER PURITY: this primitive knows nothing about specific post types, sites,
 * or taxonomy slugs. The consuming plugin owns the `is_singular()` / site
 * guard and decides WHEN to call this; the primitive just renders given args.
 *
 * @package ExtraChillNetwork
 * @since 1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the shared network bridge stylesheet.
 *
 * Registered (not enqueued) here; the render function enqueues it only when a
 * bridge actually has cards to show, so no CSS loads on posts without
 * cross-site matches. Depends on `extrachill-root` for the design tokens when
 * that handle is registered — see extrachill_network_root_style_deps().
 *
 * Centralized in extrachill-network so the three consuming plugins no longer
 * each ship an identical copy of network-bridge.css.
 *
 * @since 1.21.0
 */
function extrachill_network_bridge_register_style() {
	$css_path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/css/network-bridge.css';
	if ( ! file_exists( $css_path ) ) {
		return;
	}

	wp_register_style(
		'extrachill-network-bridge',
		EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/css/network-bridge.css',
		extrachill_network_root_style_deps(),
		(string) filemtime( $css_path )
	);
}
// Priority 9: the Extra Chill theme registers `extrachill-root` on
// wp_enqueue_scripts at priority 5, and this plugin's callbacks were added
// first, so checking the handle at priority 5 would miss it even on
// Extra Chill-themed sites and silently drop the cascade dependency.
add_action( 'wp_enqueue_scripts', 'extrachill_network_bridge_register_style', 9 );

/**
 * Render the "From Around the Extra Chill Network" section for a single post.
 *
 * The single primitive every consuming plugin calls. Renders NOTHING (no empty
 * box) when the post carries no matchable terms or when the engine resolves no
 * cross-site cards.
 *
 * @since 1.21.0
 *
 * @param array $args {
 *     Required. Per-site configuration. Every per-site difference is a param.
 *
 *     @type int      $post_id           Post ID whose terms drive the bridge.
 *     @type string[] $taxonomies        Ordered list of taxonomy slugs to pull
 *                                        terms from, e.g. array( 'artist', 'festival' ).
 *     @type string[] $allowed_site_keys Whitelist of destination site-keys kept
 *                                        from the cross-site engine results.
 *     @type string[] $slot_order        Ordered destination site-keys controlling
 *                                        card order in the rendered output.
 *     @type string   $utm_source        utm_source literal for outbound links.
 *     @type string   $cache_prefix      Prefix for the per-post transient key.
 *     @type string   $heading_id        Optional. id attribute for the heading /
 *                                        aria-labelledby target. Default
 *                                        'network-bridge-header'.
 *     @type string   $heading_text      Optional. Heading text. Default
 *                                        'From Around the Extra Chill Network'.
 * }
 */
function extrachill_render_network_bridge( array $args ) {
	// The cross-site linking engine lives in this plugin. If it's not available,
	// render nothing rather than fataling.
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' )
		|| ! function_exists( 'extrachill_cross_site_link_button' ) ) {
		return;
	}

	$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
	if ( ! $post_id ) {
		return;
	}

	$taxonomies        = isset( $args['taxonomies'] ) ? (array) $args['taxonomies'] : array();
	$allowed_site_keys = isset( $args['allowed_site_keys'] ) ? (array) $args['allowed_site_keys'] : array();
	$slot_order        = isset( $args['slot_order'] ) ? (array) $args['slot_order'] : array();
	$utm_source        = isset( $args['utm_source'] ) ? (string) $args['utm_source'] : '';
	$cache_prefix      = isset( $args['cache_prefix'] ) ? (string) $args['cache_prefix'] : '';
	$heading_id        = isset( $args['heading_id'] ) && '' !== $args['heading_id']
		? (string) $args['heading_id']
		: 'network-bridge-header';
	$heading_text      = isset( $args['heading_text'] ) && '' !== $args['heading_text']
		? (string) $args['heading_text']
		: __( 'From Around the Extra Chill Network', 'extrachill-network' );

	if ( empty( $taxonomies ) || empty( $allowed_site_keys ) || empty( $slot_order ) ) {
		return;
	}

	$cards = extrachill_network_bridge_get_cards(
		$post_id,
		$taxonomies,
		$allowed_site_keys,
		$slot_order,
		$utm_source,
		$cache_prefix
	);

	if ( empty( $cards ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-network-bridge' );

	printf(
		'<div class="network-bridge-section related-tax-section" aria-labelledby="%s">',
		esc_attr( $heading_id )
	);
	printf(
		'<h3 class="network-bridge-header related-tax-header" id="%s">%s</h3>',
		esc_attr( $heading_id ),
		esc_html( $heading_text )
	);
	echo '<div class="network-bridge-links ec-cross-site-links">';

	foreach ( $cards as $card ) {
		// Reuse the canonical cross-site button renderer (button-3 button-small).
		// Click instrumentation lives in that shared function (multisite#58).
		extrachill_cross_site_link_button( $card, 'network-bridge-link' );
	}

	echo '</div>';
	echo '</div>';
}

/**
 * Build the (cached) set of cross-site cards for a single post.
 *
 * Resolves the post's terms across the requested taxonomies, folds the engine's
 * cross-site results into one highest-count card per destination site (filtered
 * to the allowed site-keys), assembles them in slot order, and UTM-tags the
 * outbound URLs.
 *
 * Caches via a per-post transient keyed by the cache prefix + post ID + an md5
 * signature of the ordered taxonomy term IDs, so the cache invalidates if the
 * post's terms change. Cross-site queries do not run on cache hits.
 *
 * @since 1.21.0
 *
 * @param int      $post_id           Post ID.
 * @param string[] $taxonomies        Ordered taxonomy slugs to pull terms from.
 * @param string[] $allowed_site_keys Destination site-key whitelist.
 * @param string[] $slot_order        Ordered destination site-keys for card order.
 * @param string   $utm_source        utm_source literal.
 * @param string   $cache_prefix      Transient key prefix.
 * @return array List of link arrays consumable by extrachill_cross_site_link_button().
 */
function extrachill_network_bridge_get_cards( $post_id, $taxonomies, $allowed_site_keys, $slot_order, $utm_source, $cache_prefix ) {
	$post_id = (int) $post_id;

	// Resolve terms per taxonomy, preserving the requested taxonomy order.
	$terms_by_taxonomy = array();
	$signature_parts   = array();
	$has_terms         = false;

	foreach ( $taxonomies as $taxonomy ) {
		$terms                          = extrachill_network_bridge_terms( $post_id, $taxonomy );
		$terms_by_taxonomy[ $taxonomy ] = $terms;
		$signature_parts[ $taxonomy ]   = wp_list_pluck( $terms, 'term_id' );

		if ( ! empty( $terms ) ) {
			$has_terms = true;
		}
	}

	// No matchable terms — nothing to do, and nothing to cache.
	if ( ! $has_terms ) {
		return array();
	}

	$term_signature = md5( (string) wp_json_encode( $signature_parts ) );
	$cache_key      = $cache_prefix . $post_id . '_' . $term_signature;
	$cached         = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : array();
	}

	$cards = extrachill_network_bridge_build_cards(
		$terms_by_taxonomy,
		$allowed_site_keys,
		$slot_order,
		$utm_source
	);

	/**
	 * Filters the lifetime of the per-post network bridge cache.
	 *
	 * @since 1.21.0
	 *
	 * @param int    $ttl          Cache lifetime in seconds. Default 1 hour.
	 * @param int    $post_id      Post ID.
	 * @param string $cache_prefix Transient key prefix for the calling bridge.
	 */
	$ttl = (int) apply_filters( 'extrachill_network_bridge_cache_ttl', HOUR_IN_SECONDS, $post_id, $cache_prefix );

	set_transient( $cache_key, $cards, $ttl );

	return $cards;
}

/**
 * Get the post's terms for a taxonomy, safely.
 *
 * @since 1.21.0
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term[] Array of term objects (possibly empty).
 */
function extrachill_network_bridge_terms( $post_id, $taxonomy ) {
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
 * Assemble the contextual cards from the post's terms.
 *
 * Gathers candidate cross-site links from every matchable term (keeping the
 * highest-count link per destination site), filters them to the allowed
 * site-keys, assembles the surviving cards in slot order, and UTM-tags every
 * outbound URL.
 *
 * The community card (and every other card) is emitted ONLY when the cross-site
 * engine resolves a real destination — this primitive never synthesizes a
 * `/?s=<term>` search-URL fallback. Those URLs are crawlable, unbounded, and
 * each one triggers an expensive full-text search; emitting them across a large
 * catalog turned the community search endpoint into a crawl/DB-load sink (see
 * extrachill-blog#13 / extrachill-events#172). No card is better than a fake
 * search-result destination. When Community has the entity term but no real
 * topic/archive destination, its validated composer contract may fill the
 * otherwise-empty Community slot instead.
 *
 * @since 1.21.0
 *
 * @param array    $terms_by_taxonomy Map of taxonomy slug => WP_Term[].
 * @param string[] $allowed_site_keys Destination site-key whitelist.
 * @param string[] $slot_order        Ordered destination site-keys for card order.
 * @param string   $utm_source        utm_source literal.
 * @return array Ordered list of link arrays.
 */
function extrachill_network_bridge_build_cards( $terms_by_taxonomy, $allowed_site_keys, $slot_order, $utm_source ) {
	// Gather candidate cross-site links from every matchable term, keyed by
	// site so we only ever show one card per destination site.
	$by_site = array();

	foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
		foreach ( $terms as $term ) {
			extrachill_network_bridge_collect( $by_site, $term, $taxonomy, $allowed_site_keys );
		}
	}

	// A real Community topic/archive always wins. The composer only fills an
	// explicitly permitted Community slot that would otherwise remain empty.
	if ( ! isset( $by_site['community'] )
		&& in_array( 'community', $allowed_site_keys, true )
		&& in_array( 'community', $slot_order, true ) ) {
		$fallback = extrachill_network_bridge_get_community_composer_fallback( $terms_by_taxonomy );
		if ( $fallback ) {
			$by_site['community'] = $fallback;
		}
	}

	// Assemble surviving cards in the requested slot order.
	$cards = array();
	foreach ( $slot_order as $site_key ) {
		if ( isset( $by_site[ $site_key ] ) ) {
			$cards[ $site_key ] = $by_site[ $site_key ];
		}
	}

	// UTM-tag every outbound link so cross-site clicks are measurable.
	foreach ( $cards as $site_key => &$card ) {
		$card['url'] = extrachill_network_bridge_tag_url( $card['url'], $site_key, $utm_source );
	}
	unset( $card );

	return array_values( $cards );
}

/**
 * Collect the best cross-site link per destination site for a single term.
 *
 * Calls the existing cross-site engine for the term and folds the results into
 * the $by_site accumulator, keeping the highest-count link per site (so the
 * most relevant term wins when a post has several). Links to sites outside the
 * allowed whitelist are dropped — the current page's own site is never a
 * "from around the network" destination, and the engine already excludes it.
 *
 * @since 1.21.0
 *
 * @param array    $by_site           Accumulator keyed by site_key (by reference).
 * @param WP_Term  $term              Term object.
 * @param string   $taxonomy          Taxonomy slug.
 * @param string[] $allowed_site_keys Destination site-key whitelist.
 */
function extrachill_network_bridge_collect( &$by_site, $term, $taxonomy, $allowed_site_keys ) {
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	foreach ( $links as $link ) {
		$site_key = isset( $link['site_key'] ) ? $link['site_key'] : '';
		if ( ! in_array( $site_key, $allowed_site_keys, true ) ) {
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
 * Resolve a Community composer fallback for the first verified entity term.
 *
 * Community is active only on its own site, and switch_to_blog() does not load
 * destination plugins. This consumes Community's documented URL/state contract
 * without loading Community code into source sites: its destination option
 * publishes the supported schema, query keys, and taxonomies. Network validates
 * that marker and the destination-local term, then the canonical site resolver
 * supplies the URL origin. Community remains authoritative for request
 * validation, composer permissions, and logged-out Users continuation.
 *
 * @param array $terms_by_taxonomy Map of taxonomy slug => WP_Term[].
 * @return array|null Link data, or null when the contract cannot be verified.
 */
function extrachill_network_bridge_get_community_composer_fallback( $terms_by_taxonomy ) {
	foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || empty( $term->slug ) || empty( $term->name ) ) {
				continue;
			}

			$url = extrachill_network_bridge_get_community_composer_url( $taxonomy, $term->slug );
			if ( '' === $url ) {
				continue;
			}

			return array(
				'site_key'  => 'community',
				'url'       => $url,
				'label'     => sprintf(
					/* translators: %s: entity name. */
					__( 'Start a discussion about %s', 'extrachill-network' ),
					$term->name
				),
				'term_name' => '',
				'count'     => 0,
			);
		}
	}

	return null;
}

/**
 * Build Community's canonical composer URL for an existing destination term.
 *
 * The returned URL is deliberately authentication-neutral so cached bridge
 * cards cannot leak a visitor-specific login URL. On Community, its composer
 * contract sends logged-out visitors to `/login/` with this complete URL as
 * `redirect_to`; Extra Chill Users then preserves that validated continuation.
 *
 * @param string $taxonomy Supported entity taxonomy.
 * @param string $slug     Canonical source term slug.
 * @return string Canonical composer URL, or an empty string on failure.
 */
function extrachill_network_bridge_get_community_composer_url( $taxonomy, $slug ) {
	if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'ec_get_site_url' ) ) {
		return '';
	}

	if ( ! is_string( $taxonomy )
		|| ! is_string( $slug )
		|| '' === $taxonomy
		|| '' === $slug
		|| sanitize_key( $taxonomy ) !== $taxonomy
		|| sanitize_title( $slug ) !== $slug ) {
		return '';
	}

	$community_blog_id = (int) ec_get_blog_id( 'community' );
	$community_url     = ec_get_site_url( 'community' );
	$community_site    = $community_blog_id ? get_site( $community_blog_id ) : null;
	$contract          = $community_blog_id ? extrachill_network_bridge_get_community_composer_contract( $community_blog_id ) : null;

	if ( ! $community_blog_id
		|| ! is_string( $community_url )
		|| '' === $community_url
		|| ! $community_site
		|| ! empty( $community_site->deleted )
		|| ! empty( $community_site->archived )
		|| ! empty( $community_site->spam )
		|| ! $contract
		|| ! in_array( $taxonomy, $contract['supported_taxonomies'], true ) ) {
		return '';
	}

	$switched = false;
	if ( (int) get_current_blog_id() !== $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$switched = true;
	}

	try {
		$destination_term = get_term_by( 'slug', $slug, $taxonomy );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( ! $destination_term || is_wp_error( $destination_term ) || $destination_term->slug !== $slug ) {
		return '';
	}

	return add_query_arg(
		array(
			$contract['query_parameters']['action']   => $contract['action'],
			$contract['query_parameters']['taxonomy'] => $taxonomy,
			$contract['query_parameters']['slug']     => $destination_term->slug,
		),
		trailingslashit( $community_url )
	);
}

/**
 * Read and validate Community's deployment-discoverable composer contract.
 *
 * @param int $community_blog_id Community blog ID.
 * @return array|null Supported contract, or null when absent or incompatible.
 */
function extrachill_network_bridge_get_community_composer_contract( $community_blog_id ) {
	$contract = get_blog_option( $community_blog_id, 'extrachill_community_discussion_composer_contract', null );
	if ( ! is_array( $contract )
		|| 1 !== ( $contract['schema_version'] ?? null )
		|| ! is_string( $contract['action'] ?? null )
		|| '' === $contract['action']
		|| sanitize_key( $contract['action'] ) !== $contract['action']
		|| ! is_array( $contract['query_parameters'] ?? null )
		|| ! is_array( $contract['supported_taxonomies'] ?? null )
		|| array() === $contract['supported_taxonomies'] ) {
		return null;
	}

	$query_parameters = $contract['query_parameters'];
	$query_keys       = array_keys( $query_parameters );
	sort( $query_keys );
	if ( array( 'action', 'slug', 'taxonomy' ) !== $query_keys ) {
		return null;
	}

	foreach ( $query_parameters as $key ) {
		if ( ! is_string( $key ) || '' === $key || sanitize_key( $key ) !== $key ) {
			return null;
		}
	}

	if ( count( $query_parameters ) !== count( array_unique( $query_parameters ) ) ) {
		return null;
	}

	foreach ( $contract['supported_taxonomies'] as $taxonomy ) {
		if ( ! is_string( $taxonomy ) || '' === $taxonomy || sanitize_key( $taxonomy ) !== $taxonomy ) {
			return null;
		}
	}
	if ( count( $contract['supported_taxonomies'] ) !== count( array_unique( $contract['supported_taxonomies'] ) ) ) {
		return null;
	}

	return $contract;
}

/**
 * Append UTM parameters to a cross-site outbound URL.
 *
 * Tags cross-site journeys so each bridge's effectiveness is measurable in
 * analytics. Source = the calling bridge's utm_source, medium = the bridge
 * section, campaign = the destination surface.
 *
 * @since 1.21.0
 *
 * @param string $url        Destination URL.
 * @param string $site_key   Destination site key (the utm_campaign value).
 * @param string $utm_source utm_source literal.
 * @return string UTM-tagged URL.
 */
function extrachill_network_bridge_tag_url( $url, $site_key, $utm_source ) {
	if ( empty( $url ) ) {
		return $url;
	}

	return add_query_arg(
		array(
			'utm_source'   => $utm_source,
			'utm_medium'   => 'network_bridge',
			'utm_campaign' => $site_key,
		),
		$url
	);
}
