<?php
/**
 * Forward-Surface Candidates for Internal Linking
 *
 * Data Machine's internal-linking task (`datamachine links crosslink`) discovers
 * link targets only from same-site posts that share a category or tag with the
 * source post. On Extra Chill's main blog that means a residual song-meaning
 * page can only ever link to *other* residual pages — it deepens the very silo
 * the linking pass is meant to break.
 *
 * This hook adds the network dimension Data Machine core deliberately does not
 * have. For a source post it takes the shared cross-site taxonomy terms
 * (artist, venue, location, festival), resolves where those terms have live
 * content on sibling sites via the existing cross-site term-link primitive, and
 * offers those sibling URLs as additional linking candidates — biased above the
 * same-site catalog so traffic is routed toward forward surfaces (events,
 * community, news wire) rather than back into the silo.
 *
 * Data Machine core stays network-agnostic: all Extra Chill site knowledge
 * (which taxonomies are shared, which sites count as forward surfaces, how to
 * resolve a term's cross-site URL) lives here, behind the
 * `datamachine_internal_linking_candidates` filter.
 *
 * @package ExtraChillNetwork
 * @since   1.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-site taxonomies that meaningfully connect a post to forward surfaces.
 *
 * These are the shared-entity taxonomies (not the generic category/post_tag
 * Data Machine already matches on) whose terms exist across sites and resolve
 * to a real archive/profile URL via extrachill_get_cross_site_term_links().
 *
 * @return array<int, string> Taxonomy slugs, in priority order.
 */
function extrachill_internal_linking_cross_site_taxonomies() {
	/**
	 * Filter the taxonomies used to discover cross-site internal-linking targets.
	 *
	 * @since 1.19.0
	 *
	 * @param array<int, string> $taxonomies Taxonomy slugs.
	 */
	return apply_filters(
		'extrachill_internal_linking_cross_site_taxonomies',
		array( 'artist', 'venue', 'festival', 'location' )
	);
}

/**
 * Taxonomy tiers that decide which cross-site matches may win.
 *
 * The crosslink engine must prefer the entity an article is *about* (the
 * artist, the festival) over an incidental geographic mention (a "California"
 * location tag on a song whose writers happen to be Californian). A geographic
 * match is a far weaker relevance signal, so:
 *
 *   - `entity` taxonomies (artist/festival) always outrank `geography`
 *     taxonomies (location/venue), and
 *   - a `geography` candidate is suppressed entirely when the post has no
 *     `entity` candidate — inserting nothing beats inserting a tangential link.
 *
 * Any taxonomy not listed here is treated as `entity` (it carries a specific
 * subject, not a place), so new shared taxonomies default to the safe tier.
 *
 * @return array{entity: string[], geography: string[]} Tier => taxonomy slugs.
 */
function extrachill_internal_linking_taxonomy_tiers() {
	/**
	 * Filter the entity-vs-geography tiering used to rank cross-site candidates.
	 *
	 * @since 1.20.0
	 *
	 * @param array{entity: string[], geography: string[]} $tiers Tier map.
	 */
	return apply_filters(
		'extrachill_internal_linking_taxonomy_tiers',
		array(
			'entity'    => array( 'artist', 'festival' ),
			'geography' => array( 'location', 'venue' ),
		)
	);
}

/**
 * Whether a taxonomy is an incidental-geography taxonomy for crosslink ranking.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return bool True when the taxonomy is in the geography tier.
 */
function extrachill_internal_linking_is_geography_taxonomy( $taxonomy ) {
	$tiers = extrachill_internal_linking_taxonomy_tiers();
	return in_array( $taxonomy, (array) $tiers['geography'], true );
}

/**
 * Minimum UPCOMING events an artist/festival archive needs to be link-worthy.
 *
 * The events resolver returns the artist/festival *archive* page
 * (`/artist/<slug>`). Crosslinks are written permanently into post content,
 * so an events archive is only linked when it has enough UPCOMING published
 * shows to be worth the click; an archive with only past shows is skipped.
 *
 * @param string $taxonomy Taxonomy slug being resolved (artist/festival).
 * @param int    $post_id  Source post being linked from.
 * @return int Minimum upcoming events. Default 3.
 */
function extrachill_internal_linking_min_events_archive_count( $taxonomy, $post_id ) {
	/**
	 * Filter the minimum upcoming events an events archive needs to be linked.
	 *
	 * @since 1.20.0
	 *
	 * @param int    $min      Minimum upcoming events. Default 3.
	 * @param string $taxonomy Taxonomy slug (artist/festival).
	 * @param int    $post_id  Source post ID.
	 */
	return (int) apply_filters( 'extrachill_internal_linking_min_artist_events', 3, $taxonomy, $post_id );
}

/**
 * Whether a source article is news-adjacent enough for wire location links.
 *
 * @param int $post_id Source post ID.
 * @return bool
 */
function extrachill_internal_linking_is_wire_location_relevant( $post_id ) {
	$categories = apply_filters( 'extrachill_internal_linking_wire_location_categories', array( 'music-news', 'festival-news', 'festival-recap', 'festival-preview' ), $post_id );
	$assigned   = wp_get_post_categories( $post_id, array( 'fields' => 'slugs' ) );
	return (bool) array_intersect( (array) $categories, (array) $assigned );
}

/**
 * Site keys treated as forward surfaces.
 *
 * Candidates on these sites get a score boost so they outrank same-site
 * residual catalog candidates when Data Machine re-ranks the merged list. Any
 * other cross-site target (e.g. shop) is still offered, just without the boost.
 *
 * @return array<int, string> Forward-surface site keys.
 */
function extrachill_internal_linking_forward_surface_keys() {
	/**
	 * Filter which sibling sites count as "forward surfaces" for linking bias.
	 *
	 * @since 1.19.0
	 *
	 * @param array<int, string> $keys Site keys (see ec_get_blog_ids()).
	 */
	return apply_filters(
		'extrachill_internal_linking_forward_surface_keys',
		array( 'events', 'community', 'wire' )
	);
}

/**
 * Inject cross-site forward-surface candidates into internal linking.
 *
 * Hooked to Data Machine's `datamachine_internal_linking_candidates` filter.
 * Returns the original same-site candidates plus any cross-site candidates
 * discovered for the source post's shared-taxonomy terms. Forward-surface
 * candidates carry a score above the highest same-site score so they win
 * Data Machine's re-rank without discarding the in-catalog options.
 *
 * @param array  $candidates   Same-site candidates discovered by Data Machine core.
 * @param int    $post_id      Source post being linked from.
 * @param string $source_title Source post title (unused; kept for filter contract).
 * @param array  $categories   Source post category term IDs (unused here).
 * @param array  $tags         Source post tag term IDs (unused here).
 * @param int    $limit        Maximum candidates Data Machine will keep.
 * @return array Merged candidate list.
 */
function extrachill_add_cross_site_linking_candidates( $candidates, $post_id, $source_title, $categories, $tags, $limit ) {
	unset( $source_title, $categories, $tags, $limit );

	$post_id = absint( $post_id );
	if ( $post_id <= 0 ) {
		return $candidates;
	}

	// Requires the cross-site term-link primitive from this plugin.
	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return $candidates;
	}

	$candidates = (array) $candidates;

	// Highest existing same-site score — forward-surface candidates are
	// boosted just above it so they rank first without erasing the catalog.
	$max_same_site_score = 0.0;
	foreach ( $candidates as $candidate ) {
		if ( isset( $candidate['score'] ) && (float) $candidate['score'] > $max_same_site_score ) {
			$max_same_site_score = (float) $candidate['score'];
		}
	}

	$forward_keys   = extrachill_internal_linking_forward_surface_keys();
	$forward_boost  = (float) apply_filters( 'extrachill_internal_linking_forward_boost', 100.0, $post_id );
	$seen_urls      = array();
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;

	// Avoid offering a URL the post already links to.
	foreach ( $candidates as $candidate ) {
		if ( ! empty( $candidate['url'] ) ) {
			$seen_urls[ trailingslashit( (string) $candidate['url'] ) ] = true;
		}
	}

	// Collect candidates split by tier so geography can never outrank — or
	// substitute for — the entity the article is actually about. A flat
	// per-taxonomy boost (the old behaviour) let a "California" location match
	// beat the artist match purely on iteration order; tiering fixes that.
	$entity_candidates    = array();
	$geography_candidates = array();

	foreach ( extrachill_internal_linking_cross_site_taxonomies() as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}

		$is_geography = extrachill_internal_linking_is_geography_taxonomy( $taxonomy );

		foreach ( $terms as $term ) {
			$links = (array) extrachill_get_cross_site_term_links( $term, $taxonomy );

			// Events destinations must have enough UPCOMING published shows.
			// The shared resolver already gates the events site on upcoming
			// events and reports that upcoming count; enforce the floor on it
			// so an archive with only past shows is never linked.
			if ( ! $is_geography ) {
				$links = array_values(
					array_filter(
						$links,
						static function ( $link ) use ( $events_blog_id, $taxonomy, $post_id ) {
							$is_events = 'events' === ( $link['site_key'] ?? '' ) || ( $events_blog_id > 0 && (int) ( $link['blog_id'] ?? 0 ) === $events_blog_id );
							if ( ! $is_events ) {
								return true;
							}
							return (int) ( $link['count'] ?? 0 ) >= extrachill_internal_linking_min_events_archive_count( $taxonomy, $post_id );
						}
					)
				);
			}

			if ( empty( $links ) ) {
				continue;
			}

			foreach ( $links as $link ) {
				if ( empty( $link['url'] ) ) {
					continue;
				}

				$url      = (string) $link['url'];
				$site_key = isset( $link['site_key'] ) ? (string) $link['site_key'] : '';
				// Resolver results may be cached from before URL normalization;
				// canonicalize every cross-site URL to its target blog's slash rule.
				if ( ! empty( $link['blog_id'] ) && function_exists( 'extrachill_normalize_url_for_blog' ) ) {
					$url = extrachill_normalize_url_for_blog( $url, (int) $link['blog_id'] );
				}
				// Never write a query-string URL (e.g. a ?p= permalink) into post content.
				if ( false !== strpos( $url, '?' ) ) {
					continue;
				}
				if ( 'location' === $taxonomy && 'wire' === $site_key && ! extrachill_internal_linking_is_wire_location_relevant( $post_id ) ) {
					continue;
				}
				$url_key = trailingslashit( $url );
				if ( isset( $seen_urls[ $url_key ] ) ) {
					continue;
				}
				$seen_urls[ $url_key ] = true;
				$is_forward            = in_array( $site_key, $forward_keys, true );
				$term_name             = isset( $link['term_name'] ) ? (string) $link['term_name'] : $term->name;
				$site_label            = isset( $link['label'] ) ? (string) $link['label'] : ucfirst( $site_key );

				// Title is what the AI weaves the anchor around. Use the term
				// name so the link reads naturally in prose (e.g. the artist or
				// venue the source post is about).
				$title = $term_name;

				// Score: forward surfaces boosted above same-site; others sit
				// just below the boost but still above a zero-score catalog tail.
				// Geography candidates are demoted a further notch below entity
				// candidates so a place match never outranks the subject match.
				$score = $is_forward
					? $max_same_site_score + $forward_boost
					: $max_same_site_score + ( $forward_boost / 2 );
				if ( $is_geography ) {
					$score -= $forward_boost / 4;
				}

				$candidate_row = array(
					'id'      => 0,
					'url'     => $url,
					'title'   => $title,
					'excerpt' => sprintf(
						/* translators: 1: term name, 2: sibling site label. */
						__( '%1$s on %2$s', 'extrachill-network' ),
						$term_name,
						$site_label
					),
					'score'   => $score,
				);

				if ( $is_geography ) {
					$geography_candidates[] = $candidate_row;
				} else {
					$entity_candidates[] = $candidate_row;
				}
			}
		}
	}

	// Incidental-geography suppression: only consider place matches when the
	// post actually has an entity (artist/festival) cross-site candidate. With
	// no subject match, a bare geographic archive link is worse than no link.
	$cross_site = $entity_candidates;
	if ( ! empty( $entity_candidates ) && ! empty( $geography_candidates ) ) {
		$cross_site = array_merge( $cross_site, $geography_candidates );
	}

	if ( empty( $cross_site ) ) {
		return $candidates;
	}

	return array_merge( $candidates, $cross_site );
}

add_filter( 'datamachine_internal_linking_candidates', 'extrachill_add_cross_site_linking_candidates', 10, 6 );
