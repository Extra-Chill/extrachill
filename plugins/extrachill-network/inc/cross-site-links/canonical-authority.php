<?php
/**
 * Canonical Authority Resolution
 *
 * Centralized system for determining canonical URLs for taxonomy archives
 * across the Extra Chill multisite network. When a taxonomy archive exists
 * on multiple sites, this determines which site is the authoritative source.
 *
 * @package ExtraChillNetwork
 * @since 1.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get canonical authority configuration for taxonomies
 *
 * Defines which site is canonical for each shared taxonomy.
 * - Single string: That site is always canonical
 * - Array: Priority cascade, first site with content wins
 *
 * Conditions:
 * - 'profile_with_image': Artist profile must exist with profile image
 * - 'has_posts': Site must have at least 1 published post for the term
 * - null: Just check if term exists on the canonical site
 *
 * @return array Taxonomy slug => canonical configuration
 */
function extrachill_get_taxonomy_canonical_config() {
	return apply_filters(
		'extrachill_taxonomy_canonical_config',
		array(
			'artist'   => array(
				'canonical' => 'artist',
				'condition' => 'profile_with_image',
			),
			'venue'    => array(
				'canonical' => 'events',
				'condition' => null,
			),
			'location' => array(
				'canonical' => array( 'main', 'wire', 'events' ),
				'condition' => 'has_posts',
			),
			'festival' => array(
				'canonical' => array( 'wire', 'main', 'events' ),
				'condition' => 'has_posts',
			),
		)
	);
}

/**
 * Get canonical authority URL for a taxonomy term
 *
 * Resolves the canonical URL for a taxonomy archive. Returns null if:
 * - Current site IS the canonical authority
 * - No canonical authority is configured for this taxonomy
 * - The canonical site doesn't have the term/content
 *
 * @param WP_Term|int $term     Term object or term ID.
 * @param string      $taxonomy Taxonomy slug.
 * @return string|null Canonical URL or null if self-canonical.
 */
function extrachill_get_canonical_authority_url( $term, $taxonomy ) {
	if ( is_int( $term ) ) {
		$term = get_term( $term, $taxonomy );
	}

	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$config = extrachill_get_taxonomy_canonical_config();
	if ( ! isset( $config[ $taxonomy ] ) ) {
		return null;
	}

	$taxonomy_config  = $config[ $taxonomy ];
	$canonical        = $taxonomy_config['canonical'];
	$condition        = $taxonomy_config['condition'] ?? null;
	$current_site_key = extrachill_get_current_site_key();

	// Handle priority cascade (array of sites).
	if ( is_array( $canonical ) ) {
		return extrachill_resolve_cascade_canonical( $term->slug, $taxonomy, $canonical, $condition, $current_site_key );
	}

	// Handle single canonical site.
	// If we're already on the canonical site, return null (self-canonical).
	if ( $current_site_key === $canonical ) {
		return null;
	}

	// Artist taxonomy has special handling (profile CPT, not taxonomy term).
	if ( 'artist' === $taxonomy && 'artist' === $canonical ) {
		return extrachill_resolve_artist_canonical( $term->slug );
	}

	// Standard taxonomy term canonical.
	return extrachill_resolve_term_canonical( $term->slug, $taxonomy, $canonical );
}

/**
 * Resolve canonical URL for artist taxonomy
 *
 * Artist is special because canonical points to artist_profile CPT,
 * not a taxonomy archive. Also requires profile image to exist.
 *
 * @param string $slug Artist slug.
 * @return string|null Canonical URL or null if profile doesn't qualify.
 */
function extrachill_resolve_artist_canonical( $slug ) {
	if ( ! extrachill_artist_profile_has_image( $slug ) ) {
		return null;
	}

	$profile = extrachill_get_artist_profile_by_slug( $slug );
	if ( ! $profile ) {
		return null;
	}

	return $profile['permalink'];
}

/**
 * Check if artist profile exists and has a profile image
 *
 * @param string $slug Artist slug.
 * @return bool True if profile exists with image.
 */
function extrachill_artist_profile_has_image( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( empty( $slug ) ) {
		return false;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$posts = get_posts(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return false;
		}

		$artist_id    = (int) $posts[0];
		$thumbnail_id = get_post_thumbnail_id( $artist_id );

		return ! empty( $thumbnail_id );
	} finally {
		restore_current_blog();
	}
}

/**
 * Resolve canonical URL for standard taxonomy term
 *
 * Used for venue, location - single canonical site.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @param string $site_key Canonical site key.
 * @return string|null Canonical URL or null if term doesn't exist.
 */
function extrachill_resolve_term_canonical( $slug, $taxonomy, $site_key ) {
	$blog_id = ec_get_blog_id( $site_key );
	if ( ! $blog_id ) {
		return null;
	}

	switch_to_blog( $blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return null;
		}

		return $url;
	} finally {
		restore_current_blog();
	}
}

/**
 * Resolve canonical URL using priority cascade
 *
 * Used for festival taxonomy - checks sites in order, first with content wins.
 *
 * @param string   $slug             Term slug.
 * @param string   $taxonomy         Taxonomy slug.
 * @param array    $site_keys        Priority-ordered array of site keys.
 * @param string   $condition        Condition type ('has_posts' or null).
 * @param string   $current_site_key Current site's key.
 * @return string|null Canonical URL or null if no site qualifies or current site is canonical.
 */
function extrachill_resolve_cascade_canonical( $slug, $taxonomy, $site_keys, $condition, $current_site_key ) {
	if ( 'festival' === $taxonomy ) {
		$festival_canonical = extrachill_resolve_festival_canonical( $slug, $site_keys, $condition, $current_site_key );
		if ( null !== $festival_canonical ) {
			return $festival_canonical;
		}
	}

	foreach ( $site_keys as $site_key ) {
		$blog_id = ec_get_blog_id( $site_key );
		if ( ! $blog_id ) {
			continue;
		}

		$has_content = extrachill_site_has_taxonomy_content( $slug, $taxonomy, $blog_id, $condition );

		if ( $has_content ) {
			// This site is the canonical authority.
			// If it's the current site, return null (self-canonical).
			if ( $site_key === $current_site_key ) {
				return null;
			}

			// Build and return the canonical URL.
			return extrachill_resolve_term_canonical( $slug, $taxonomy, $site_key );
		}
	}

	// No site in cascade has content, remain self-canonical.
	return null;
}

/**
 * Resolve canonical for festival taxonomy.
 *
 * Canonical order uses normal site cascade rules, but if both wire and main
 * qualify, published post count is used as a tie-breaker between them.
 *
 * @param string      $slug             Festival term slug.
 * @param array       $site_keys        Priority-ordered site keys.
 * @param string|null $condition        Condition type ('has_posts' or null).
 * @param string      $current_site_key Current site key.
 * @return string|null Canonical URL, null for self-canonical, or null to defer.
 */
function extrachill_resolve_festival_canonical( $slug, $site_keys, $condition, $current_site_key ) {
	$qualified_sites = array();

	foreach ( $site_keys as $site_key ) {
		$blog_id = ec_get_blog_id( $site_key );
		if ( ! $blog_id ) {
			continue;
		}

		if ( extrachill_site_has_taxonomy_content( $slug, 'festival', $blog_id, $condition ) ) {
			$qualified_sites[] = $site_key;
		}
	}

	if ( empty( $qualified_sites ) ) {
		return null;
	}

	$wire_has_content = in_array( 'wire', $qualified_sites, true );
	$main_has_content = in_array( 'main', $qualified_sites, true );

	// If both main and wire qualify, use post count tie-breaker.
	if ( $wire_has_content && $main_has_content ) {
		$wire_count = extrachill_get_festival_term_post_count( $slug, 'wire' );
		$main_count = extrachill_get_festival_term_post_count( $slug, 'main' );

		if ( $wire_count > $main_count ) {
			return ( 'wire' === $current_site_key ) ? null : extrachill_resolve_term_canonical( $slug, 'festival', 'wire' );
		}

		// Blog wins on tie or if it has more.
		return ( 'main' === $current_site_key ) ? null : extrachill_resolve_term_canonical( $slug, 'festival', 'main' );
	}

	// Default: first qualifying site in cascade order.
	$canonical_site_key = $qualified_sites[0];
	return ( $canonical_site_key === $current_site_key ) ? null : extrachill_resolve_term_canonical( $slug, 'festival', $canonical_site_key );
}

/**
 * Get published post count for a festival term on a site.
 *
 * Used as tie-breaker between wire and main when both qualify.
 *
 * @param string $slug     Festival term slug.
 * @param string $site_key Site key.
 * @return int Published post count.
 */
function extrachill_get_festival_term_post_count( $slug, $site_key ) {
	$blog_id = ec_get_blog_id( $site_key );
	if ( ! $blog_id ) {
		return 0;
	}

	switch_to_blog( $blog_id );
	try {
		if ( ! taxonomy_exists( 'festival' ) ) {
			return 0;
		}

		$term = get_term_by( 'slug', $slug, 'festival' );
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		$post_types = get_taxonomy( 'festival' )->object_type;
		$query      = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'posts_per_page' => 1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'festival',
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			)
		);

		return (int) $query->found_posts;
	} finally {
		restore_current_blog();
	}
}

/**
 * Check if site has content for a taxonomy term
 *
 * @param string      $slug      Term slug.
 * @param string      $taxonomy  Taxonomy slug.
 * @param int         $blog_id   Blog ID to check.
 * @param string|null $condition Condition type.
 * @return bool True if site has qualifying content.
 */
function extrachill_site_has_taxonomy_content( $slug, $taxonomy, $blog_id, $condition ) {
	switch_to_blog( $blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		// If no condition, just check term exists.
		if ( null === $condition ) {
			return true;
		}

		// Check for published posts.
		if ( 'has_posts' === $condition ) {
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
					'no_found_rows'  => true,
				)
			);

			return $query->post_count > 0;
		}

		return false;
	} finally {
		restore_current_blog();
	}
}
