<?php
/**
 * Smoke test for Festival Wire classification policy.
 *
 * Run with: php tests/smoke-classification.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$terms = array(
	'festival' => array(
		(object) array( 'term_id' => 3, 'name' => 'Bonnaroo', 'slug' => 'bonnaroo', 'taxonomy' => 'festival', 'count' => 535 ),
		(object) array( 'term_id' => 252, 'name' => 'Primavera Sound Barcelona', 'slug' => 'primavera-sound-barcelona', 'taxonomy' => 'festival', 'count' => 33 ),
		(object) array( 'term_id' => 356, 'name' => 'ai_decides', 'slug' => 'ai_decides', 'taxonomy' => 'festival', 'count' => 1 ),
	),
	'location' => array(
		(object) array( 'term_id' => 26, 'name' => 'Austin', 'slug' => 'austin', 'taxonomy' => 'location', 'count' => 161 ),
		(object) array( 'term_id' => 359, 'name' => 'Primavera Sound', 'slug' => 'primavera-sound', 'taxonomy' => 'location', 'count' => 9 ),
		(object) array( 'term_id' => 368, 'name' => 'primaverasound', 'slug' => 'primaverasound', 'taxonomy' => 'location', 'count' => 2 ),
		(object) array( 'term_id' => 363, 'name' => 'Austin, Texas', 'slug' => 'austin-texas', 'taxonomy' => 'location', 'count' => 2 ),
		(object) array( 'term_id' => 394, 'name' => 'location:', 'slug' => 'location', 'taxonomy' => 'location', 'count' => 1 ),
	),
);
$post_types = array( 7132 => 'festival_wire', 8047 => 'festival_wire', 10 => 'post' );
$objects    = array( 'festival:356' => array( 7132 ), 'location:394' => array( 8047 ), 'location:363' => array( 10 ) );
$mutations  = array();
$set_result = array( 359 );

function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function remove_accents( $value ) { return $value; }
function is_wp_error( $value ) { return false; }
function get_terms( $args ) { global $terms; return $terms[ $args['taxonomy'] ]; }
function get_post_type( $post_id ) { global $post_types; return $post_types[ $post_id ] ?? ''; }
function get_objects_in_term( $term_id, $taxonomy ) { global $objects; return $objects[ $taxonomy . ':' . $term_id ] ?? array(); }
function get_term( $term_id, $taxonomy ) { global $terms; foreach ( $terms[ $taxonomy ] as $term ) { if ( $term->term_id === $term_id ) { return $term; } } return null; }
function add_filter() {}
function do_action() {}
function wp_set_object_terms( $post_id, $term_ids ) { global $mutations, $set_result; $mutations[] = 'set:' . implode( ',', $term_ids ); return $set_result; }
function wp_remove_object_terms( $post_id, $term_ids ) { global $mutations; $mutations[] = 'remove:' . implode( ',', $term_ids ); return true; }
function wp_delete_term( $term_id ) { global $mutations; $mutations[] = 'delete:' . $term_id; return true; }

require dirname( __DIR__ ) . '/includes/classification.php';

function expect( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

expect( '' === ec_news_wire_validate_classification( 'ai_decides', 'festival', 7132 ), 'Instruction placeholders must be rejected.' );
expect( '' === ec_news_wire_validate_classification( 'location:', 'location', 8047 ), 'Taxonomy labels must be rejected.' );
expect( '3' === ec_news_wire_validate_classification( 'Bonnaroo', 'festival', 7132 ), 'Canonical terms must resolve to their existing IDs.' );
expect( '' === ec_news_wire_validate_classification( 'Austin, Texas', 'location', 8047 ), 'Ambiguous city/state duplicates must be held for review.' );
expect( '359' === ec_news_wire_validate_classification( 'primaverasound', 'location', 8047 ), 'Missing-space duplicates must resolve to the stronger existing term.' );
expect( 'ai_decides' === ec_news_wire_validate_classification( 'ai_decides', 'festival', 10 ), 'Other post types must not be affected.' );

$findings = ec_news_wire_audit_classifications( false );
expect( count( $findings ) >= 3, 'Dry-run must report malformed and duplicate terms.' );
expect( 'review' === array_values( array_filter( $findings, function ( $finding ) { return 363 === $finding['term_id']; } ) )[0]['action'], 'Ambiguous location duplicates must not be auto-merged.' );
expect( array() === $mutations, 'Dry-run audit must not mutate terms or relationships.' );

$objects['location:368'] = array( 8047 );
$set_result              = array();
$mutations               = array();
ec_news_wire_audit_classifications( true );
expect( ! in_array( 'remove:368', $mutations, true ), 'A failed canonical assignment must preserve the source relationship.' );

echo "Classification smoke tests passed.\n";
