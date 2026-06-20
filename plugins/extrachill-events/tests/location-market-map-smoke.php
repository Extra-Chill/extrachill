<?php
/**
 * Location market-map smoke test.
 *
 * Self-contained (no PHPUnit / WP test framework). Verifies that
 * extrachill_events_get_market_slug_for_venue() rolls metro-suburb venue
 * cities up to the correct discovery market. Guards the Houston/Dallas
 * metro mappings added for extrachill-events#... (data-machine-events#379):
 * a 50mi city-pipeline ingest radius was stamping every metro event with the
 * pipeline-center city term (e.g. Galveston) because suburbs like Sugar Land,
 * Pasadena TX, Pearland, Humble, Texas City, Lake Jackson had no market
 * mapping and no location term, so the venue-city resolver fell back to the
 * flow-config term.
 *
 * Run directly:
 *   php tests/location-market-map-smoke.php
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP shims used while loading the normalizer file. Only the market-map
// resolver path is exercised here, which performs no WP lookups.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by() {
		return false;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term() {
		return null;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms() {
		return array();
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

require_once dirname( __DIR__ ) . '/inc/core/location-normalizer.php';

$cases = array(
	// [ city, state, zip, expected market slug ]
	array( 'Sugar Land', 'TX', '77478', 'houston' ),
	array( 'Pasadena', 'TX', '77506', 'houston' ),
	array( 'Pasadena', 'CA', '91101', 'los-angeles' ),
	array( 'Pearland', 'TX', '77581', 'houston' ),
	array( 'Humble', 'TX', '77338', 'houston' ),
	array( 'Texas City', 'TX', '77590', 'houston' ),
	array( 'Lake Jackson', 'TX', '77566', 'houston' ),
	array( 'Conroe', 'TX', '77301', 'houston' ),
	array( 'Katy', 'TX', '77449', 'houston' ),
	array( 'Spring', 'TX', '77373', 'houston' ),
	array( 'The Woodlands', 'TX', '77380', 'houston' ),
	array( 'Carrollton', 'TX', '75006', 'dallas' ),
	array( 'Fort Worth', 'TX', '76102', 'dallas' ),
	array( 'Ft Worth', 'TX', '76102', 'dallas' ),
	// Galveston itself has its own location term — the market map must NOT
	// roll it up; null lets the exact-city-name match resolve it.
	array( 'Galveston', 'TX', '77550', null ),

	// Network-wide metro-suburb rollups (data-machine-events#379).
	// Spelling normalizations.
	array( 'Saint Louis', 'MO', '63101', 'st-louis' ),
	array( 'Saint Paul', 'MN', '55101', 'st-paul' ),
	array( 'Saint Augustine', 'FL', '32084', 'st-augustine' ),
	// LA / Orange County rolls to los-angeles (existing convention).
	array( 'Santa Ana', 'CA', '92701', 'los-angeles' ),
	array( 'Anaheim', 'CA', '92805', 'los-angeles' ),
	array( 'Inglewood', 'CA', '90301', 'los-angeles' ),
	// Phoenix metro.
	array( 'Mesa', 'AZ', '85201', 'phoenix' ),
	array( 'Chandler', 'AZ', '85225', 'phoenix' ),
	// Detroit metro.
	array( 'Ferndale', 'MI', '48220', 'detroit' ),
	array( 'Royal Oak', 'MI', '48067', 'detroit' ),
	// Chicago metro.
	array( 'Joliet', 'IL', '60435', 'chicago' ),
	array( 'Rosemont', 'IL', '60018', 'chicago' ),
	// Cleveland / Pittsburgh metro.
	array( 'Cleveland Heights', 'OH', '44118', 'cleveland' ),
	array( 'Millvale', 'PA', '15209', 'pittsburgh' ),
	// State-keyed disambiguation.
	array( 'Aurora', 'CO', '80012', 'denver' ),
	array( 'Aurora', 'IL', '60505', 'chicago' ),
	array( 'Newport', 'KY', '41071', 'cincinnati' ),
	array( 'Covington', 'KY', '41011', 'cincinnati' ),
	array( 'Covington', 'OH', '45318', 'dayton' ),
	array( 'Everett', 'MA', '02149', 'boston' ),
	array( 'Everett', 'WA', '98201', 'seattle' ),
	array( 'Highland Park', 'CA', '90042', 'los-angeles' ),
	array( 'Highland Park', 'IL', '60035', 'chicago' ),
	array( 'Dallas suburb Irving', 'TX', '75061', null ), // sanity: unmapped phrase stays null
	array( 'Irving', 'TX', '75061', 'dallas' ),

	// Canada: Vancouver-metro suburbs roll up to the Vancouver, BC term
	// (slug vancouver-bc). Richmond is BC-keyed so it never hijacks Richmond, VA.
	array( 'Surrey', 'BC', '', 'vancouver-bc' ),
	array( 'Coquitlam', 'BC', '', 'vancouver-bc' ),
	array( 'Abbotsford', 'BC', '', 'vancouver-bc' ),
	array( 'Richmond', 'BC', '', 'vancouver-bc' ),
	array( 'Richmond', 'VA', '', null ), // US Richmond stays null -> exact-name match resolves it
	// Niagara Falls is cross-border: NY rolls to Buffalo; the Ontario side has
	// its own Canada-tree term and must stay null (resolves via exact-name match).
	array( 'Niagara Falls', 'NY', '', 'buffalo' ),
	array( 'Niagara Falls', 'ON', '', null ),
	array( 'Burnaby', 'BC', '', 'vancouver-bc' ),

	// Mexico: Rosarito (Baja California) must NOT roll into a US market via the
	// map — it has its own Mexico-tree term (slug rosarito-bcn) and resolves via
	// exact-name match. The map must return null so it never lands in San Diego.
	// "BC" here is Baja California (MX), distinct from British Columbia (CA);
	// because Rosarito is a unique city name it resolves without state collision.
	array( 'Rosarito', 'BC', '', null ),

	// Existing mappings must keep working.
	array( 'Cambridge', 'MA', '02139', 'boston' ),
	array( 'North Charleston', 'SC', '29405', 'charleston' ),
);

$pass = 0;
$fail = 0;

foreach ( $cases as $case ) {
	list( $city, $state, $zip, $expected ) = $case;
	$got = extrachill_events_get_market_slug_for_venue( $city, $state, $zip );

	if ( $got === $expected ) {
		++$pass;
	} else {
		++$fail;
		printf(
			"FAIL: %s, %s (%s) -> %s, expected %s\n",
			$city,
			$state,
			$zip,
			var_export( $got, true ),
			var_export( $expected, true )
		);
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
