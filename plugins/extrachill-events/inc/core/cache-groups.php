<?php
/**
 * Object-Cache Group Tuning
 *
 * Registers high-cardinality, low-cross-request-reuse object-cache groups as
 * NON-PERSISTENT on the events site (blog 7) so they live in per-request
 * memory instead of being written to the persistent Redis backend forever.
 *
 * Background (extrachill-events#167): blog 7 holds ~79K published events across
 * many taxonomies. WordPress caches per-object term-relationship lists in the
 * `{$taxonomy}_relationships` groups with NO TTL, so the Redis keyspace grew to
 * ~2.34M persistent keys, pinned the object cache at its memory ceiling, and
 * triggered LRU thrashing that caused a network-wide login outage on
 * 2026-06-02. No event data is deleted — this only changes WHERE these cheap,
 * easily-recomputed lookups are stored.
 *
 * Why `{$taxonomy}_relationships` is safe to make non-persistent:
 *   - Each entry is just the list of term IDs attached to one object for one
 *     taxonomy (see get_object_term_cache() / update_object_term_cache() in
 *     wp-includes/taxonomy.php). Recomputing is a single, batched
 *     (wp_cache_get_multiple) term-relationship query.
 *   - Within a single request the WP_Object_Cache in-memory array still serves
 *     repeat reads, so the_loop on an archive page incurs at most one query per
 *     taxonomy, not one per post.
 *   - These groups are the dominant share of the blog-7 keyspace and the lowest
 *     risk to move off the persistent backend.
 *
 * Scoped to the events site only so the other network sites keep WordPress's
 * default persistent caching behavior.
 *
 * @package ExtraChillEvents
 * @since 0.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register non-persistent object-cache groups for the events site.
 *
 * Runs on `init` at a late priority so every taxonomy (WordPress core defaults,
 * data-machine-events, and the extrachill platform taxonomies) is registered
 * before we derive the group list. Term-relationship caches are only read
 * during query/loop execution (after `init`), so this timing is early enough to
 * intercept every persistent write for the request.
 *
 * @return void
 */
function extrachill_events_register_non_persistent_cache_groups() {
	if ( ! ec_is_events_site() ) {
		return;
	}

	if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
		return;
	}

	$groups = array();

	// Every registered taxonomy gets a `{$taxonomy}_relationships` object-cache
	// group. Derive the list dynamically so newly added taxonomies are covered
	// automatically and removed ones simply drop out.
	foreach ( get_taxonomies( array(), 'names' ) as $taxonomy ) {
		$groups[] = $taxonomy . '_relationships';
	}

	/**
	 * Filter the object-cache groups registered as non-persistent on the
	 * events site. Operators can add or remove groups without forking the
	 * plugin.
	 *
	 * @param array $groups Cache group names to mark non-persistent.
	 */
	$groups = apply_filters( 'extrachill_events_non_persistent_cache_groups', $groups );

	if ( empty( $groups ) ) {
		return;
	}

	wp_cache_add_non_persistent_groups( array_values( array_unique( $groups ) ) );
}
add_action( 'init', 'extrachill_events_register_non_persistent_cache_groups', 99 );
