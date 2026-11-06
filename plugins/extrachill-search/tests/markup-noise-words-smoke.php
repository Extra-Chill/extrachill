<?php
/**
 * Smoke test: block-markup noise words are dropped from FULLTEXT queries.
 *
 * Run with: php tests/markup-noise-words-smoke.php
 *
 * @package ExtraChillSearch
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['mnw_cache']     = array();
$GLOBALS['mnw_transient'] = array();
$GLOBALS['mnw_queries']   = 0;

function get_current_blog_id() {
	return 7;
}
function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['mnw_cache'][ $group . $key ] ?? false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	$GLOBALS['mnw_cache'][ $group . $key ] = $value;
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['mnw_transient'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['mnw_transient'][ $key ] = $value;
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}

/* Fake wpdb: returns event-style block posts for the sample query. */
class MNW_WPDB {
	public $posts = 'c8c_7_posts';
	public function get_col( $sql ) {
		++$GLOBALS['mnw_queries'];
		if ( false === strpos( $sql, 'LIMIT 50' ) ) {
			throw new RuntimeException( 'Noise sampling must stay bounded.' );
		}
		$rows = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$rows[] = '<!-- wp:data-machine-events/event-details {"startDate":"2026-11-' . ( 10 + $i % 20 ) . '","venue":"Venue ' . $i . '","performerType":"PerformingGroup"} -->'
				. '<p>Band number ' . $i . ' plays Rage Against the Machine covers.</p>'
				. '<!-- /wp:data-machine-events/event-details -->';
		}
		return $rows;
	}
	public function _real_escape( $value ) {
		return addslashes( $value );
	}
}
$GLOBALS['wpdb'] = new MNW_WPDB();

require dirname( __DIR__ ) . '/inc/core/search-functions.php';
require dirname( __DIR__ ) . '/inc/core/search-algorithm.php';

$failures = 0;
function mnw_check( $cond, $msg ) {
	global $failures;
	if ( ! $cond ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$msg}\n" );
	}
}

// Tokenizer follows InnoDB: hyphen splits, underscore joins, min length 3.
mnw_check( array( 'wu', 'tang' ) !== extrachill_fulltext_tokenize( 'Wu-Tang' ) && array( 'tang' ) === extrachill_fulltext_tokenize( 'Wu-Tang' ), 'tokenizer drops short tokens and splits on hyphen' );
mnw_check( array( 'data_machine_events' ) === extrachill_fulltext_tokenize( 'data_machine_events' ), 'tokenizer keeps underscores' );

// Too few samples says nothing.
mnw_check( array() === extrachill_compute_markup_noise_words( array( '<!-- wp:paragraph -->' ) ), 'fewer than 10 samples yields no noise words' );

$noise = extrachill_get_markup_noise_words();
foreach ( array( 'data', 'machine', 'events', 'event', 'details', 'startdate', 'venue', 'performertype', 'performinggroup' ) as $word ) {
	mnw_check( in_array( $word, $noise, true ), "'{$word}' detected as markup noise" );
}
foreach ( array( 'rage', 'against', 'band', 'covers', 'plays' ) as $word ) {
	mnw_check( ! in_array( $word, $noise, true ), "'{$word}' (body text) is not noise" );
}
mnw_check( 1 === $GLOBALS['mnw_queries'], 'noise words are sampled with one bounded query' );
extrachill_get_markup_noise_words();
mnw_check( 1 === $GLOBALS['mnw_queries'], 'noise words are cached' );

// Stripping.
mnw_check( array( 'Wu-Tang', 'Rage', 'Against', 'the' ) === extrachill_strip_search_noise_words( array( 'Wu-Tang', 'Rage', 'Against', 'the', 'Machine' ), $noise ), 'noise word removed from multi-word query' );
mnw_check( array( 'Head' ) === extrachill_strip_search_noise_words( array( 'Machine', 'Head' ), $noise ), 'Machine Head keeps Head' );
mnw_check( array() === extrachill_strip_search_noise_words( array( 'Data', 'Machine' ), $noise ), 'all-noise query leaves nothing' );
mnw_check( array( 'x', 'y' ) === extrachill_strip_search_noise_words( array( 'x', 'y' ), array() ), 'no noise list leaves words untouched' );

// Boolean clause.
$clause = extrachill_build_fulltext_clause( 'Rage Against the Machine' );
mnw_check( false !== strpos( $clause, "AGAINST('+Rage +Against +the' IN BOOLEAN MODE)" ), 'boolean clause omits the noise word' );
mnw_check( '1=0' === extrachill_build_fulltext_clause( 'Data Machine' ), 'all-noise boolean clause matches nothing instead of the whole table' );
mnw_check( false === extrachill_build_fulltext_clause( '   ' ), 'empty term still returns false' );

if ( $failures ) {
	exit( 1 );
}
echo "Markup noise words smoke passed.\n";
