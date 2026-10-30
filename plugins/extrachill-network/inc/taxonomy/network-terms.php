<?php
/**
 * Approved network taxonomy identities, projections, and post assignments.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post meta containing classifier fingerprints and AI-owned term identities. */
const EXTRACHILL_NETWORK_TERM_PROVENANCE_META = '_extrachill_network_term_classifier';

/** Whether classifier-owned writes are in progress in this request. */
$GLOBALS['extrachill_network_term_classifier_writing'] = false;

/**
 * Return the explicit target and approved-source policy.
 *
 * Events is intentionally absent as a source for artist, festival, and
 * location: scraper-created terms are not approved merely because an event
 * relationship exists. Venue is the exception — events owns the canonical
 * venue identity (see extrachill_get_taxonomy_canonical_config()).
 *
 * Genre is sourced from main and targets main/post, community/topic,
 * events/data_machine_events, and artist/artist_profile: genre is a
 * network-wide artist fact, but Data Machine's import path can never write
 * it onto events (see inc/taxonomy/genre.php) — the events site only
 * receives it through approved projection.
 *
 * @return array<string,mixed>
 */
function extrachill_network_get_term_policy() {
	return apply_filters(
		'extrachill_network_term_policy',
		array(
			'targets' => array(
				'main'      => array( 'post' => array( 'artist', 'festival', 'genre', 'location', 'venue' ) ),
				'community' => array( 'topic' => array( 'artist', 'festival', 'genre', 'location' ) ),
				'wire'      => array( 'festival_wire' => array( 'festival', 'location' ) ),
				'events'    => array( 'data_machine_events' => array( 'genre' ) ),
				'artist'    => array( 'artist_profile' => array( 'genre' ) ),
			),
			'sources' => array(
				'artist'   => array( 'main' ),
				'festival' => array( 'wire', 'main' ),
				'genre'    => array( 'main' ),
				'location' => array( 'main', 'wire' ),
				'venue'    => array( 'events' ),
			),
		)
	);
}

/**
 * Resolve configured taxonomies for one target and intersect with runtime registration.
 *
 * Must be called while the target blog is current. WordPress does not reload
 * site-specific plugin code when switch_to_blog() changes database context.
 *
 * @param string $site_key   Logical site key.
 * @param string $post_type  Post type.
 * @param array  $requested  Optional requested taxonomy allowlist.
 * @return string[]
 */
function extrachill_network_get_eligible_term_taxonomies( $site_key, $post_type, $requested = array() ) {
	$policy     = extrachill_network_get_term_policy();
	$configured = $policy['targets'][ $site_key ][ $post_type ] ?? array();
	$requested  = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $requested ) ) ) );

	if ( $requested ) {
		$configured = array_values( array_intersect( $configured, $requested ) );
	}

	return array_values(
		array_filter(
			$configured,
			static function ( $taxonomy ) use ( $post_type ) {
				return taxonomy_exists( $taxonomy ) && post_type_exists( $post_type ) && is_object_in_taxonomy( $post_type, $taxonomy );
			}
		)
	);
}

/**
 * Return statuses eligible for classification on one configured target.
 *
 * bbPress keeps closed topics public, so they retain the same classification
 * lifecycle as published topics.
 *
 * @param string $site_key  Logical site key.
 * @param string $post_type Post type.
 * @return string[]
 */
function extrachill_network_get_eligible_term_statuses( $site_key, $post_type ) {
	$statuses = array( 'pending', 'publish' );
	if ( 'community' === $site_key && 'topic' === $post_type ) {
		$statuses[] = 'closed';
	}

	return array_values( array_unique( apply_filters( 'extrachill_network_term_classification_statuses', $statuses, $site_key, $post_type ) ) );
}

/**
 * Read one approved source term into the network identity shape.
 *
 * @param WP_Term $term       Source term.
 * @param string  $taxonomy   Taxonomy slug.
 * @param string  $source_key Source site key.
 * @return array<string,mixed>
 */
function extrachill_network_prepare_term_candidate( $term, $taxonomy, $source_key ) {
	$parent = null;
	if ( ! empty( $term->parent ) ) {
		$parent_term = get_term( (int) $term->parent, $taxonomy );
		if ( $parent_term && ! is_wp_error( $parent_term ) ) {
			$parent = array(
				'taxonomy' => $taxonomy,
				'slug'     => $parent_term->slug,
				'name'     => $parent_term->name,
			);
		}
	}

	return array(
		'taxonomy'       => $taxonomy,
		'slug'           => $term->slug,
		'name'           => $term->name,
		'description'    => $term->description,
		'parent'         => $parent,
		'source'         => $source_key,
		'source_term_id' => (int) $term->term_id,
	);
}

/**
 * Resolve an approved identity by taxonomy and slug.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param string $slug     Term slug.
 * @return array<string,mixed>|WP_Error
 */
function extrachill_network_resolve_term_identity( $taxonomy, $slug ) {
	$taxonomy = sanitize_key( $taxonomy );
	$slug     = sanitize_title( $slug );
	$policy   = extrachill_network_get_term_policy();
	$sources  = $policy['sources'][ $taxonomy ] ?? array();

	if ( '' === $slug || empty( $sources ) ) {
		return new WP_Error( 'unapproved_network_term', __( 'That taxonomy identity is not approved for network projection.', 'extrachill-network' ) );
	}

	foreach ( $sources as $source_key ) {
		$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $source_key ) : 0;
		if ( ! $blog_id ) {
			continue;
		}

		switch_to_blog( $blog_id );
		try {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) {
				return extrachill_network_prepare_term_candidate( $term, $taxonomy, $source_key );
			}
		} finally {
			restore_current_blog();
		}
	}

	return new WP_Error( 'network_term_not_found', __( 'No approved network source contains that taxonomy identity.', 'extrachill-network' ) );
}

/**
 * Search approved source terms without creating local projections.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param string $query    Name or slug query.
 * @param int    $limit    Maximum results.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function extrachill_network_search_terms( $taxonomy, $query, $limit = 20 ) {
	$taxonomy = sanitize_key( $taxonomy );
	$query    = sanitize_text_field( $query );
	$limit    = min( 50, max( 1, absint( $limit ) ) );
	$policy   = extrachill_network_get_term_policy();
	$sources  = $policy['sources'][ $taxonomy ] ?? array();

	if ( '' === trim( $query ) || empty( $sources ) ) {
		return new WP_Error( 'invalid_network_term_search', __( 'An approved taxonomy and non-empty query are required.', 'extrachill-network' ) );
	}

	$results = array();
	$slug    = sanitize_title( $query );
	foreach ( $sources as $source_key ) {
		$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $source_key ) : 0;
		if ( ! $blog_id ) {
			continue;
		}

		switch_to_blog( $blog_id );
		try {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'search'     => $query,
					'number'     => $limit,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$exact = get_term_by( 'slug', $slug, $taxonomy );
			if ( $exact ) {
				array_unshift( $terms, $exact );
			}

			foreach ( $terms as $term ) {
				$identity = $taxonomy . ':' . $term->slug;
				if ( ! isset( $results[ $identity ] ) ) {
					$results[ $identity ] = extrachill_network_prepare_term_candidate( $term, $taxonomy, $source_key );
				}
				if ( count( $results ) >= $limit ) {
					break 2;
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	return array_values( $results );
}

/**
 * Materialize one approved identity on the current target site.
 *
 * @param string $site_key  Target site key.
 * @param string $post_type Target post type.
 * @param string $taxonomy  Taxonomy slug.
 * @param string $slug      Approved term slug.
 * @param bool   $dry_run   Whether to report without writing.
 * @return array<string,mixed>|WP_Error
 */
function extrachill_network_project_term( $site_key, $post_type, $taxonomy, $slug, $dry_run = false ) {
	$site_key  = sanitize_key( $site_key );
	$post_type = sanitize_key( $post_type );
	$taxonomy  = sanitize_key( $taxonomy );
	$slug      = sanitize_title( $slug );
	$eligible  = extrachill_network_get_eligible_term_taxonomies( $site_key, $post_type, array( $taxonomy ) );
	if ( ! in_array( $taxonomy, $eligible, true ) ) {
		return new WP_Error( 'unsupported_term_projection', __( 'The taxonomy is not registered for that target in this runtime.', 'extrachill-network' ) );
	}

	$candidate = extrachill_network_resolve_term_identity( $taxonomy, $slug );
	if ( is_wp_error( $candidate ) ) {
		return $candidate;
	}

	$existing = get_term_by( 'slug', $slug, $taxonomy );
	if ( $existing ) {
		return array(
			'taxonomy' => $taxonomy,
			'slug'     => $existing->slug,
			'term_id'  => (int) $existing->term_id,
			'created'  => false,
			'source'   => $candidate['source'],
		);
	}

	$parent_id = 0;
	if ( ! empty( $candidate['parent']['slug'] ) ) {
		$parent = extrachill_network_project_term( $site_key, $post_type, $taxonomy, $candidate['parent']['slug'], $dry_run );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$parent_id = (int) $parent['term_id'];
	}

	if ( $dry_run ) {
		return array(
			'taxonomy' => $taxonomy,
			'slug'     => $candidate['slug'],
			'term_id'  => 0,
			'created'  => true,
			'dry_run'  => true,
		);
	}

	$inserted = wp_insert_term(
		$candidate['name'],
		$taxonomy,
		array(
			'slug'        => $candidate['slug'],
			'description' => $candidate['description'],
			'parent'      => $parent_id,
		)
	);
	if ( is_wp_error( $inserted ) ) {
		$term_id = (int) $inserted->get_error_data( 'term_exists' );
		if ( $term_id > 0 ) {
			return array(
				'taxonomy' => $taxonomy,
				'slug'     => $candidate['slug'],
				'term_id'  => $term_id,
				'created'  => false,
			);
		}
		return $inserted;
	}

	return array(
		'taxonomy' => $taxonomy,
		'slug'     => $candidate['slug'],
		'term_id'  => (int) $inserted['term_id'],
		'created'  => true,
	);
}

/**
 * Fingerprint meaningful post text.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function extrachill_network_term_classification_fingerprint( $post ) {
	$title   = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $post->post_title ) ) );
	$content = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) );

	return hash( 'sha256', $title . "\n" . $content );
}

/**
 * Whether this exact text has already been sent for classification.
 *
 * extrachill_network_term_classification_is_current() only recognises
 * fingerprints that *succeeded*, which is the right test before doing the
 * work again on demand. It is the wrong test for deciding whether routine
 * post churn should pay for a new job: a post whose classification failed,
 * or never wrote provenance, looks unclassified forever and reschedules on
 * every single touch.
 *
 * Measured on the events site: 86% of 131,293 published events carry no
 * classifier provenance, so a metadata-only pass over them scheduled a paid
 * job each, every day, with no way to converge.
 *
 * This checks attempts rather than successes, so unchanged text is only ever
 * sent once per lane. Changed text still classifies.
 *
 * @param int    $post_id     Post ID.
 * @param string $fingerprint Current text fingerprint.
 * @return bool True when this text was already scheduled at least once.
 */
function extrachill_network_term_classification_already_attempted( $post_id, $fingerprint ) {
	$job_states = get_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_CLASSIFICATION_JOBS_META, true );
	if ( ! is_array( $job_states ) ) {
		return false;
	}

	foreach ( $job_states as $state ) {
		if ( ! is_array( $state ) || ! isset( $state['fingerprint'] ) ) {
			continue;
		}
		if ( hash_equals( (string) $state['fingerprint'], (string) $fingerprint ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether all requested taxonomies succeeded for this fingerprint.
 *
 * @param int      $post_id    Post ID.
 * @param string[] $taxonomies Taxonomies.
 * @param string   $fingerprint Fingerprint.
 * @return bool
 */
function extrachill_network_term_classification_is_current( $post_id, $taxonomies, $fingerprint ) {
	$provenance   = get_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META, true );
	$fingerprints = is_array( $provenance['fingerprints'] ?? null ) ? $provenance['fingerprints'] : array();

	foreach ( $taxonomies as $taxonomy ) {
		if ( ! isset( $fingerprints[ $taxonomy ] ) || ! hash_equals( (string) $fingerprints[ $taxonomy ], $fingerprint ) ) {
			return false;
		}
	}
	return ! empty( $taxonomies );
}

/**
 * Find bounded approved candidates explicitly mentioned in post text.
 *
 * @param WP_Post  $post       Post object.
 * @param string[] $taxonomies Taxonomies.
 * @return array<int,array<string,mixed>>
 */
function extrachill_network_find_post_term_candidates( $post, $taxonomies ) {
	$text       = html_entity_decode( wp_strip_all_tags( (string) $post->post_title . ' ' . strip_shortcodes( (string) $post->post_content ) ), ENT_QUOTES, 'UTF-8' );
	$text       = strtolower( preg_replace( '/\s+/', ' ', $text ) );
	$policy     = extrachill_network_get_term_policy();
	$candidates = array();

	foreach ( $taxonomies as $taxonomy ) {
		foreach ( $policy['sources'][ $taxonomy ] ?? array() as $source_key ) {
			$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $source_key ) : 0;
			if ( ! $blog_id ) {
				continue;
			}

			switch_to_blog( $blog_id );
			try {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 2000,
					)
				);
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$needle   = strtolower( trim( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ) );
					$identity = $taxonomy . ':' . $term->slug;
					if ( mb_strlen( $needle ) >= 3 && false !== mb_stripos( $text, $needle ) && ! isset( $candidates[ $identity ] ) ) {
						$candidates[ $identity ] = extrachill_network_prepare_term_candidate( $term, $taxonomy, $source_key );
					}
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	$candidates = array_values( $candidates );
	usort( $candidates, static fn( $a, $b ) => strlen( $b['name'] ) <=> strlen( $a['name'] ) );

	return array_slice( $candidates, 0, 75 );
}

/**
 * Apply classifier selections while preserving every human-owned assignment.
 *
 * @param array $args {site, post_id, taxonomies?, fingerprint?, force?, dry_run?}.
 * @param mixed $selector Receives post and approved candidates when callable.
 * @return array<string,mixed>|WP_Error
 */
function extrachill_network_classify_post_terms( $args, $selector ) {
	$site_key = sanitize_key( $args['site'] ?? '' );
	$post_id  = absint( $args['post_id'] ?? 0 );
	$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
	if ( ! $blog_id || ! $post_id || ! is_callable( $selector ) ) {
		return new WP_Error( 'invalid_classification_target', __( 'A valid site, post, and selector are required.', 'extrachill-network' ) );
	}

	switch_to_blog( $blog_id );
	try {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'classification_post_not_found', __( 'The classification target was not found.', 'extrachill-network' ) );
		}

		$taxonomies = extrachill_network_get_eligible_term_taxonomies( $site_key, $post->post_type, $args['taxonomies'] ?? array() );
		$statuses   = extrachill_network_get_eligible_term_statuses( $site_key, $post->post_type );
		if ( empty( $taxonomies ) || ! in_array( $post->post_status, $statuses, true ) ) {
			return new WP_Error( 'unsupported_classification_target', __( 'The post status or target policy is not eligible for classification.', 'extrachill-network' ) );
		}

		$content_text = trim( wp_strip_all_tags( (string) $post->post_title . ' ' . strip_shortcodes( (string) $post->post_content ) ) );
		if ( mb_strlen( $content_text ) < (int) apply_filters( 'extrachill_network_term_classification_min_length', 40, $post ) ) {
			return new WP_Error( 'insufficient_classification_content', __( 'The post does not contain enough meaningful text to classify.', 'extrachill-network' ) );
		}

		$fingerprint = extrachill_network_term_classification_fingerprint( $post );
		if ( ! empty( $args['fingerprint'] ) && ! hash_equals( (string) $args['fingerprint'], $fingerprint ) ) {
			return array(
				'skipped'     => true,
				'reason'      => 'stale_fingerprint',
				'fingerprint' => $fingerprint,
			);
		}
		if ( empty( $args['force'] ) && extrachill_network_term_classification_is_current( $post_id, $taxonomies, $fingerprint ) ) {
			return array(
				'skipped'     => true,
				'reason'      => 'identical_fingerprint',
				'fingerprint' => $fingerprint,
			);
		}

		$candidates = extrachill_network_find_post_term_candidates( $post, $taxonomies );
		$selections = empty( $candidates ) ? array() : call_user_func( $selector, $post, $candidates, $taxonomies );
		if ( is_wp_error( $selections ) ) {
			return $selections;
		}

		$candidate_map = array();
		foreach ( $candidates as $candidate ) {
			$candidate_map[ $candidate['taxonomy'] . ':' . $candidate['slug'] ] = $candidate;
		}
		$selected = array_fill_keys( $taxonomies, array() );
		foreach ( (array) $selections as $selection ) {
			$taxonomy = sanitize_key( $selection['taxonomy'] ?? '' );
			$slug     = sanitize_title( $selection['slug'] ?? '' );
			$identity = $taxonomy . ':' . $slug;
			if ( in_array( $taxonomy, $taxonomies, true ) && isset( $candidate_map[ $identity ] ) && (float) ( $selection['confidence'] ?? 0 ) >= 0.85 ) {
				$selected[ $taxonomy ][ $slug ] = $candidate_map[ $identity ];
			}
		}

		$previous_provenance          = get_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META, true );
		$previous_provenance          = is_array( $previous_provenance ) ? $previous_provenance : array();
		$provenance                   = $previous_provenance;
		$provenance['schema_version'] = 1;
		$provenance['fingerprints']   = is_array( $provenance['fingerprints'] ?? null ) ? $provenance['fingerprints'] : array();
		$provenance['terms']          = is_array( $provenance['terms'] ?? null ) ? $provenance['terms'] : array();
		$previous_terms               = array();
		$assignment_plan              = array();
		$assignment_identities        = array();
		$projection_plan              = array();

		foreach ( $taxonomies as $taxonomy ) {
			$current = wp_get_object_terms( $post_id, $taxonomy );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$previous_terms[ $taxonomy ] = array_map( static fn( $term ) => (int) $term->term_id, $current );
			$prior_ai                    = array_map( 'sanitize_title', (array) ( $previous_provenance['terms'][ $taxonomy ] ?? array() ) );
			$human                       = array();
			foreach ( $current as $term ) {
				if ( ! in_array( $term->slug, $prior_ai, true ) ) {
					$human[ $term->slug ] = (int) $term->term_id;
				}
			}

			$ai_ids = array();

			$assignment_identities[ $taxonomy ] = array_keys( $human );
			$projection_plan[ $taxonomy ]       = array();
			foreach ( $selected[ $taxonomy ] as $slug => $candidate ) {
				if ( isset( $human[ $slug ] ) ) {
					continue;
				}
				$projection = extrachill_network_project_term( $site_key, $post->post_type, $taxonomy, $slug, ! empty( $args['dry_run'] ) );
				if ( is_wp_error( $projection ) ) {
					return $projection;
				}
				$ai_ids[ $slug ] = (int) $projection['term_id'];

				$projection_plan[ $taxonomy ][] = array(
					'slug'         => $slug,
					'term_id'      => (int) $projection['term_id'],
					'would_create' => ! empty( $projection['created'] ),
					'source'       => $candidate['source'],
				);
			}

			$assignment_plan[ $taxonomy ]            = array_values( array_filter( array_merge( array_values( $human ), array_values( $ai_ids ) ) ) );
			$assignment_identities[ $taxonomy ]      = array_values( array_unique( array_merge( $assignment_identities[ $taxonomy ], array_keys( $ai_ids ) ) ) );
			$provenance['fingerprints'][ $taxonomy ] = $fingerprint;
			$provenance['terms'][ $taxonomy ]        = array_keys( $ai_ids );
		}
		$provenance['classified_at'] = current_time( 'mysql' );

		$effect = array(
			'type'                => 'network_post_terms_set',
			'target'              => array(
				'site'    => $site_key,
				'post_id' => $post_id,
			),
			'previous_terms'      => $previous_terms,
			'previous_provenance' => ! empty( $previous_provenance ) ? $previous_provenance : null,
			'applied_terms'       => $assignment_plan,
			'applied_provenance'  => $provenance,
		);

		if ( empty( $args['dry_run'] ) ) {
			$GLOBALS['extrachill_network_term_classifier_writing'] = true;
			try {
				foreach ( $assignment_plan as $taxonomy => $term_ids ) {
					$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				update_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META, $provenance );
			} finally {
				$GLOBALS['extrachill_network_term_classifier_writing'] = false;
			}
		}

		return array(
			'site'            => $site_key,
			'post_id'         => $post_id,
			'fingerprint'     => $fingerprint,
			'taxonomies'      => $taxonomies,
			'selected'        => array_map( 'array_keys', $selected ),
			'assignments'     => $assignment_identities,
			'term_ids'        => $assignment_plan,
			'projections'     => $projection_plan,
			'candidate_count' => count( $candidates ),
			'dry_run'         => ! empty( $args['dry_run'] ),
			'effects'         => empty( $args['dry_run'] ) ? array( $effect ) : array(),
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Restore a classifier assignment effect for task undo.
 *
 * @param array $effect Recorded effect.
 * @return array<string,mixed>
 */
function extrachill_network_restore_term_classification_effect( $effect ) {
	$site_key = sanitize_key( $effect['target']['site'] ?? '' );
	$post_id  = absint( $effect['target']['post_id'] ?? 0 );
	$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
	if ( ! $blog_id || ! $post_id || empty( $effect['previous_terms'] ) || empty( $effect['applied_terms'] ) || empty( $effect['applied_provenance'] ) ) {
		return array(
			'status' => 'failed',
			'type'   => 'network_post_terms_set',
			'reason' => 'Invalid effect target',
		);
	}

	switch_to_blog( $blog_id );
	try {
		$current_provenance = get_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META, true );
		$current_provenance = is_array( $current_provenance ) ? $current_provenance : array();
		$prior_provenance   = is_array( $effect['previous_provenance'] ?? null ) ? $effect['previous_provenance'] : array();
		$reverted           = array();
		$conflicts          = array();

		$GLOBALS['extrachill_network_term_classifier_writing'] = true;
		try {
			foreach ( $effect['previous_terms'] as $taxonomy => $previous_ids ) {
				$taxonomy            = sanitize_key( $taxonomy );
				$applied_fingerprint = (string) ( $effect['applied_provenance']['fingerprints'][ $taxonomy ] ?? '' );
				$current_fingerprint = (string) ( $current_provenance['fingerprints'][ $taxonomy ] ?? '' );
				$applied_ai          = array_values( (array) ( $effect['applied_provenance']['terms'][ $taxonomy ] ?? array() ) );
				$current_ai          = array_values( (array) ( $current_provenance['terms'][ $taxonomy ] ?? array() ) );
				sort( $applied_ai );
				sort( $current_ai );

				if ( '' === $applied_fingerprint || ! hash_equals( $applied_fingerprint, $current_fingerprint ) || $applied_ai !== $current_ai ) {
					$conflicts[] = $taxonomy;
					continue;
				}

				$current_terms = wp_get_object_terms( $post_id, $taxonomy );
				if ( is_wp_error( $current_terms ) ) {
					return array(
						'status' => 'failed',
						'type'   => 'network_post_terms_set',
						'reason' => $current_terms->get_error_message(),
					);
				}
				$current_ids  = array_map( static fn( $term ) => (int) $term->term_id, $current_terms );
				$previous_ids = array_map( 'absint', (array) $previous_ids );
				$applied_ids  = array_map( 'absint', (array) ( $effect['applied_terms'][ $taxonomy ] ?? array() ) );
				$added_ids    = array_diff( $applied_ids, $previous_ids );
				$removed_ids  = array_diff( $previous_ids, $applied_ids );
				$restored_ids = array_values( array_unique( array_merge( array_diff( $current_ids, $added_ids ), $removed_ids ) ) );

				$result = wp_set_object_terms( $post_id, $restored_ids, $taxonomy );
				if ( is_wp_error( $result ) ) {
					return array(
						'status' => 'failed',
						'type'   => 'network_post_terms_set',
						'reason' => $result->get_error_message(),
					);
				}
				$reverted[] = $taxonomy;

				if ( isset( $prior_provenance['fingerprints'][ $taxonomy ] ) ) {
					$current_provenance['fingerprints'][ $taxonomy ] = $prior_provenance['fingerprints'][ $taxonomy ];
				} else {
					unset( $current_provenance['fingerprints'][ $taxonomy ] );
				}
				if ( isset( $prior_provenance['terms'][ $taxonomy ] ) ) {
					$current_provenance['terms'][ $taxonomy ] = $prior_provenance['terms'][ $taxonomy ];
				} else {
					unset( $current_provenance['terms'][ $taxonomy ] );
				}
			}

			if ( empty( $conflicts ) ) {
				if ( isset( $prior_provenance['classified_at'] ) ) {
					$current_provenance['classified_at'] = $prior_provenance['classified_at'];
				} else {
					unset( $current_provenance['classified_at'] );
				}
			}
			if ( empty( $current_provenance['fingerprints'] ) && empty( $current_provenance['terms'] ) ) {
				delete_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META );
			} else {
				update_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_PROVENANCE_META, $current_provenance );
			}
		} finally {
			$GLOBALS['extrachill_network_term_classifier_writing'] = false;
		}
		return array(
			'status'              => $reverted ? 'reverted' : 'skipped',
			'type'                => 'network_post_terms_set',
			'post_id'             => $post_id,
			'site'                => $site_key,
			'reverted_taxonomies' => $reverted,
			'conflicts'           => $conflicts,
		);
	} finally {
		restore_current_blog();
	}
}
