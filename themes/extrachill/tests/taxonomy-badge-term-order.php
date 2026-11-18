<?php
/**
 * Badge term selection honors stored term order (extrachill#1685).
 *
 * Sort-enabled taxonomies (core 'sort' => true) persist per-object
 * term_order. Badges must lead with that order, so an event's headliners show
 * instead of whichever artists have the highest global post count. Terms
 * without a stored order keep the historical count/name selection.
 *
 * @package ExtraChill
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone fixture.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/inc/core/templates/taxonomy-badges.php';

/**
 * Build a term double.
 *
 * @param int    $tt_id Term taxonomy ID.
 * @param string $name  Name.
 * @param int    $count Post count.
 * @return object
 */
function ec_badge_term( $tt_id, $name, $count ) {
	return (object) array(
		'term_taxonomy_id' => $tt_id,
		'name'             => $name,
		'count'            => $count,
	);
}

/**
 * Assert selected names.
 *
 * @param string[] $expected Expected names.
 * @param array    $terms    Selected terms.
 * @param string   $label    Case label.
 */
function ec_badge_assert( array $expected, array $terms, $label ) {
	$actual = array_map( static fn( $term ) => $term->name, $terms );
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL {$label}: expected " . implode( ', ', $expected ) . ' got ' . implode( ', ', $actual ) . "\n" );
		exit( 1 );
	}
	echo "ok - {$label}\n";
}

$lineup = array(
	ec_badge_term( 1, 'Alpha', 2 ),
	ec_badge_term( 2, 'Bravo', 1 ),
	ec_badge_term( 3, 'Charlie', 5 ),
	ec_badge_term( 4, 'Delta', 9 ),
	ec_badge_term( 5, 'Echo', 18 ),
);

ec_badge_assert(
	array( 'Alpha', 'Bravo', 'Charlie' ),
	extrachill_select_badge_terms( $lineup, array( 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ) ),
	'stored order leads even when later terms have higher counts'
);

ec_badge_assert(
	array( 'Echo', 'Delta', 'Charlie' ),
	extrachill_select_badge_terms( $lineup, array() ),
	'no stored order selects top terms by count'
);

ec_badge_assert(
	array( 'Echo', 'Delta', 'Charlie' ),
	extrachill_select_badge_terms( $lineup, array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ) ),
	'legacy zero term_order falls back to count'
);

ec_badge_assert(
	array( 'Bravo', 'Echo', 'Delta' ),
	extrachill_select_badge_terms( $lineup, array( 2 => 1 ) ),
	'ordered terms lead, unordered follow by count'
);

$ties = array( ec_badge_term( 1, 'Zulu', 3 ), ec_badge_term( 2, 'Yankee', 3 ), ec_badge_term( 3, 'Xray', 3 ), ec_badge_term( 4, 'Whiskey', 3 ) );
ec_badge_assert(
	array( 'Whiskey', 'Xray', 'Yankee' ),
	extrachill_select_badge_terms( $ties, array() ),
	'equal counts break ties by name'
);
