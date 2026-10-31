<?php
/**
 * Genre taxonomy: closed vocabulary, alias resolution, seeding, and enforcement.
 *
 * `genre` is a closed vocabulary. The default vocabulary ships 21 canonical
 * terms; consumers replace or extend it through the
 * `extrachill_network_genre_vocabulary` filter. An alias map
 * (`extrachill_network_genre_aliases`) makes the closed list survivable:
 * free-form inputs such as "rap", "edm", or "R&B" resolve to a canonical
 * slug instead of inventing a new term.
 *
 * Seeding creates any missing vocabulary terms on the main site only (the
 * canonical source); other sites receive terms through the network term
 * projection. Seeding never deletes terms.
 *
 * Closed-vocabulary enforcement hooks the two Data Machine core hooks:
 *  - `datamachine_taxonomy_tool_parameter` — narrows the AI tool schema for
 *    `genre` to an enum array capped at three values.
 *  - `datamachine_taxonomy_assign_value` — resolves supplied values to
 *    existing term IDs through the resolver, so no assignment path can
 *    create a non-vocabulary genre term. The `data_machine_events` post
 *    type is hard-locked: events never receive genre from the import path
 *    (Extra-Chill/extrachill-events#30 §3).
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option key holding the hash of the vocabulary that was last seeded. */
const EXTRACHILL_NETWORK_GENRE_SEED_OPTION = 'extrachill_network_genre_vocabulary_seeded';

/** Taxonomy slug owned by this module. */
const EXTRACHILL_NETWORK_GENRE_TAXONOMY = 'genre';

/** Maximum genres assignable to a single object. */
const EXTRACHILL_NETWORK_GENRE_CAP = 3;

/**
 * Return the default genre vocabulary, canonical slug => display name.
 *
 * @return array<string,string>
 */
function extrachill_network_default_genre_vocabulary() {
	return array(
		'rock'              => 'Rock',
		'alternative'       => 'Alternative',
		'indie'             => 'Indie',
		'punk'              => 'Punk',
		'metal'             => 'Metal',
		'hip-hop'           => 'Hip-Hop',
		'rnb'               => 'R&B',
		'soul-funk'         => 'Soul/Funk',
		'electronic'        => 'Electronic',
		'jam'               => 'Jam',
		'jazz'              => 'Jazz',
		'blues'             => 'Blues',
		'folk-americana'    => 'Folk/Americana',
		'country'           => 'Country',
		'bluegrass'         => 'Bluegrass',
		'pop'               => 'Pop',
		'reggae'            => 'Reggae',
		'latin'             => 'Latin',
		'world'             => 'World',
		'classical'         => 'Classical',
		'singer-songwriter' => 'Singer-Songwriter',
	);
}

/**
 * Return the active genre vocabulary, normalized to slug => name.
 *
 * Filtered vocabularies are sanitized: keys become slugs, empty entries and
 * duplicate slugs are dropped, and order is preserved.
 *
 * @return array<string,string>
 */
function extrachill_network_get_genre_vocabulary() {
	$vocabulary = apply_filters( 'extrachill_network_genre_vocabulary', extrachill_network_default_genre_vocabulary() );

	$normalized = array();
	if ( is_array( $vocabulary ) ) {
		foreach ( $vocabulary as $slug => $name ) {
			$slug = sanitize_title( is_string( $slug ) ? $slug : $name );
			$name = trim( (string) $name );
			if ( '' === $slug || '' === $name || isset( $normalized[ $slug ] ) ) {
				continue;
			}
			$normalized[ $slug ] = $name;
		}
	}

	return $normalized;
}

/**
 * Return the genre alias map, alias => canonical vocabulary slug.
 *
 * Aliases are matched case-insensitively after sanitize_title. Alias
 * targets that are not in the active vocabulary are ignored at resolve
 * time, so a stale or foreign alias can never mint a term.
 *
 * @return array<string,string>
 */
function extrachill_network_get_genre_aliases() {
	$aliases = array(
		// Hip-Hop.
		'rap'               => 'hip-hop',
		'hiphop'            => 'hip-hop',
		'hip hop'           => 'hip-hop',
		// R&B.
		'r&b'               => 'rnb',
		'rnb'               => 'rnb',
		'r-b'               => 'rnb',
		'rhythm and blues'  => 'rnb',
		// Electronic.
		'edm'               => 'electronic',
		'house'             => 'electronic',
		'techno'            => 'electronic',
		'dnb'               => 'electronic',
		'drum and bass'     => 'electronic',
		'dubstep'           => 'electronic',
		'bass music'        => 'electronic',
		'downtempo'         => 'electronic',
		// Alternative.
		'alt'               => 'alternative',
		'alt rock'          => 'alternative',
		'alternative rock'  => 'alternative',
		// Indie.
		'indie rock'        => 'indie',
		'indie pop'         => 'indie',
		// Punk.
		'hardcore'          => 'punk',
		'post-hardcore'     => 'punk',
		'posthardcore'      => 'punk',
		'emo'               => 'punk',
		'pop punk'          => 'punk',
		// Rock.
		'hard rock'         => 'rock',
		'post grunge'       => 'rock',
		'grunge'            => 'rock',
		'classic rock'      => 'rock',
		'prog'              => 'rock',
		'prog rock'         => 'rock',
		'progrock'          => 'rock',
		'progressive rock'  => 'rock',
		// Soul/Funk.
		'funk'              => 'soul-funk',
		'soul'              => 'soul-funk',
		'neo soul'          => 'soul-funk',
		'motown'            => 'soul-funk',
		// Folk/Americana.
		'americana'         => 'folk-americana',
		'folk'              => 'folk-americana',
		'roots'             => 'folk-americana',
		'acoustic'          => 'folk-americana',
		// Country.
		'alt country'       => 'country',
		'outlaw country'    => 'country',
		// Jam.
		'jamband'           => 'jam',
		'jam band'          => 'jam',
		'jamgrass'          => 'jam',
		// Reggae.
		'ska'               => 'reggae',
		'dub'               => 'reggae',
		'dancehall'         => 'reggae',
		// Latin.
		'reggaeton'         => 'latin',
		'salsa'             => 'latin',
		'cumbia'            => 'latin',
		'bachata'           => 'latin',
		// World.
		'afrobeat'          => 'world',
		'afrobeats'         => 'world',
		'afropop'           => 'world',
		// Classical.
		'orchestral'        => 'classical',
		'chamber'           => 'classical',
		'opera'             => 'classical',
		// Singer-Songwriter.
		'singer songwriter' => 'singer-songwriter',
		'songwriter'        => 'singer-songwriter',
		// Metal.
		'heavy metal'       => 'metal',
		'death metal'       => 'metal',
		'black metal'       => 'metal',
		'doom'              => 'metal',
		'thrash'            => 'metal',
		// Blues.
		'blues rock'        => 'blues',
		// Jazz.
		'bebop'             => 'jazz',
		'swing'             => 'jazz',
		'big band'          => 'jazz',
		// Pop.
		'synth pop'         => 'pop',
		'synthpop'          => 'pop',
		'dance pop'         => 'pop',
		'k-pop'             => 'pop',
	);

	return apply_filters( 'extrachill_network_genre_aliases', $aliases );
}

/**
 * Resolve one free-form value to a canonical genre slug.
 *
 * Matches a vocabulary slug, a vocabulary name, or a registered alias,
 * case-insensitively after sanitize_title. Returns '' for anything the
 * active vocabulary cannot absorb.
 *
 * @param mixed $value Candidate value (term name, slug, or alias).
 * @return string Canonical vocabulary slug, or '' when unresolvable.
 */
function extrachill_network_resolve_genre( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$slug_needle = sanitize_title( (string) $value );
	$text_needle = strtolower( trim( (string) $value ) );
	if ( '' === $slug_needle && '' === $text_needle ) {
		return '';
	}

	$vocabulary = extrachill_network_get_genre_vocabulary();

	foreach ( $vocabulary as $slug => $name ) {
		if ( $slug_needle === $slug || ( '' !== $text_needle && strtolower( trim( (string) $name ) ) === $text_needle ) ) {
			return (string) $slug;
		}
	}

	$aliases = extrachill_network_get_genre_aliases();
	foreach ( $aliases as $alias => $target ) {
		if ( '' !== $slug_needle && sanitize_title( (string) $alias ) === $slug_needle && isset( $vocabulary[ $target ] ) ) {
			return (string) $target;
		}
	}

	return '';
}

/**
 * Resolve a free-form multi-genre string to canonical slugs.
 *
 * Splits on `,` `/` `|` `+`, whitespace-delimited `&`, and the word "and",
 * then resolves each piece through extrachill_network_resolve_genre().
 * Duplicate slugs are dropped preserving first-seen order; the result is
 * capped at $cap slugs.
 *
 * @param mixed $value Raw value, e.g. "Hip Hop, RNB" or "Electronic/Downtempo".
 * @param int   $cap   Maximum slugs returned.
 * @return string[] Canonical vocabulary slugs in first-seen order.
 */
function extrachill_network_resolve_genres( $value, $cap = EXTRACHILL_NETWORK_GENRE_CAP ) {
	$pieces = is_array( $value ) ? $value : preg_split( '/[,\/|+]|\s+&\s+|\s+and\s+/i', (string) $value );
	if ( ! is_array( $pieces ) ) {
		return array();
	}

	$cap      = max( 1, (int) $cap );
	$resolved = array();
	foreach ( $pieces as $piece ) {
		$slug = extrachill_network_resolve_genre( $piece );
		if ( '' !== $slug && ! in_array( $slug, $resolved, true ) ) {
			$resolved[] = $slug;
		}
		if ( count( $resolved ) >= $cap ) {
			break;
		}
	}

	return $resolved;
}

/**
 * Seed any missing vocabulary terms on the main site.
 *
 * Runs on init after taxonomy registration. Main site only: it is the
 * canonical source; other sites receive genre terms through the network
 * term projection when assigned. Guarded by a hashed option so the insert
 * pass only runs when the resolved vocabulary actually changed. Existing
 * terms are never modified or deleted.
 *
 * @return void
 */
function extrachill_network_maybe_seed_genre_vocabulary() {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : ( defined( 'EC_BLOG_ID_MAIN' ) ? (int) EC_BLOG_ID_MAIN : 0 );
	if ( ! $main_blog_id || get_current_blog_id() !== $main_blog_id ) {
		return;
	}

	if ( ! taxonomy_exists( EXTRACHILL_NETWORK_GENRE_TAXONOMY ) ) {
		return;
	}

	$vocabulary = extrachill_network_get_genre_vocabulary();
	if ( empty( $vocabulary ) ) {
		return;
	}

	$hash = md5( (string) wp_json_encode( $vocabulary ) );

	static $seeded = array();
	if ( isset( $seeded[ $hash ] ) || get_option( EXTRACHILL_NETWORK_GENRE_SEED_OPTION ) === $hash ) {
		$seeded[ $hash ] = true;
		return;
	}

	$failed = false;
	foreach ( $vocabulary as $slug => $name ) {
		$term = get_term_by( 'slug', $slug, EXTRACHILL_NETWORK_GENRE_TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			$term = get_term_by( 'name', $name, EXTRACHILL_NETWORK_GENRE_TAXONOMY );
		}
		if ( $term instanceof WP_Term ) {
			continue;
		}

		$created = wp_insert_term( $name, EXTRACHILL_NETWORK_GENRE_TAXONOMY, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) && ! $created->get_error_data( 'term_exists' ) ) {
			$failed = true;
		}
	}

	if ( ! $failed ) {
		update_option( EXTRACHILL_NETWORK_GENRE_SEED_OPTION, $hash, false );
		$seeded[ $hash ] = true;
	}
}
add_action( 'init', 'extrachill_network_maybe_seed_genre_vocabulary', 10 );

/**
 * Narrow the AI tool schema for `genre` to the closed vocabulary.
 *
 * `genre` is flat, so Data Machine's generic default exposes an array of
 * free-form strings whose terms are created on demand. The parameter
 * becomes an array whose items are constrained to the vocabulary names,
 * capped at three values.
 *
 * @param mixed $param_def      Generic JSON Schema fragment.
 * @param mixed $taxonomy       Taxonomy object being exposed.
 * @param mixed $handler_config Handler configuration (part of the hook signature).
 * @param mixed $post_type      Post type in scope (part of the hook signature).
 * @return mixed
 */
function extrachill_network_filter_genre_tool_parameter( $param_def, $taxonomy, $handler_config = array(), $post_type = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
	if ( ! is_object( $taxonomy ) || EXTRACHILL_NETWORK_GENRE_TAXONOMY !== ( $taxonomy->name ?? '' ) ) {
		return $param_def;
	}

	$names = array_values( extrachill_network_get_genre_vocabulary() );
	if ( empty( $names ) ) {
		return $param_def;
	}

	if ( ! is_array( $param_def ) ) {
		$param_def = array();
	}

	$param_def['type']        = 'array';
	$param_def['items']       = array(
		'type' => 'string',
		'enum' => $names,
	);
	$param_def['maxItems']    = EXTRACHILL_NETWORK_GENRE_CAP;
	$param_def['description'] = sprintf(
		'Assign up to %1$d genres for this post. Choose each value from the allowed list only: %2$s. This is a closed vocabulary — never invent a value and never return anything outside the list; leave the parameter empty when nothing fits.',
		EXTRACHILL_NETWORK_GENRE_CAP,
		implode( ', ', $names )
	);

	return $param_def;
}
add_filter( 'datamachine_taxonomy_tool_parameter', 'extrachill_network_filter_genre_tool_parameter', 10, 4 );

/**
 * Resolve an assigned `genre` value to existing term IDs before term creation.
 *
 * Runs ahead of Data Machine's generic find-or-create pass, so a value
 * outside the vocabulary can never reach wp_insert_term(). Returns term
 * IDs resolved through the alias-aware resolver, or '' to skip assignment
 * entirely.
 *
 * The `data_machine_events` post type is hard-locked: genre is an artist
 * fact and events derive it from their performers, so the import path
 * must never write it (Extra-Chill/extrachill-events#30 §3). The assign
 * hook signature carries no post type, so it is derived from the post ID.
 *
 * @param mixed  $value    Supplied taxonomy value (string or array of strings).
 * @param string $taxonomy Taxonomy slug being assigned.
 * @param int    $post_id  Post receiving the assignment.
 * @return mixed Term ID strings, or '' to skip assignment.
 */
function extrachill_network_filter_genre_assign_value( $value, $taxonomy, $post_id ) {
	if ( EXTRACHILL_NETWORK_GENRE_TAXONOMY !== $taxonomy ) {
		return $value;
	}

	$post_type = $post_id ? get_post_type( (int) $post_id ) : '';
	if ( 'data_machine_events' === $post_type ) {
		return '';
	}

	$values = is_array( $value ) ? $value : array( $value );

	$term_ids = array();
	foreach ( $values as $single ) {
		if ( is_array( $single ) ) {
			$single = reset( $single );
		}
		$slug = extrachill_network_resolve_genre( $single );
		if ( '' === $slug ) {
			continue;
		}
		$term = taxonomy_exists( EXTRACHILL_NETWORK_GENRE_TAXONOMY ) ? get_term_by( 'slug', $slug, EXTRACHILL_NETWORK_GENRE_TAXONOMY ) : false;
		if ( $term instanceof WP_Term ) {
			$term_ids[] = (string) (int) $term->term_id;
		}
		if ( count( $term_ids ) >= EXTRACHILL_NETWORK_GENRE_CAP ) {
			break;
		}
	}

	return empty( $term_ids ) ? '' : array_values( array_unique( $term_ids ) );
}
add_filter( 'datamachine_taxonomy_assign_value', 'extrachill_network_filter_genre_assign_value', 10, 3 );
