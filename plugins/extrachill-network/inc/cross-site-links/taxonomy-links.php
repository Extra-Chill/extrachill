<?php
/**
 * Cross-Site Taxonomy Links
 *
 * Functions for linking taxonomy archives across sites in the multisite network.
 * Only returns links to sites where the term exists and has published content.
 * Main, Events, Shop, and Wire sites use REST APIs for accurate counts.
 * Artist site uses slug-based matching to artist_profile CPT.
 *
 * @package ExtraChillNetwork
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object-cache group for cross-site term link results.
 *
 * Persistent (Redis) on this network, so entries survive across requests and
 * are shared by every consumer. The group is registered as a global group so
 * keys are network-scoped, not per-blog: invalidation must hit every site's
 * cached results at once, and each key embeds the current site key
 * (`links_{taxonomy}_{term_id}_{site_key}`), so entries stay unambiguous
 * without a per-blog prefix.
 *
 * Invalidation contract (generation key, O(1)): every key is prefixed with a
 * network-global generation counter stored in this group. Bumping the counter
 * (see extrachill_cross_site_links_flush_cache_group()) instantly orphans all
 * previous-generation entries; they age out via their TTL. Never call
 * wp_cache_flush_group() here — on the Redis Object Cache drop-in that is a
 * full-keyspace SCAN, not an O(group) operation.
 */
const EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP = 'extrachill_cross_site_links';

/**
 * Register the cross-site links cache group as network-global.
 *
 * Cross-site link answers change when relevant content is published on ANY
 * network site, so a generation bump on one site must orphan cached results
 * everywhere. Global-group keys drop the per-blog prefix, which is safe here
 * because every key already embeds the current site key.
 *
 * @return void
 */
function extrachill_register_cross_site_links_cache_group() {
	if ( function_exists( 'wp_cache_add_global_groups' ) ) {
		wp_cache_add_global_groups( array( EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP ) );
	}
}
add_action( 'init', 'extrachill_register_cross_site_links_cache_group', 0 );

/**
 * Current generation of the cross-site links cache, read once per request.
 *
 * The generation is an integer stored in the cache group itself. On a cold
 * key it is initialized to 1 with wp_cache_add() (atomic — concurrent cold
 * requests converge on the winner's value).
 *
 * @param bool $reset True to drop the per-request static after a generation
 *                    bump so later reads build the new keys. Internal use.
 * @return int Current generation (>= 1).
 */
function extrachill_cross_site_links_cache_generation( $reset = false ) {
	static $generation = null;

	if ( $reset ) {
		$generation = null;
	}

	if ( null !== $generation ) {
		return $generation;
	}

	$found = wp_cache_get( 'generation', EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );

	if ( false !== $found && (int) $found >= 1 ) {
		$generation = (int) $found;
		return $generation;
	}

	if ( wp_cache_add( 'generation', 1, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP ) ) {
		$generation = 1;
		return $generation;
	}

	// Another request initialized the counter first; read its value.
	$found      = (int) wp_cache_get( 'generation', EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
	$generation = $found >= 1 ? $found : 1;

	return $generation;
}

/**
 * Build a generation-prefixed cache key for this group.
 *
 * All cross-site-links cache keys must route through this helper so a
 * generation bump invalidates every entry at once.
 *
 * @param string $key Bare key (e.g. "links_artist_12_main").
 * @return string Generation-prefixed key (e.g. "g7:links_artist_12_main").
 */
function extrachill_cross_site_links_cache_key( $key ) {
	return 'g' . extrachill_cross_site_links_cache_generation() . ':' . $key;
}

/**
 * Get cross-site links for a taxonomy term where content exists.
 *
 * Object-cache wrapper around extrachill_get_cross_site_term_links_uncached().
 *
 * The uncached computation loops every site mapped to the taxonomy and resolves
 * each site's single-term count in-process — up to ~5-6 cross-site lookups for
 * the `artist` taxonomy. This is a shared hot path: it runs on archive pages
 * (renderers.php) and, since extrachill-blog#7, on single posts via the network
 * bridge. The blog/shop/wire/community lookups call the network-active
 * `extrachill/taxonomy-post-counts` ability directly (switch_to_blog + WP_Query,
 * no HTTP); the events lookup reads the events-site bulk warmer transient and
 * only falls back to an HTTP loopback when that cache is cold (see
 * extrachill_get_events_upcoming_count_via_api() and
 * Extra-Chill/extrachill-network#50). Without this caching layer each render
 * would repeat the full cross-site cost every time.
 *
 * Results are cached in the persistent object cache keyed by generation +
 * taxonomy + term_id + current_site_key (the output is site-relative because
 * the current site is skipped). TTL is filterable; default 1 hour.
 * Invalidated via generation-key bump on publish-state changes and deletes of
 * the relevant CPTs across the network (see
 * extrachill_cross_site_links_flush_cache_group()).
 *
 * @param WP_Term|int $term     Term object or term ID.
 * @param string      $taxonomy Taxonomy slug.
 * @return array Array of link data (see _uncached() for shape).
 */
function extrachill_get_cross_site_term_links( $term, $taxonomy ) {
	if ( is_int( $term ) ) {
		$term = get_term( $term, $taxonomy );
	}

	if ( ! $term || is_wp_error( $term ) ) {
		return array();
	}

	$current_site_key = extrachill_get_current_site_key();
	$cache_key        = extrachill_cross_site_links_cache_key(
		'links_' . $taxonomy . '_' . (int) $term->term_id . '_' . (string) $current_site_key
	);

	$cached = wp_cache_get( $cache_key, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : array();
	}

	$links = extrachill_get_cross_site_term_links_uncached( $term, $taxonomy );

	/**
	 * Filters the TTL for cached cross-site term links.
	 *
	 * Keep at or above the consumer-side cache TTLs (e.g. the network bridge's
	 * per-post transient in extrachill-blog) so the inner layer doesn't expire
	 * faster than the outer one.
	 *
	 * @since 1.14.0
	 *
	 * @param int    $ttl      Cache lifetime in seconds. Default 1 hour.
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID.
	 */
	$ttl = (int) apply_filters( 'extrachill_cross_site_links_cache_ttl', HOUR_IN_SECONDS, $taxonomy, (int) $term->term_id );

	wp_cache_set( $cache_key, $links, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP, $ttl );

	return $links;
}

/**
 * Compute cross-site links for a taxonomy term where content exists.
 *
 * Checks each mapped site for the term and returns links only where
 * the term exists with at least one published post. Events and Shop
 * sites use REST APIs for accurate counts.
 *
 * Uncached — call extrachill_get_cross_site_term_links() for the cached path.
 *
 * @param WP_Term|int $term     Term object or term ID.
 * @param string      $taxonomy Taxonomy slug.
 * @return array Array of link data, each containing:
 *               - blog_id: int
 *               - site_key: string
 *               - url: string
 *               - label: string
 *               - count: int
 */
function extrachill_get_cross_site_term_links_uncached( $term, $taxonomy ) {
	if ( is_int( $term ) ) {
		$term = get_term( $term, $taxonomy );
	}

	if ( ! $term || is_wp_error( $term ) ) {
		return array();
	}

	$taxonomy_site_map = extrachill_get_taxonomy_site_map();
	if ( ! isset( $taxonomy_site_map[ $taxonomy ] ) ) {
		return array();
	}

	$target_sites        = $taxonomy_site_map[ $taxonomy ];
	$current_site_key    = extrachill_get_current_site_key();
	$content_type_labels = extrachill_get_site_content_type_labels();
	$main_blog_id        = ec_get_blog_id( 'main' );
	$events_blog_id      = ec_get_blog_id( 'events' );
	$shop_blog_id        = ec_get_blog_id( 'shop' );
	$wire_blog_id        = ec_get_blog_id( 'wire' );
	$artist_blog_id      = ec_get_blog_id( 'artist' );
	$community_blog_id   = ec_get_blog_id( 'community' );
	$links               = array();

	foreach ( $target_sites as $site_key ) {
		// Skip current site.
		if ( $site_key === $current_site_key ) {
			continue;
		}

		$blog_id = ec_get_blog_id( $site_key );
		if ( ! $blog_id ) {
			continue;
		}

		// Use REST APIs for consistent cross-site data access.
		// Artist site uses slug-based profile matching (CPT, not taxonomy).
		if ( $blog_id === $artist_blog_id && 'artist' === $taxonomy ) {
			$artist_profile = extrachill_get_artist_profile_by_slug( $term->slug );
			$term_data      = $artist_profile ? array(
				'count' => 1,
				'url'   => $artist_profile['permalink'],
			) : null;
		} elseif ( $blog_id === $main_blog_id ) {
			$term_data = extrachill_get_blog_taxonomy_count_via_api( $term->slug, $taxonomy );
		} elseif ( $blog_id === $events_blog_id ) {
			$term_data = extrachill_get_events_upcoming_count_via_api( $term->slug, $taxonomy );
		} elseif ( $blog_id === $shop_blog_id ) {
			$term_data = extrachill_get_shop_taxonomy_count_via_api( $term->slug, $taxonomy );
		} elseif ( $blog_id === $wire_blog_id ) {
			$term_data = extrachill_get_wire_taxonomy_count_via_api( $term->slug, $taxonomy );
		} elseif ( $blog_id === $community_blog_id ) {
			$term_data = extrachill_get_community_taxonomy_count_via_api( $term->slug, $taxonomy );
		} else {
			$term_data = extrachill_check_term_on_site( $term->slug, $taxonomy, $blog_id );
		}

		if ( ! $term_data || $term_data['count'] < 1 ) {
			continue;
		}

		// REST APIs return URL directly, otherwise build it
		if ( isset( $term_data['url'] ) ) {
			$url = $term_data['url'];
		} else {
			$url = extrachill_build_term_archive_url( $term->slug, $taxonomy, $blog_id );
		}

		if ( ! $url ) {
			continue;
		}
		$url = extrachill_normalize_url_for_blog( $url, $blog_id );

		$links[] = array(
			'blog_id'   => $blog_id,
			'site_key'  => $site_key,
			'url'       => $url,
			'label'     => isset( $content_type_labels[ $site_key ] ) ? $content_type_labels[ $site_key ] : ucfirst( $site_key ),
			'term_name' => $term->name,
			'count'     => $term_data['count'],
		);
	}

	return $links;
}

/**
 * Resolve a single term's published-post count on a target site, in-process.
 *
 * Calls the network-active `extrachill/taxonomy-post-counts` ability directly.
 * The ability switches to the target blog internally and runs a plain WP_Query
 * — no REST dispatch, so the route-affinity middleware never fires and no HTTP
 * loopback to 127.0.0.1 is made. This is the path that previously tripped the
 * nginx `wpjson` rate-limit zone (see Extra-Chill/extrachill-network#50).
 *
 * The ability's handler depends only on network-shared primitives (taxonomy
 * registration, the posts/terms tables, get_term_link), all of which resolve
 * correctly under switch_to_blog() — so unlike the events upcoming-count path,
 * this never needs the target site's per-site plugin stack.
 *
 * @param string      $term_slug Term slug.
 * @param string      $taxonomy  Taxonomy slug.
 * @param string      $site_key  Target site key (e.g. 'main', 'shop', 'wire').
 * @param string|null $post_type Optional explicit post type to count.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_taxonomy_count_via_ability( $term_slug, $taxonomy, $site_key, $post_type = null ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}

	$ability = wp_get_ability( 'extrachill/taxonomy-post-counts' );
	if ( ! $ability ) {
		return null;
	}

	$input = array(
		'taxonomy' => $taxonomy,
		'site'     => $site_key,
		'slug'     => $term_slug,
	);
	if ( $post_type ) {
		$input['post_type'] = $post_type;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) || empty( $result['terms'] ) ) {
		return null;
	}

	$term = $result['terms'][0];
	if ( ! isset( $term['count'] ) || (int) $term['count'] < 1 ) {
		return null;
	}

	return array(
		'term_id' => isset( $term['term_id'] ) ? (int) $term['term_id'] : null,
		'count'   => (int) $term['count'],
		'url'     => isset( $term['url'] ) ? $term['url'] : null,
	);
}

/**
 * Get shop product count for a term (in-process).
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_shop_taxonomy_count_via_api( $term_slug, $taxonomy ) {
	return extrachill_get_taxonomy_count_via_ability( $term_slug, $taxonomy, 'shop', 'product' );
}

/**
 * Get wire (festival_wire) post count for a term (in-process).
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_wire_taxonomy_count_via_api( $term_slug, $taxonomy ) {
	return extrachill_get_taxonomy_count_via_ability( $term_slug, $taxonomy, 'wire', 'festival_wire' );
}

/**
 * Get community content count for a term (in-process).
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_community_taxonomy_count_via_api( $term_slug, $taxonomy ) {
	return extrachill_get_taxonomy_count_via_ability( $term_slug, $taxonomy, 'community', 'topic' );
}

/**
 * Get blog post count for a term (in-process).
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_blog_taxonomy_count_via_api( $term_slug, $taxonomy ) {
	return extrachill_get_taxonomy_count_via_ability( $term_slug, $taxonomy, 'main' );
}

/**
 * Get upcoming event count for a term on the events site.
 *
 * Upcoming-event counting is genuinely events business logic: it filters the
 * `datamachine_event_dates` table by start date and is implemented by the
 * `extrachill/events-upcoming-counts` ability, which is registered ONLY on the
 * events site (extrachill-events / data-machine-events are per-site, not
 * network-activated). So unlike the blog/shop/wire/community paths above, this
 * cannot be answered in-process from another site — switch_to_blog() swaps the
 * DB context but does not load the events plugin code that registers the
 * ability. Duplicating that SQL here would be a layering violation.
 *
 * To avoid the per-term HTTP loopback that tripped nginx rate-limiting
 * (Extra-Chill/extrachill-network#50), this resolves from the bulk
 * `ec_upcoming_counts_<taxonomy>` transient that the badge-count warmer
 * populates on the events site every 4 hours (see badge-count-warmer.php).
 * The transient lives in the events blog's object-cache namespace, so we read
 * it under switch_to_blog(). Only when that bulk cache is genuinely cold do we
 * fall back to a single HTTP loopback request — at most once per taxonomy per
 * warm cycle, not once per term per page — and we negative-cache a throttled
 * failure briefly so a rate-limited response never silently drops the card on
 * subsequent terms in the same render.
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @return array|null Array with 'count' and 'url', or null if not found.
 */
function extrachill_get_events_upcoming_count_via_api( $term_slug, $taxonomy ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return null;
	}

	// 1. Try the bulk transient the events-site warmer maintains. This is the
	// common path on any page and involves zero HTTP.
	$from_bulk = extrachill_get_events_count_from_bulk_cache( $term_slug, $taxonomy, (int) $events_blog_id );
	if ( null !== $from_bulk ) {
		// Sentinel: an empty array means "warm cache, term has no upcoming
		// events" — a definitive negative, so don't fall through to HTTP.
		return empty( $from_bulk ) ? null : $from_bulk;
	}

	// 2. Bulk cache is cold. Fall back to the affinity-forwarded REST route
	// (HTTP loopback to the events site) — but guard it so a throttled
	// response doesn't get retried for every remaining term on the page.
	if ( extrachill_events_loopback_is_backing_off() ) {
		return null;
	}

	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params(
		array(
			'taxonomy' => $taxonomy,
			'slug'     => $term_slug,
		)
	);

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		$error  = $response->as_error();
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		// 429 (Too Many Requests) / 503 mean the loopback got throttled.
		// Back off so the remaining terms in this render don't each retry and
		// amplify the burst that tripped the limiter in the first place.
		if ( 429 === $status || 503 === $status || 502 === $status ) {
			extrachill_events_loopback_set_backoff();
		}

		return null;
	}

	$data = $response->get_data();
	if ( empty( $data ) || ! isset( $data['count'] ) ) {
		return null;
	}

	return array(
		'term_id' => null,
		'count'   => (int) $data['count'],
		'url'     => isset( $data['url'] ) ? $data['url'] : null,
	);
}

/**
 * Resolve a single term's upcoming-event count from the events-site bulk cache.
 *
 * Reads the `ec_upcoming_counts_<taxonomy>` transient maintained by the badge
 * warmer on the events site (read under switch_to_blog so we hit the events
 * blog's object-cache namespace). Returns:
 *   - a populated array when the term is present with upcoming events,
 *   - an empty array when the cache is warm but the term has no upcoming events
 *     (a definitive negative — caller must NOT fall through to HTTP), or
 *   - null when the bulk cache is cold/absent (caller may fall back to HTTP).
 *
 * @param string $term_slug      Term slug.
 * @param string $taxonomy       Taxonomy slug.
 * @param int    $events_blog_id Events blog ID.
 * @return array|null Term data array, empty array (warm negative), or null (cold).
 */
function extrachill_get_events_count_from_bulk_cache( $term_slug, $taxonomy, $events_blog_id ) {
	$switched = false;
	if ( (int) get_current_blog_id() !== (int) $events_blog_id ) {
		switch_to_blog( $events_blog_id );
		$switched = true;
	}

	try {
		$terms = get_transient( 'ec_upcoming_counts_' . $taxonomy );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( false === $terms || ! is_array( $terms ) ) {
		return null; // Cold cache.
	}

	foreach ( $terms as $term ) {
		if ( isset( $term['slug'] ) && $term['slug'] === $term_slug ) {
			if ( ! isset( $term['count'] ) || (int) $term['count'] < 1 ) {
				return array(); // Warm negative.
			}

			return array(
				'term_id' => isset( $term['term_id'] ) ? (int) $term['term_id'] : null,
				'count'   => (int) $term['count'],
				'url'     => isset( $term['url'] ) ? $term['url'] : null,
			);
		}
	}

	// Warm cache but term not listed → no upcoming events for this term.
	return array();
}

/**
 * Whether the events HTTP-loopback fallback is currently backing off.
 *
 * @return bool True if a recent loopback call was throttled.
 */
function extrachill_events_loopback_is_backing_off() {
	return (bool) get_transient( 'ec_events_loopback_backoff' );
}

/**
 * Engage a short back-off window for the events HTTP-loopback fallback.
 *
 * Set after a throttled (429/503/502) loopback response so the remaining terms
 * in the same page render — and other requests in the immediate window — skip
 * the loopback instead of hammering the already-rate-limited route. Short TTL
 * because the bulk warmer refreshes the cache and the limiter window is brief.
 *
 * @return void
 */
function extrachill_events_loopback_set_backoff() {
	/**
	 * Filters the events loopback back-off window in seconds.
	 *
	 * @since 1.15.0
	 *
	 * @param int $seconds Back-off duration. Default 120.
	 */
	$ttl = (int) apply_filters( 'extrachill_events_loopback_backoff_ttl', 2 * MINUTE_IN_SECONDS );
	set_transient( 'ec_events_loopback_backoff', 1, $ttl );
}

/**
 * Check if term exists on target site with published posts
 *
 * @param string $term_slug Term slug to check.
 * @param string $taxonomy  Taxonomy slug.
 * @param int    $blog_id   Target blog ID.
 * @return array|null Array with 'term_id' and 'count', or null if not found.
 */
function extrachill_check_term_on_site( $term_slug, $taxonomy, $blog_id ) {
	switch_to_blog( $blog_id );
	try {
		// Check if taxonomy exists on this site.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $term_slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		// Get actual post count (term->count may include unpublished).
		$post_types = get_taxonomy( $taxonomy )->object_type;
		$query      = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return array(
			'term_id' => $term->term_id,
			'count'   => $query->found_posts,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Normalize a URL to a blog's own trailing-slash rule.
 *
 * get_term_link()/get_permalink() under switch_to_blog() apply the ORIGIN
 * blog's $wp_rewrite trailing-slash rule, so URLs built cross-site can carry
 * a slash the target blog 301s away (or lack one it adds). Query-string and
 * fragment URLs are returned unchanged.
 *
 * @param string $url     URL on the target blog.
 * @param int    $blog_id Target blog ID.
 * @return string Canonical URL for that blog.
 */
function extrachill_normalize_url_for_blog( $url, $blog_id ) {
	$url = (string) $url;
	if ( '' === $url || false !== strpbrk( $url, '?#' ) ) {
		return $url;
	}

	$structure = (string) get_blog_option( (int) $blog_id, 'permalink_structure' );
	if ( '' === $structure ) {
		return $url;
	}

	return '/' === substr( $structure, -1 ) ? trailingslashit( $url ) : untrailingslashit( $url );
}

/**
 * Build taxonomy archive URL for a site
 *
 * @param string $term_slug Term slug.
 * @param string $taxonomy  Taxonomy slug.
 * @param int    $blog_id   Target blog ID.
 * @return string|null Archive URL or null on failure.
 */
function extrachill_build_term_archive_url( $term_slug, $taxonomy, $blog_id ) {
	switch_to_blog( $blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $term_slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return null;
		}

		return extrachill_normalize_url_for_blog( $url, $blog_id );
	} finally {
		restore_current_blog();
	}
}

/**
 * Invalidate the cross-site term links cache by bumping the generation key.
 *
 * The cached result of extrachill_get_cross_site_term_links() answers "does
 * this term have published content on the other network sites?" — an answer
 * that changes whenever relevant content is published, unpublished, or deleted
 * on ANY site (a new event, a new wire story, a new blog post). Per-term
 * invalidation isn't practical because the cache is keyed by term across
 * sites, so a single content change can affect many cached entries.
 *
 * Contract: an O(1) increment of the network-global generation counter. Every
 * cache key in this group is generation-prefixed (see
 * extrachill_cross_site_links_cache_key()), so the bump instantly orphans all
 * previous-generation entries; they age out via their TTL. This replaces the
 * former wp_cache_flush_group() call, which the Redis Object Cache drop-in
 * implements as a full-keyspace SCAN — an O(keyspace) stall (~0.8-1.5s at
 * 1M+ keys) that ran ~100x/hour under aggregator import load. Never call
 * wp_cache_flush_group() on this group.
 *
 * Coalesced per request: a batch of N inserts in one import job bumps the
 * generation once, since one bump already orphans everything. The guard is
 * the generation value seen at bump time, so a bump from another actor
 * (another worker, another site) re-arms it rather than suppressing a needed
 * bump.
 *
 * @return void
 */
function extrachill_cross_site_links_flush_cache_group() {
	static $last_bumped_generation = null;

	// Fresh read (not the per-request static): concurrent bumps from other
	// requests must be visible here for the coalescing guard to re-arm.
	$generation = (int) wp_cache_get( 'generation', EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );

	if ( null !== $last_bumped_generation && $generation === $last_bumped_generation ) {
		return;
	}

	$next = wp_cache_incr( 'generation', 1, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );

	if ( false === $next ) {
		$next = $generation + 1;
		wp_cache_set( 'generation', $next, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
	}

	$last_bumped_generation = (int) $next;

	// Drop the per-request static so later reads/writes build the new keys.
	extrachill_cross_site_links_cache_generation( true );
}

/**
 * Post types whose publish/unpublish/delete can change cross-site link answers.
 *
 * Spans every site mapped in extrachill_get_taxonomy_site_map(): blog posts
 * (main), events (events), festival wire (wire), products (shop), and artist
 * profiles (artist). Filterable so new content surfaces can opt in.
 *
 * @return string[] Post type slugs.
 */
function extrachill_cross_site_links_invalidating_post_types() {
	return apply_filters(
		'extrachill_cross_site_links_invalidating_post_types',
		array(
			'post',
			'data_machine_events',
			'festival_wire',
			'product',
			'artist_profile',
			'forum',
			'topic',
		)
	);
}

/**
 * Maybe bump the cross-site links generation when publish state changes.
 *
 * Fires on transition_post_status, not save_post: an update to an
 * already-published post (an aggregator price edit, a typo fix) does not
 * change the "does this term have published content on site X" answer, while
 * every wp_insert_post/wp_update_post fires save_post. A bump only happens
 * when a post enters or leaves 'publish', which is exactly when cached
 * cross-site answers can go stale. Skips autosaves/revisions and post types
 * that can't affect cross-site link answers.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function extrachill_cross_site_links_maybe_flush_on_transition( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status ) {
		return;
	}

	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}

	if ( ! $post instanceof WP_Post || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, extrachill_cross_site_links_invalidating_post_types(), true ) ) {
		return;
	}

	extrachill_cross_site_links_flush_cache_group();
}
add_action( 'transition_post_status', 'extrachill_cross_site_links_maybe_flush_on_transition', 10, 3 );

/**
 * Bump the cross-site links generation on hard delete of a relevant post.
 *
 * Deleted posts have no transition pair (status goes straight to removal), so
 * the deleted_post hook remains the invalidation point here.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object (WP 5.5+ passes this).
 * @return void
 */
function extrachill_cross_site_links_maybe_flush_on_delete( $post_id, $post = null ) {
	if ( $post instanceof WP_Post
		&& ! in_array( $post->post_type, extrachill_cross_site_links_invalidating_post_types(), true ) ) {
		return;
	}

	extrachill_cross_site_links_flush_cache_group();
}
add_action( 'deleted_post', 'extrachill_cross_site_links_maybe_flush_on_delete', 10, 2 );
