<?php
/**
 * Smoke test: event marking search uses a bounded FULLTEXT title prefilter.
 *
 * Run with: php tests/event-search-title-prefilter-smoke.php
 *
 * @package ExtraChillUsers
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['etp_cache']   = array();
$GLOBALS['etp_indexes'] = array( 'post_title', 'post_excerpt', 'post_content' );
$GLOBALS['etp_show']    = 0;

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['etp_cache'][ $group . $key ] ?? false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	$GLOBALS['etp_cache'][ $group . $key ] = $value;
	return true;
}
function esc_sql( $value ) {
	return addslashes( $value );
}

/* Fake wpdb answering only SHOW INDEX. */
class ETP_WPDB {
	public function get_col( $sql, $column = 0 ) {
		++$GLOBALS['etp_show'];
		return 0 === strpos( $sql, 'SHOW INDEX FROM' ) ? $GLOBALS['etp_indexes'] : array();
	}
}
$GLOBALS['wpdb'] = new ETP_WPDB();

/* Load only the helper functions from service.php. */
$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/concert-tracking/service.php' );
$start  = strrpos( substr( $source, 0, strpos( $source, 'function ec_users_build_title_prefilter' ) ), '/**' );
$end    = strrpos( substr( $source, 0, strpos( $source, 'function ec_users_search_events_for_marking' ) ), '/**' );
eval( substr( $source, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only loader.

$failures = 0;
function etp_check( $cond, $msg ) {
	global $failures;
	if ( ! $cond ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$msg}\n" );
	}
}

etp_check( array( 'pils' ) === ec_users_fulltext_prefix_words( 'Pils' ), 'single word' );
etp_check( array( 'tang' ) === ec_users_fulltext_prefix_words( 'Wu-Tang' ), 'short tokens dropped, hyphen splits' );
etp_check( array( 'rage', 'against', 'machine' ) === ec_users_fulltext_prefix_words( 'Rage Against the Machine' ), 'stopwords dropped' );
etp_check( array() === ec_users_fulltext_prefix_words( 'Wu' ), 'too-short query has no words' );
etp_check( array( 'blind', 'tiger', 'various' ) === ec_users_fulltext_prefix_words( 'Blind Tiger (various*' ), 'boolean operators cannot leak through' );
etp_check( array( 'pils' ) === ec_users_fulltext_prefix_words( 'pils PILS' ), 'duplicates collapse' );

$clause = ec_users_build_title_prefilter( 'Rage Against the Machine', 'c8c_7_posts' );
etp_check( "MATCH(ft.post_title, ft.post_excerpt, ft.post_content) AGAINST('+rage* +against* +machine*' IN BOOLEAN MODE)" === $clause, 'prefilter clause requires each word as a prefix' );
etp_check( '' === ec_users_build_title_prefilter( 'Wu', 'c8c_7_posts' ), 'no prefilter for too-short query (falls back to LIKE)' );

ec_users_build_title_prefilter( 'Pils', 'c8c_7_posts' );
etp_check( 1 === $GLOBALS['etp_show'], 'FULLTEXT index check is cached per table' );

$GLOBALS['etp_indexes'] = array();
etp_check( '' === ec_users_build_title_prefilter( 'Pils', 'c8c_9_posts' ), 'no prefilter when the table lacks the FULLTEXT index' );

$GLOBALS['etp_indexes'] = array( 'post_title' );
etp_check( '' === ec_users_build_title_prefilter( 'Pils', 'c8c_10_posts' ), 'no prefilter when the index columns differ' );

/* The search query itself must not use a per-row correlated EXISTS any more. */
$search_fn = substr( $source, strpos( $source, 'function ec_users_search_events_for_marking' ) );
$search_fn = substr( $search_fn, 0, strpos( $search_fn, "\n}\n" ) );
etp_check( false === strpos( $search_fn, 'EXISTS' ), 'term match is an uncorrelated IN, not EXISTS per row' );
etp_check( false !== strpos( $search_fn, 'ec_users_build_title_prefilter(' ), 'search uses the title prefilter' );

if ( $failures ) {
	exit( 1 );
}
echo "Event search title prefilter smoke passed.\n";
