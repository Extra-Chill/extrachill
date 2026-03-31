<?php
/**
 * Location Normalizer
 *
 * Corrects the location taxonomy term on imported events based on the
 * venue's market. Handles cities where geographic radius-based imports
 * (Ticketmaster, etc.) cause events to be tagged with the wrong market or
 * wrong sub-location.
 *
 * Currently handles:
 * - New York City boroughs (Brooklyn, Queens, Manhattan, Bronx, Staten Island)
 * - Extra Chill municipality-to-market rollups (for example North Charleston → Charleston)
 *
 * Fires on the `datamachine_event_taxonomy_processed` action, which runs after
 * TaxonomyHandler has set all taxonomy terms (including location). This ensures
 * the normalizer can read and correct the location term that was assigned by the
 * import flow.
 *
 * @package ExtraChillEvents
 * @since 0.8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'datamachine_event_taxonomy_processed', 'extrachill_events_normalize_location' );

/**
 * Normalize event location based on venue market.
 *
 * Fires after TaxonomyHandler has set all taxonomy terms on an event.
 * Looks up the venue city/state/zip from term meta and corrects the
 * location taxonomy if it maps to a different term than what was assigned
 * by the import pipeline.
 *
 * @param int $post_id Post ID.
 */
function extrachill_events_normalize_location( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$venues = get_the_terms( $post_id, 'venue' );
	if ( ! $venues || is_wp_error( $venues ) ) {
		return;
	}

	$venue                = $venues[0];
	$venue_city           = (string) get_term_meta( $venue->term_id, '_venue_city', true );
	$venue_state          = (string) get_term_meta( $venue->term_id, '_venue_state', true );
	$zip                  = (string) get_term_meta( $venue->term_id, '_venue_zip', true );
	$correct_location_slug = extrachill_events_get_market_slug_for_venue( $venue_city, $venue_state, $zip );
	if ( ! $correct_location_slug ) {
		return;
	}

	// Check if the event already has the correct location term.
	$current_locations = get_the_terms( $post_id, 'location' );
	if ( $current_locations && ! is_wp_error( $current_locations ) ) {
		foreach ( $current_locations as $loc ) {
			if ( $loc->slug === $correct_location_slug ) {
				return; // Already correct.
			}
		}
	}

	// Look up the correct location term.
	$correct_term = get_term_by( 'slug', $correct_location_slug, 'location' );
	if ( ! $correct_term || is_wp_error( $correct_term ) ) {
		return;
	}

	// Replace all location terms with the correct one.
	wp_set_object_terms( $post_id, array( $correct_term->term_id ), 'location' );
}

/**
 * Get the canonical market slug for a venue.
 *
 * Resolution order:
 * 1. NYC zip → borough mapping
 * 2. Explicit municipality → market mapping
 * 3. No opinion (null)
 *
 * @param string $city  Venue city.
 * @param string $state Venue state.
 * @param string $zip   Venue zip.
 * @return string|null Canonical location slug, or null if no mapping exists.
 */
function extrachill_events_get_market_slug_for_venue( $city, $state = '', $zip = '' ) {
	$borough_slug = extrachill_events_get_location_slug_for_zip( $zip );
	if ( $borough_slug ) {
		return $borough_slug;
	}

	$city  = strtolower( trim( (string) $city ) );
	$state = strtolower( trim( (string) $state ) );

	if ( '' === $city ) {
		return null;
	}

	$market_map = extrachill_events_get_city_market_map();
	if ( ! isset( $market_map[ $city ] ) ) {
		return null;
	}

	$mapping = $market_map[ $city ];

	if ( is_string( $mapping ) ) {
		return $mapping;
	}

	if ( '' !== $state && isset( $mapping[ $state ] ) ) {
		return $mapping[ $state ];
	}

	if ( isset( $mapping['default'] ) ) {
		return $mapping['default'];
	}

	return null;
}

/**
 * Get municipality → market mapping for Extra Chill events.
 *
 * The location taxonomy represents discovery markets, not every municipality.
 * NYC boroughs are handled separately via zip mapping.
 *
 * @return array<string, string|array<string, string>>
 */
function extrachill_events_get_city_market_map() {
	return array(
		'alexandria'      => 'washington-d-c',
		'allentown'       => 'philadelphia',
		'anderson'        => 'greenville',
		'ann arbor'       => 'detroit',
		'annapolis'       => 'washington-d-c',
		'ardmore'         => 'philadelphia',
		'arlington'       => array(
			'virginia' => 'washington-d-c',
			'va'       => 'washington-d-c',
			'texas'    => 'dallas',
			'tx'       => 'dallas',
		),
		'arnoldsville'    => 'athens',
		'bensalem'        => 'philadelphia',
		'berwyn'          => 'chicago',
		'bethesda'        => 'washington-d-c',
		'bethlehem'       => 'philadelphia',
		'bristow'         => 'washington-d-c',
		'bronx'           => 'new-york-city',
		'black mountain'  => 'asheville',
		'bloomingdale'    => 'savannah',
		'buda'            => 'austin',
		'cambridge'       => 'boston',
		'columbia'        => array(
			'maryland'  => 'baltimore',
			'md'        => 'baltimore',
		),
		'cedar park'      => 'austin',
		'cherokee'        => 'asheville',
		'clemson'         => 'greenville',
		'danielsville'    => 'athens',
		'decatur'         => 'atlanta',
		'duluth'          => 'atlanta',
		'durham'          => 'raleigh',
		'easley'          => 'greenville',
		'evanston'        => 'chicago',
		'folly beach'     => 'charleston',
		'forest park'     => 'chicago',
		'franklin'        => 'nashville',
		'ft lauderdale'   => 'miami',
		'frenchtown'      => 'philadelphia',
		'georgetown'      => array(
			'texas' => 'austin',
		),
		'glendale'        => 'phoenix',
		'glenside'        => 'philadelphia',
		'hamtramck'       => 'detroit',
		'henderson'       => 'las-vegas',
		'hollywood'       => array(
			'california'     => 'los-angeles',
			'florida'        => 'miami',
			'south carolina' => 'charleston',
		),
		'isle of palms'   => 'charleston',
		'lakewood'        => 'cleveland',
		'leesburg'        => 'washington-d-c',
		'lockhart'        => 'austin',
		'metairie'        => 'new-orleans',
		'miami beach'     => 'miami',
		'manhattan'       => 'new-york-city',
		'murfreesboro'    => 'nashville',
		'new york'        => 'new-york-city',
		'new york city'   => 'new-york-city',
		'north charleston'=> 'charleston',
		'pflugerville'    => 'austin',
		'pottstown'       => 'philadelphia',
		'port wentworth'  => 'savannah',
		'queens'          => 'queens',
		'reading'         => 'philadelphia',
		'ridgewood'       => 'queens',
		'round rock'      => 'austin',
		'san marcos'      => 'austin',
		'simpsonville'    => 'greenville',
		'solana beach'    => 'san-diego',
		'spartanburg'     => 'greenville',
		'st. paul'        => 'minneapolis',
		'staten island'   => 'new-york-city',
		'silver spring'   => 'washington-d-c',
		'summerville'     => 'charleston',
		'tempe'           => 'phoenix',
		'winterville'     => 'athens',
		'washington'      => array(
			'dc'                     => 'washington-d-c',
			'd.c.'                   => 'washington-d-c',
			'district of columbia'   => 'washington-d-c',
		),
		'washington, d.c.' => 'washington-d-c',
		'watkinsville'    => 'athens',
		'west hollywood'  => 'los-angeles',
		'wilmington'      => 'philadelphia',
		'worcester'       => 'boston',
	);
}

/**
 * Get the correct location slug for a US zip code.
 *
 * Returns a location taxonomy slug if the zip code belongs to a city
 * where sub-location normalization is needed. Returns null for zip codes
 * that don't require correction.
 *
 * @param string $zip Zip code (5-digit US).
 * @return string|null Location taxonomy slug, or null if no mapping exists.
 */
function extrachill_events_get_location_slug_for_zip( $zip ) {
	$zip = intval( substr( trim( $zip ), 0, 5 ) );

	if ( 0 === $zip ) {
		return null;
	}

	$nyc_map = extrachill_events_get_nyc_zip_map();

	if ( isset( $nyc_map[ $zip ] ) ) {
		return $nyc_map[ $zip ];
	}

	return null;
}

/**
 * Get NYC zip code → location slug mapping.
 *
 * Maps every NYC zip code to its borough's location taxonomy slug.
 * Source: Verified against Nominatim (OpenStreetMap) + USPS zip code data (March 2026).
 *
 * @return array Zip code (int) => location slug (string).
 */
function extrachill_events_get_nyc_zip_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();

	// Brooklyn: 112xx range.
	$brooklyn_zips = array(
		11201,
		11202,
		11203,
		11204,
		11205,
		11206,
		11207,
		11208,
		11209,
		11210,
		11211,
		11212,
		11213,
		11214,
		11215,
		11216,
		11217,
		11218,
		11219,
		11220,
		11221,
		11222,
		11223,
		11224,
		11225,
		11226,
		11227,
		11228,
		11229,
		11230,
		11231,
		11232,
		11233,
		11234,
		11235,
		11236,
		11237,
		11238,
		11239,
		11241,
		11242,
		11243,
		11245,
		11247,
		11249,
		11251,
		11252,
		11256,
	);
	foreach ( $brooklyn_zips as $z ) {
		$map[ $z ] = 'brooklyn';
	}

	// Manhattan: 100xx–102xx range.
	$manhattan_zips = array(
		10001,
		10002,
		10003,
		10004,
		10005,
		10006,
		10007,
		10008,
		10009,
		10010,
		10011,
		10012,
		10013,
		10014,
		10015,
		10016,
		10017,
		10018,
		10019,
		10020,
		10021,
		10022,
		10023,
		10024,
		10025,
		10026,
		10027,
		10028,
		10029,
		10030,
		10031,
		10032,
		10033,
		10034,
		10035,
		10036,
		10037,
		10038,
		10039,
		10040,
		10041,
		10043,
		10044,
		10045,
		10055,
		10060,
		10065,
		10069,
		10075,
		10080,
		10081,
		10087,
		10101,
		10102,
		10103,
		10104,
		10105,
		10106,
		10107,
		10108,
		10109,
		10110,
		10111,
		10112,
		10113,
		10114,
		10115,
		10116,
		10117,
		10118,
		10119,
		10120,
		10121,
		10122,
		10123,
		10124,
		10125,
		10126,
		10128,
		10129,
		10130,
		10131,
		10132,
		10133,
		10138,
		10150,
		10151,
		10152,
		10153,
		10154,
		10155,
		10156,
		10157,
		10158,
		10159,
		10160,
		10161,
		10162,
		10163,
		10164,
		10165,
		10166,
		10167,
		10168,
		10169,
		10170,
		10171,
		10172,
		10173,
		10174,
		10175,
		10176,
		10177,
		10178,
		10179,
		10185,
		10199,
		10203,
		10211,
		10212,
		10213,
		10242,
		10249,
		10256,
		10257,
		10258,
		10259,
		10260,
		10261,
		10265,
		10268,
		10269,
		10270,
		10271,
		10272,
		10273,
		10274,
		10275,
		10276,
		10277,
		10278,
		10279,
		10280,
		10281,
		10282,
		10285,
		10286,
	);
	foreach ( $manhattan_zips as $z ) {
		$map[ $z ] = 'new-york-city';
	}

	// Queens: 110xx (partial) + 113xx–116xx range.
	$queens_zips = array(
		11004,
		11005,
		11101,
		11102,
		11103,
		11104,
		11105,
		11106,
		11109,
		11351,
		11352,
		11354,
		11355,
		11356,
		11357,
		11358,
		11359,
		11360,
		11361,
		11362,
		11363,
		11364,
		11365,
		11366,
		11367,
		11368,
		11369,
		11370,
		11371,
		11372,
		11373,
		11374,
		11375,
		11377,
		11378,
		11379,
		11380,
		11381,
		11385,
		11386,
		11405,
		11411,
		11412,
		11413,
		11414,
		11415,
		11416,
		11417,
		11418,
		11419,
		11420,
		11421,
		11422,
		11423,
		11424,
		11425,
		11426,
		11427,
		11428,
		11429,
		11430,
		11431,
		11432,
		11433,
		11434,
		11435,
		11436,
		11439,
		11451,
		11690,
		11691,
		11692,
		11693,
		11694,
		11695,
		11697,
	);
	foreach ( $queens_zips as $z ) {
		$map[ $z ] = 'queens';
	}

	// Bronx: 104xx range.
	$bronx_zips = array(
		10451,
		10452,
		10453,
		10454,
		10455,
		10456,
		10457,
		10458,
		10459,
		10460,
		10461,
		10462,
		10463,
		10464,
		10465,
		10466,
		10467,
		10468,
		10469,
		10470,
		10471,
		10472,
		10473,
		10474,
		10475,
	);
	foreach ( $bronx_zips as $z ) {
		$map[ $z ] = 'new-york-city';
	}

	// Staten Island: 103xx range.
	$staten_island_zips = array(
		10301,
		10302,
		10303,
		10304,
		10305,
		10306,
		10307,
		10308,
		10309,
		10310,
		10311,
		10312,
		10314,
	);
	foreach ( $staten_island_zips as $z ) {
		$map[ $z ] = 'new-york-city';
	}

	return $map;
}
