<?php
/**
 * Genre vocabulary, alias resolution, seeding, and enforcement tests (no WordPress or MySQL required).
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['genre_test'] = array(
	'filters'        => array(),
	'actions'        => array(),
	'blog_id'        => 1,
	'options'        => array(),
	'terms'          => array(),
	'terms_by_id'    => array(),
	'next_term_id'   => 201,
	'inserts'        => array(),
	'post_types'     => array(),
	'taxonomy_exists' => array(),
);

function genre_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function genre_test_clear_filters() {
	$GLOBALS['genre_test']['filters'] = array();
}

// WordPress function stubs.
function __( $text, $domain = 'default' ) {
	return $text;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['genre_test']['actions'][ $hook_name ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['genre_test']['filters'][ $hook_name ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function apply_filters( $hook_name, $value, ...$args ) {
	foreach ( $GLOBALS['genre_test']['filters'][ $hook_name ] ?? array() as $registered ) {
		$value = call_user_func_array( $registered['callback'], array_slice( array_merge( array( $value ), $args ), 0, $registered['accepted_args'] ) );
	}
	return $value;
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/<[^>]*>/', '', $title );
	$title = preg_replace( '/[^a-z0-9\s\-_]+/', '', $title );
	$title = preg_replace( '/[\s_]+/', '-', $title );
	$title = preg_replace( '/-+/', '-', $title );
	return trim( $title, '-' );
}

function get_current_blog_id() {
	return $GLOBALS['genre_test']['blog_id'];
}

function ec_get_blog_id( $key ) {
	return 'main' === $key ? 1 : null;
}

function get_option( $key, $default = false ) {
	return $GLOBALS['genre_test']['options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['genre_test']['options'][ $key ] = $value;
	return true;
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function taxonomy_exists( $taxonomy ) {
	return ! empty( $GLOBALS['genre_test']['taxonomy_exists'][ $taxonomy ] );
}

class WP_Term {
	public $term_id = 0;
	public $name    = '';
	public $slug    = '';
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data( string $code = '' ) {
		return is_array( $this->data ) ? ( $this->data[ $code ] ?? $this->data ) : $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_term_by( $field, $value, $taxonomy ) {
	if ( 'genre' !== $taxonomy ) {
		return false;
	}
	if ( 'slug' === $field ) {
		$term = $GLOBALS['genre_test']['terms'][ $value ] ?? null;
	} elseif ( 'name' === $field ) {
		$term = null;
		foreach ( $GLOBALS['genre_test']['terms'] as $candidate ) {
			if ( $candidate->name === $value ) {
				$term = $candidate;
				break;
			}
		}
	} else {
		return false;
	}
	return $term instanceof WP_Term ? $term : false;
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
	$slug = $args['slug'] ?? sanitize_title( $term );
	if ( isset( $GLOBALS['genre_test']['terms'][ $slug ] ) ) {
		return new WP_Error( 'term_exists', 'A term with the name provided already exists.', array( 'term_id' => $GLOBALS['genre_test']['terms'][ $slug ]->term_id ) );
	}
	$term_object       = new WP_Term();
	$term_object->slug = $slug;
	$term_object->name = (string) $term;
	$term_object->term_id = $GLOBALS['genre_test']['next_term_id']++;
	$GLOBALS['genre_test']['terms'][ $slug ]          = $term_object;
	$GLOBALS['genre_test']['terms_by_id'][ $term_object->term_id ] = $term_object;
	$GLOBALS['genre_test']['inserts'][]               = array( $taxonomy, $slug, $term_object->term_id );
	return array(
		'term_id' => $term_object->term_id,
		'term_taxonomy_id' => $term_object->term_id,
	);
}

function get_post_type( $post_id ) {
	return $GLOBALS['genre_test']['post_types'][ $post_id ] ?? false;
}

require_once dirname( __DIR__ ) . '/inc/taxonomy/genre.php';

// Hook contract.
genre_test_assert(
	isset( $GLOBALS['genre_test']['actions']['init'] ) && 1 === count( $GLOBALS['genre_test']['actions']['init'] ) && 'extrachill_network_maybe_seed_genre_vocabulary' === $GLOBALS['genre_test']['actions']['init'][0]['callback'] && 10 === $GLOBALS['genre_test']['actions']['init'][0]['priority'],
	'seeding is hooked on init priority 10'
);
genre_test_assert(
	isset( $GLOBALS['genre_test']['filters']['datamachine_taxonomy_tool_parameter'] ) && 4 === $GLOBALS['genre_test']['filters']['datamachine_taxonomy_tool_parameter'][0]['accepted_args'],
	'tool parameter filter is registered with four accepted args'
);
genre_test_assert(
	isset( $GLOBALS['genre_test']['filters']['datamachine_taxonomy_assign_value'] ) && 3 === $GLOBALS['genre_test']['filters']['datamachine_taxonomy_assign_value'][0]['accepted_args'],
	'assign value filter is registered with three accepted args'
);

// Default vocabulary shape: 21 canonical slugs in order.
$expected_vocabulary = array(
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
genre_test_assert(
	extrachill_network_get_genre_vocabulary() === $expected_vocabulary,
	'default vocabulary is the 21 canonical terms in order'
);
genre_test_assert(
	array_keys( extrachill_network_default_genre_vocabulary() ) === array_keys( $expected_vocabulary ),
	'default vocabulary slugs are declared in canonical order'
);

// Single resolver: slug, name, and alias matches.
genre_test_assert( 'rock' === extrachill_network_resolve_genre( 'Rock' ), 'resolve_genre matches a vocabulary name case-insensitively' );
genre_test_assert( 'hip-hop' === extrachill_network_resolve_genre( 'hip-hop' ), 'resolve_genre matches a vocabulary slug' );
genre_test_assert( 'hip-hop' === extrachill_network_resolve_genre( 'Hip Hop' ), 'resolve_genre matches a spaced slug form' );
genre_test_assert( 'rnb' === extrachill_network_resolve_genre( 'R&B' ), 'resolve_genre resolves the R&B alias' );
genre_test_assert( 'singer-songwriter' === extrachill_network_resolve_genre( 'Singer Songwriter' ), 'resolve_genre resolves the singer songwriter alias' );
genre_test_assert( 'electronic' === extrachill_network_resolve_genre( 'EDM' ), 'resolve_genre resolves the EDM alias' );
genre_test_assert( '' === extrachill_network_resolve_genre( 'idk' ), 'resolve_genre returns empty for unknown input' );
genre_test_assert( '' === extrachill_network_resolve_genre( '' ), 'resolve_genre returns empty for empty input' );
genre_test_assert( '' === extrachill_network_resolve_genre( array( 'rock' ) ), 'resolve_genre returns empty for non-scalar input' );

// Multi resolver: splitting, dedupe, order, cap.
genre_test_assert(
	array( 'hip-hop' ) === extrachill_network_resolve_genres( 'Rap/Hip-Hop' ),
	'"Rap/Hip-Hop" resolves to hip-hop deduped'
);
genre_test_assert(
	array( 'hip-hop', 'rnb' ) === extrachill_network_resolve_genres( 'Hip Hop, RNB' ),
	'"Hip Hop, RNB" resolves to hip-hop and rnb in order'
);
genre_test_assert(
	array( 'alternative', 'rock', 'punk' ) === extrachill_network_resolve_genres( 'Alternative, progrock, emo' ),
	'"Alternative, progrock, emo" resolves to alternative, rock, punk'
);
genre_test_assert(
	array() === extrachill_network_resolve_genres( 'idk' ),
	'"idk" resolves to an empty list'
);
genre_test_assert(
	array( 'electronic' ) === extrachill_network_resolve_genres( 'Electronic/Downtempo' ),
	'"Electronic/Downtempo" resolves to electronic deduped'
);
genre_test_assert(
	array( 'rock', 'punk', 'jazz' ) === extrachill_network_resolve_genres( 'rock, punk, jazz, blues, pop' ),
	'multi resolver caps results at three by default'
);
genre_test_assert(
	array( 'rock', 'punk', 'jazz', 'blues' ) === extrachill_network_resolve_genres( 'rock, punk, jazz, blues, pop', 4 ),
	'multi resolver honors an explicit cap'
);
genre_test_assert(
	array( 'rnb' ) === extrachill_network_resolve_genres( 'R&B' ),
	'ampersand inside a compound name is not a split boundary'
);
genre_test_assert(
	array( 'hip-hop', 'rnb' ) === extrachill_network_resolve_genres( 'Hip-Hop & R&B' ),
	'space-delimited ampersand is a split boundary'
);
genre_test_assert(
	array( 'rock', 'jazz' ) === extrachill_network_resolve_genres( 'rock and jazz' ),
	'the word and is a split boundary'
);

// Vocabulary filter override replaces the list entirely.
add_filter(
	'extrachill_network_genre_vocabulary',
	static function () {
		return array( 'weird' => 'Weird' );
	}
);
genre_test_assert(
	array( 'weird' => 'Weird' ) === extrachill_network_get_genre_vocabulary(),
	'vocabulary filter override replaces the default list'
);
genre_test_assert(
	'weird' === extrachill_network_resolve_genre( 'Weird' ) && '' === extrachill_network_resolve_genre( 'rock' ),
	'resolver honors the overridden vocabulary'
);
genre_test_clear_filters();

// Alias filter: targets outside the vocabulary are dropped.
add_filter(
	'extrachill_network_genre_aliases',
	static function ( $aliases ) {
		$aliases['zzz']  = 'jazz';
		$aliases['zzz2'] = 'not-a-genre';
		return $aliases;
	}
);
genre_test_assert(
	'jazz' === extrachill_network_resolve_genre( 'zzz' ),
	'alias filter additions resolve to vocabulary targets'
);
genre_test_assert(
	'' === extrachill_network_resolve_genre( 'zzz2' ),
	'aliases pointing outside the vocabulary are dropped'
);
genre_test_clear_filters();

// Tool parameter narrowing for the genre taxonomy.
$genre_taxonomy      = new stdClass();
$genre_taxonomy->name = 'genre';
$param              = extrachill_network_filter_genre_tool_parameter(
	array(
		'type'        => 'array',
		'items'       => array( 'type' => 'string' ),
		'description' => 'Assign genre for this post.',
	),
	$genre_taxonomy,
	array(),
	'post'
);
genre_test_assert(
	'array' === $param['type'] && array( 'type' => 'string', 'enum' => array_values( $expected_vocabulary ) ) === $param['items'],
	'tool parameter narrows items to the vocabulary enum'
);
genre_test_assert(
	3 === $param['maxItems'],
	'tool parameter caps the array at three items'
);
genre_test_assert(
	str_contains( $param['description'], 'closed vocabulary' ),
	'tool parameter description declares the closed vocabulary'
);
$other_taxonomy       = new stdClass();
$other_taxonomy->name = 'event_type';
$untouched            = array( 'type' => 'array' );
genre_test_assert(
	$untouched === extrachill_network_filter_genre_tool_parameter( $untouched, $other_taxonomy, array(), 'post' ),
	'tool parameter filter passes non-genre taxonomies through untouched'
);
genre_test_assert(
	is_array( extrachill_network_filter_genre_tool_parameter( 'junk', $genre_taxonomy, array(), 'post' ) ),
	'tool parameter filter coerces a non-array param definition'
);

// Assign value: events lockout, resolution to term IDs, passthrough.
$GLOBALS['genre_test']['taxonomy_exists'] = array( 'genre' => true );
$GLOBALS['genre_test']['post_types']      = array(
	55  => 'data_machine_events',
	101 => 'post',
);
$GLOBALS['genre_test']['terms']       = array();
$GLOBALS['genre_test']['terms_by_id'] = array();
$GLOBALS['genre_test']['next_term_id'] = 201;
wp_insert_term( 'Rock', 'genre', array( 'slug' => 'rock' ) );
wp_insert_term( 'Hip-Hop', 'genre', array( 'slug' => 'hip-hop' ) );
wp_insert_term( 'R&B', 'genre', array( 'slug' => 'rnb' ) );
wp_insert_term( 'Punk', 'genre', array( 'slug' => 'punk' ) );
wp_insert_term( 'Jazz', 'genre', array( 'slug' => 'jazz' ) );
wp_insert_term( 'Blues', 'genre', array( 'slug' => 'blues' ) );

genre_test_assert(
	'' === extrachill_network_filter_genre_assign_value( array( 'Rock', 'rap' ), 'genre', 55 ),
	'genre assignment is refused unconditionally for data_machine_events'
);
genre_test_assert(
	array( '202', '203' ) === extrachill_network_filter_genre_assign_value( array( 'rap', 'RNB' ), 'genre', 101 ),
	'genre assignment resolves aliases to existing term IDs'
);
genre_test_assert(
	array( '201' ) === extrachill_network_filter_genre_assign_value( array( 'idk', 'Rock' ), 'genre', 101 ),
	'genre assignment drops unresolvable values and keeps resolved ones'
);
genre_test_assert(
	'' === extrachill_network_filter_genre_assign_value( array( 'idk' ), 'genre', 101 ),
	'genre assignment skips when nothing resolves'
);
genre_test_assert(
	array( '201', '204', '205' ) === extrachill_network_filter_genre_assign_value( array( 'Rock', 'Punk', 'Jazz', 'Blues' ), 'genre', 101 ),
	'genre assignment caps resolved term IDs at three'
);
genre_test_assert(
	'Jazz Fest' === extrachill_network_filter_genre_assign_value( 'Jazz Fest', 'festival', 101 ),
	'assign value filter passes non-genre taxonomies through untouched'
);

// Seeding: main site only, hashed-option guard, never deletes.
$GLOBALS['genre_test']['taxonomy_exists'] = array( 'genre' => true );
$GLOBALS['genre_test']['blog_id']         = 1;
$GLOBALS['genre_test']['terms']           = array();
$GLOBALS['genre_test']['terms_by_id']     = array();
$GLOBALS['genre_test']['next_term_id']    = 201;
$GLOBALS['genre_test']['inserts']         = array();
$GLOBALS['genre_test']['options']         = array();

extrachill_network_maybe_seed_genre_vocabulary();
genre_test_assert(
	21 === count( $GLOBALS['genre_test']['inserts'] ),
	'seeding creates all 21 vocabulary terms on the main site'
);
$seed_hash = md5( (string) wp_json_encode( $expected_vocabulary ) );
genre_test_assert(
	$seed_hash === $GLOBALS['genre_test']['options'][ EXTRACHILL_NETWORK_GENRE_SEED_OPTION ],
	'seeding stamps the hashed vocabulary option'
);

$before = count( $GLOBALS['genre_test']['inserts'] );
extrachill_network_maybe_seed_genre_vocabulary();
genre_test_assert(
	$before === count( $GLOBALS['genre_test']['inserts'] ),
	'seeding is idempotent while the vocabulary hash is unchanged'
);

// A vocabulary change creates only the missing term and never deletes extras.
add_filter(
	'extrachill_network_genre_vocabulary',
	static function ( $vocabulary ) {
		$vocabulary['world-fusion'] = 'World Fusion';
		return $vocabulary;
	}
);
extrachill_network_maybe_seed_genre_vocabulary();
genre_test_clear_filters();
genre_test_assert(
	1 === count( $GLOBALS['genre_test']['inserts'] ) - $before && 'world-fusion' === $GLOBALS['genre_test']['inserts'][ count( $GLOBALS['genre_test']['inserts'] ) - 1 ][1],
	'a vocabulary change seeds only the missing term'
);
$actual_term_slugs   = array_keys( $GLOBALS['genre_test']['terms'] );
$expected_term_slugs = array_merge( array_keys( $expected_vocabulary ), array( 'world-fusion' ) );
sort( $actual_term_slugs );
sort( $expected_term_slugs );
genre_test_assert(
	$expected_term_slugs === $actual_term_slugs,
	'seeding never creates terms outside the vocabulary and never deletes existing terms'
);

// Seeding does not run away from the main site.
$GLOBALS['genre_test']['blog_id']  = 7;
$GLOBALS['genre_test']['options']  = array();
$GLOBALS['genre_test']['inserts']  = array();
extrachill_network_maybe_seed_genre_vocabulary();
genre_test_assert(
	array() === $GLOBALS['genre_test']['inserts'] && ! isset( $GLOBALS['genre_test']['options'][ EXTRACHILL_NETWORK_GENRE_SEED_OPTION ] ),
	'seeding never runs on a non-main site'
);

// Seeding requires the taxonomy to exist.
$GLOBALS['genre_test']['blog_id']         = 1;
$GLOBALS['genre_test']['taxonomy_exists'] = array();
extrachill_network_maybe_seed_genre_vocabulary();
genre_test_assert(
	array() === $GLOBALS['genre_test']['inserts'],
	'seeding skips when the genre taxonomy is not registered'
);

echo "GenreVocabularyTest: all assertions passed\n";
