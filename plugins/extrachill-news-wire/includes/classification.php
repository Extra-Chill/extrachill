<?php
/**
 * Festival Wire classification policy and audit tooling.
 *
 * @package ExtraChillNewsWire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a classification for comparison.
 *
 * @param string $value Raw classification.
 * @return string
 */
function ec_news_wire_normalize_classification( $value ) {
	$resolver = '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility';
	if ( class_exists( $resolver ) && is_callable( array( $resolver, 'normalize_name_for_matching' ) ) ) {
		return $resolver::normalize_name_for_matching( (string) $value );
	}

	$value = remove_accents( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$value = strtolower( $value );
	$value = preg_replace( '/\s*&\s*/', ' and ', $value );
	$value = preg_replace( '/^(the|a|an)\s+/i', '', $value );
	$value = preg_replace( '/[^a-z0-9\s]/', '', $value );

	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

/**
 * Build a compact identity key that also catches missing-space variants.
 *
 * @param string $value Raw classification.
 * @return string
 */
function ec_news_wire_classification_key( $value ) {
	return str_replace( ' ', '', ec_news_wire_normalize_classification( $value ) );
}

/**
 * Identify values that are instructions or labels rather than classifications.
 *
 * @param string $value    Candidate value.
 * @param string $taxonomy Taxonomy slug.
 * @return string Empty when valid, otherwise a rejection reason.
 */
function ec_news_wire_invalid_classification_reason( $value, $taxonomy ) {
	$value      = trim( sanitize_text_field( (string) $value ) );
	$normalized = ec_news_wire_normalize_classification( preg_replace( '/[_-]+/', ' ', $value ) );

	if ( '' === $normalized ) {
		return 'empty value';
	}

	if ( preg_match( '#^https?://#i', $value ) ) {
		return 'URL is not a classification';
	}

	if ( preg_match( '/^(?:ai\s+decides?|skip|none|null|unknown|n\s*a|not\s+applicable)$/', $normalized ) ) {
		return 'instruction placeholder';
	}

	if ( preg_match( '/^(?:taxonomy\s+)?(?:festival|festivals|location|locations)$/', $normalized ) ) {
		return 'taxonomy label';
	}

	if ( preg_match( '/^(?:extra\s+chill\s+)?(?:news\s+)?wire$/', $normalized ) ) {
		return 'publisher label';
	}

	if ( 'festival' === $taxonomy && preg_match( '/^(?:local|live|music)\s+(?:concerts?|events?|news)$/', $normalized ) ) {
		return 'generic content label';
	}

	return '';
}

/**
 * Resolve a candidate to the strongest existing canonical term.
 *
 * @param string $value           Candidate value.
 * @param string $taxonomy        Taxonomy slug.
 * @param int    $current_term_id Existing term being audited, if any.
 * @return object|null
 */
function ec_news_wire_find_canonical_term( $value, $taxonomy, $current_term_id = 0 ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return null;
	}

	// Use Data Machine's generic exact/slug/fuzzy resolver on the live publish
	// path, then rank all compact-name matches so an exact but weak duplicate
	// cannot win over an established canonical term.
	$resolver = '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility';
	$matches  = array();
	if ( 0 === (int) $current_term_id && class_exists( $resolver ) && is_callable( array( $resolver, 'resolve' ) ) ) {
		$resolved = $resolver::resolve( (string) $value, $taxonomy, false, array(), true );
		if ( ! empty( $resolved['success'] ) ) {
			$term = get_term( (int) $resolved['term_id'], $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$matches[ (int) $term->term_id ] = $term;
			}
		}
	}

	$key = ec_news_wire_classification_key( $value );
	foreach ( $terms as $term ) {
		if ( $key === ec_news_wire_classification_key( $term->name ) ) {
			$matches[ (int) $term->term_id ] = $term;
		}
	}

	if ( empty( $matches ) ) {
		return null;
	}

	$matches = array_values( $matches );
	usort(
		$matches,
		function ( $left, $right ) {
			$count_compare = (int) $right->count <=> (int) $left->count;
			return 0 !== $count_compare ? $count_compare : (int) $left->term_id <=> (int) $right->term_id;
		}
	);

	return (int) $matches[0]->term_id === (int) $current_term_id ? null : $matches[0];
}

/**
 * Find a possible concise city term without assuming it is geographically equivalent.
 *
 * @param string $value Candidate location.
 * @return object|null
 */
function ec_news_wire_find_location_review_candidate( $value ) {
	if ( false === strpos( $value, ',' ) ) {
		return null;
	}

	$city  = trim( strtok( $value, ',' ) );
	$terms = get_terms( array( 'taxonomy' => 'location', 'hide_empty' => false, 'number' => 0 ) );
	if ( is_wp_error( $terms ) ) {
		return null;
	}

	foreach ( $terms as $term ) {
		if ( 0 === strcasecmp( $city, $term->name ) && 0 !== strcasecmp( $value, $term->name ) ) {
			return $term;
		}
	}

	return null;
}

/**
 * Validate and canonicalize one Wire classification.
 *
 * @param string $value           Candidate value.
 * @param string $taxonomy        Taxonomy slug.
 * @param int    $current_term_id Existing term being audited, if any.
 * @return array{status:string,value:mixed,reason:string,term_id:int}
 */
function ec_news_wire_classify_value( $value, $taxonomy, $current_term_id = 0 ) {
	$value  = trim( sanitize_text_field( (string) $value ) );
	$reason = ec_news_wire_invalid_classification_reason( $value, $taxonomy );
	if ( '' !== $reason ) {
		return array( 'status' => 'invalid', 'value' => '', 'reason' => $reason, 'term_id' => 0 );
	}

	$review_term = 'location' === $taxonomy ? ec_news_wire_find_location_review_candidate( $value ) : null;
	if ( $review_term ) {
		return array(
			'status'  => 'review',
			'value'   => '',
			'reason'  => sprintf( 'possible duplicate of %s; geographic equivalence requires review', $review_term->name ),
			'term_id' => (int) $review_term->term_id,
		);
	}

	$canonical = ec_news_wire_find_canonical_term( $value, $taxonomy, $current_term_id );
	if ( $canonical ) {
		return array(
			'status'  => 'canonical',
			'value'   => (string) $canonical->term_id,
			'reason'  => sprintf( 'resolves to %s', $canonical->name ),
			'term_id' => (int) $canonical->term_id,
		);
	}

	return array( 'status' => 'valid', 'value' => $value, 'reason' => '', 'term_id' => 0 );
}

/**
 * Apply Wire policy immediately before Data Machine creates taxonomy terms.
 *
 * @param mixed  $taxonomy_value AI-supplied term value.
 * @param string $taxonomy_name  Taxonomy slug.
 * @param int    $post_id        Post receiving terms.
 * @return mixed
 */
function ec_news_wire_validate_classification( $taxonomy_value, $taxonomy_name, $post_id ) {
	if ( ! in_array( $taxonomy_name, array( 'festival', 'location' ), true ) || 'festival_wire' !== get_post_type( $post_id ) ) {
		return $taxonomy_value;
	}

	$was_array = is_array( $taxonomy_value );
	$values    = $was_array ? $taxonomy_value : array( $taxonomy_value );
	$accepted  = array();

	foreach ( $values as $value ) {
		$result = ec_news_wire_classify_value( $value, $taxonomy_name );
		if ( in_array( $result['status'], array( 'invalid', 'review' ), true ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Festival Wire rejected or held taxonomy classification',
				array( 'post_id' => $post_id, 'taxonomy' => $taxonomy_name, 'value' => $value, 'reason' => $result['reason'] )
			);
			continue;
		}
		$accepted[] = $result['value'];
	}

	if ( $was_array ) {
		return array_values( array_unique( $accepted ) );
	}

	return $accepted[0] ?? '';
}
add_filter( 'datamachine_taxonomy_assign_value', 'ec_news_wire_validate_classification', 10, 3 );

/**
 * Audit or explicitly repair malformed Festival Wire classifications.
 *
 * @param bool $apply Whether to apply reported changes.
 * @return array<int,array<string,mixed>>
 */
function ec_news_wire_audit_classifications( $apply = false ) {
	$findings = array();

	foreach ( array( 'festival', 'location' ) as $taxonomy ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0 ) );
		if ( is_wp_error( $terms ) ) {
			$findings[] = array(
				'taxonomy' => $taxonomy,
				'term_id'  => 0,
				'name'     => '',
				'action'   => 'error',
				'target'   => 0,
				'posts'    => 0,
				'reason'   => $terms->get_error_message(),
				'result'   => 'partial (taxonomy lookup failed)',
			);
			continue;
		}

		foreach ( $terms as $term ) {
			$result = ec_news_wire_classify_value( $term->name, $taxonomy, (int) $term->term_id );
			if ( 'valid' === $result['status'] ) {
				continue;
			}

			$object_ids   = get_objects_in_term( (int) $term->term_id, $taxonomy );
			$object_error = is_wp_error( $object_ids );
			$object_ids   = $object_error ? array() : array_filter(
				array_map( 'intval', $object_ids ),
				function ( $post_id ) {
					return 'festival_wire' === get_post_type( $post_id );
				}
			);

			$findings[] = array(
				'taxonomy' => $taxonomy,
				'term_id'  => (int) $term->term_id,
				'name'     => $term->name,
				'action'   => 'canonical' === $result['status'] ? 'merge' : ( 'review' === $result['status'] ? 'review' : 'detach' ),
				'target'   => $result['term_id'],
				'posts'    => count( $object_ids ),
				'reason'   => $result['reason'],
				'result'   => $apply && 'review' === $result['status'] ? 'review required' : ( $object_error ? 'partial (relationship lookup failed)' : 'planned' ),
			);
			$finding_index = count( $findings ) - 1;

			if ( ! $apply || 'review' === $result['status'] || $object_error ) {
				continue;
			}

			if ( empty( $object_ids ) ) {
				$findings[ $finding_index ]['result'] = 'no Wire relationships';
				continue;
			}

			$errors = 0;
			foreach ( $object_ids as $post_id ) {
				if ( $result['term_id'] ) {
					$assigned = wp_set_object_terms( $post_id, array( $result['term_id'] ), $taxonomy, true );
					if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
						++$errors;
						continue;
					}
				}
				$removed = wp_remove_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
				if ( is_wp_error( $removed ) || false === $removed ) {
					++$errors;
				}
			}

			$remaining = get_term( (int) $term->term_id, $taxonomy );
			if ( ! empty( $object_ids ) && $remaining && ! is_wp_error( $remaining ) && 0 === (int) $remaining->count ) {
				$deleted = wp_delete_term( (int) $term->term_id, $taxonomy );
				if ( is_wp_error( $deleted ) || false === $deleted ) {
					++$errors;
				}
			}

			$findings[ $finding_index ]['result'] = $errors ? sprintf( 'partial (%d error(s))', $errors ) : 'repaired';
		}
	}

	return $findings;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/** Festival Wire classification maintenance. */
	class EC_News_Wire_Classification_Command {
		/**
		 * Audit classifications. Mutations require --apply.
		 *
		 * ## OPTIONS
		 *
		 * [--apply]
		 * : Reassign or detach reported Festival Wire terms. Default is dry-run.
		 */
		public function audit( $args, $assoc_args ) {
			$apply    = isset( $assoc_args['apply'] );
			$findings = ec_news_wire_audit_classifications( $apply );

			if ( empty( $findings ) ) {
				WP_CLI::success( 'No malformed Festival Wire classifications found.' );
				return;
			}

			WP_CLI\Utils\format_items( 'table', $findings, array( 'taxonomy', 'term_id', 'name', 'action', 'target', 'posts', 'reason', 'result' ) );
			$partial = array_filter( $findings, function ( $finding ) { return 0 === strpos( $finding['result'], 'partial' ); } );
			if ( ! empty( $partial ) ) {
				WP_CLI::error( sprintf( '%s with partial failures in %d of %d finding(s).', $apply ? 'Completed' : 'Audit incomplete', count( $partial ), count( $findings ) ) );
			}
			WP_CLI::success( sprintf( '%s %d classification finding(s).', $apply ? 'Processed' : 'Dry-run found', count( $findings ) ) );
		}
	}

	WP_CLI::add_command( 'extrachill-news-wire classifications', 'EC_News_Wire_Classification_Command' );
}
