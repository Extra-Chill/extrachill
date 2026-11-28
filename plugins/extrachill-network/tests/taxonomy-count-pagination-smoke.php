<?php
/**
 * Bulk taxonomy pagination and filtering contract, without a WordPress runtime.
 *
 * @package ExtraChillNetwork
 */

define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {}
function add_action( ...$args ) {}
function __( $text, $domain ) { return $text; }
function wp_register_ability( $name, $args ) { $GLOBALS['registration'] = $args; }
function ec_get_blog_id( $key ) { return 1; }
function sanitize_title( $slug ) { return $slug; }
function switch_to_blog( $id ) { ++$GLOBALS['depth']; }
function restore_current_blog() { --$GLOBALS['depth']; }
function get_taxonomy( $taxonomy ) { return (object) array( 'object_type' => array( 'post' ) ); }
function get_term_link( $term, $taxonomy = '' ) {
	$id = is_object( $term ) ? $term->term_id : $term;
	return $id === ( $GLOBALS['invalid_link'] ?? null ) ? new WP_Error() : '/term/' . $id;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_term_by( $field, $slug, $taxonomy ) {
	return 'alpha' === $slug ? (object) array( 'term_id' => 1, 'name' => 'Alpha', 'slug' => 'alpha' ) : false;
}
class WP_Query { public int $found_posts = 5; public function __construct( $args ) {} }

// Emulate only the query boundary; assert SQL ordering and LIKE escaping too.
class TaxonomyPaginationDatabase {
	public string $term_relationships = 'relationships';
	public string $term_taxonomy = 'taxonomy';
	public string $terms = 'terms';
	public string $posts = 'posts';
	public array $args = array();
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $sql, $args ) {
		check( str_contains( $sql, 'ORDER BY post_count DESC, t.term_id ASC' ), 'Stable ordering for tied counts' );
		$this->args = $args;
		return $sql;
	}
	public function get_results( $sql ) {
		$rows = array();
		foreach ( array( 'Alpha', 'Alpha Band', 'Beta', 'Gamma' ) as $index => $name ) {
			if ( str_contains( $sql, 't.name LIKE %s' ) ) {
				$needle = stripcslashes( substr( $this->args[2], 1, -1 ) );
				if ( false === stripos( $name, $needle ) ) { continue; }
			}
			$rows[] = (object) array( 'term_id' => $index + 1, 'name' => $name, 'slug' => strtolower( $name ), 'post_count' => 5 - $index );
		}
		return $rows;
	}
}
function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/inc/Abilities/TaxonomyCountAbilities.php';
$GLOBALS['depth'] = 0;
$wpdb = new TaxonomyPaginationDatabase();
$ability = new ExtraChillNetwork\Abilities\TaxonomyCountAbilities();
$ability->register();
foreach ( array( 'number', 'offset', 'search', 'min_count' ) as $property ) {
	check( isset( $GLOBALS['registration']['input_schema']['properties'][ $property ] ), 'Registered optional input ' . $property );
	check( ! in_array( $property, $GLOBALS['registration']['input_schema']['required'], true ), 'Input remains optional' );
}
check( 'boolean' === $GLOBALS['registration']['output_schema']['properties']['has_more']['type'], 'Registered has_more output' );
check( 1 === $GLOBALS['registration']['input_schema']['properties']['min_count']['minimum'], 'Minimum count matches published-only listing' );
$run = static function ( $options = array() ) use ( $ability ) {
	$result = $ability->executeGetTaxonomyPostCounts( array_merge( array( 'site' => 'main', 'taxonomy' => 'artist' ), $options ) );
	check( 0 === $GLOBALS['depth'], 'Blog restored' );
	return $result;
};
$all = $run();
check( 4 === count( $all['terms'] ) && 4 === $all['total'] && ! $all['has_more'], 'Default returns full listing' );
check( array( 1, 2, 3, 4 ) === array_column( $all['terms'], 'term_id' ), 'Default listing preserves term data' );
check( array( 'artist', 'post', '%%' ) === $wpdb->args, 'Default search uses a prepared match-all pattern' );
check( $all === $run( array( 'search' => '' ) ), 'Empty search preserves the default listing' );
foreach ( array( 0, 1, 2, 3, 4, 10 ) as $offset ) {
	$page = $run( array( 'number' => 2, 'offset' => $offset ) );
	check( array_slice( $all['terms'], $offset, 2 ) === $page['terms'], 'Page boundary ' . $offset );
	check( 4 === $page['total'] && ( $offset + 2 < 4 ) === $page['has_more'], 'Total/has_more at ' . $offset );
}
check( $all === $run( array( 'number' => 0 ) ), 'Zero number is unlimited' );
check( $all === $run( array( 'number' => 100 ) ), 'Page larger than listing' );
check( array_slice( $all['terms'], 2 ) === $run( array( 'offset' => 2 ) )['terms'], 'Offset without number' );
$filtered = $run( array( 'search' => 'ALPHA', 'min_count' => 4, 'number' => 1 ) );
check( 2 === $filtered['total'] && $filtered['has_more'] && 1 === count( $filtered['terms'] ), 'Search and count filter before paging' );
$last = $run( array( 'search' => 'ALPHA', 'min_count' => 4, 'number' => 1, 'offset' => 1 ) );
check( 2 === $last['total'] && ! $last['has_more'] && 2 === $last['terms'][0]['term_id'], 'Filtered final page' );
check( 1 === $run( array( 'min_count' => 5 ) )['total'], 'Inclusive min_count' );
$empty = $run( array( 'min_count' => 6 ) );
check( array() === $empty['terms'] && 0 === $empty['total'] && ! $empty['has_more'], 'Count filter no matches' );
$empty = $run( array( 'search' => 'missing' ) );
check( array() === $empty['terms'] && 0 === $empty['total'] && ! $empty['has_more'], 'Empty search' );
check( 2 === $run( array( 'search' => 'pha' ) )['total'], 'Search matches inside term name' );
$run( array( 'search' => '50%_off' ) );
check( '%50\\%\\_off%' === $wpdb->args[2], 'Search escapes literal LIKE wildcards' );
$GLOBALS['invalid_link'] = 2;
$page = $run( array( 'number' => 2 ) );
check( 3 === $page['total'] && $page['has_more'] && array( 1, 3 ) === array_column( $page['terms'], 'term_id' ), 'Invalid URLs excluded before pagination' );
unset( $GLOBALS['invalid_link'] );
$single = $run( array( 'slug' => 'alpha' ) );
check( $single === $run( array( 'slug' => 'alpha', 'number' => 1, 'offset' => 99, 'search' => 'missing', 'min_count' => 99 ) ), 'Slug ignores bulk options' );
check( 1 === $single['total'] && ! $single['has_more'], 'Single-term metadata' );
$missing = $run( array( 'slug' => 'missing' ) );
check( 0 === $missing['total'] && array() === $missing['terms'] && ! $missing['has_more'], 'Missing slug unchanged' );
echo "PASS: taxonomy count pagination, filters, defaults, and slug compatibility.\n";
