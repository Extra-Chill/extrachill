<?php
/**
 * Location Normalizer
 *
 * Resolves and corrects the `location` taxonomy term on events based on the
 * venue's city/state/zip. Handles both radius-based mis-tagging (Ticketmaster,
 * etc.) and create-time assignment for any path that leaves location unset.
 *
 * Resolution order (extrachill-events#145):
 * 1. Market mapping — NYC boroughs by zip + municipality → market rollups
 *    (e.g. North Charleston → Charleston, folly beach → charleston).
 * 2. Exact city-name match — venue city directly matches an existing location
 *    term (e.g. "Austin" → the Austin location term), with state-based
 *    disambiguation when multiple terms share a city name.
 *
 * The resolver (`extrachill_events_resolve_location_term_for_venue_city`) is a
 * shared function used by BOTH this create-time normalizer and the
 * `extrachill/reconcile-event-locations` audit ability, so there is one source
 * of truth for "what location does this venue city belong to."
 *
 * Fires on the `datamachine_event_taxonomy_processed` action, which runs after
 * TaxonomyHandler has set all taxonomy terms (including location). This ensures
 * the normalizer can read and correct the location term that was assigned (or
 * left empty) by the import flow.
 *
 * @package ExtraChillEvents
 * @since 0.8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'datamachine_event_taxonomy_processed', 'extrachill_events_normalize_location' );

/**
 * Normalize event location based on venue city/state/zip.
 *
 * Fires after TaxonomyHandler has set all taxonomy terms on an event.
 * Looks up the venue city/state/zip from term meta and assigns/corrects the
 * location taxonomy via the shared resolver. Unlike the previous
 * market-map-only behavior, this now also matches direct city names against
 * existing location terms, so events whose venue city is a real city (not a
 * mapped suburb) still get their location term.
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

	$venue       = $venues[0];
	$venue_city  = (string) get_term_meta( $venue->term_id, '_venue_city', true );
	$venue_state = (string) get_term_meta( $venue->term_id, '_venue_state', true );
	$zip         = (string) get_term_meta( $venue->term_id, '_venue_zip', true );

	$correct_term = extrachill_events_resolve_location_term_for_venue_city( $venue_city, $venue_state, $zip );
	if ( ! $correct_term ) {
		return;
	}

	// Don't clobber an already-correct assignment.
	$current_locations = get_the_terms( $post_id, 'location' );
	if ( $current_locations && ! is_wp_error( $current_locations ) ) {
		foreach ( $current_locations as $loc ) {
			if ( (int) $loc->term_id === (int) $correct_term->term_id ) {
				return; // Already correct.
			}
		}
	}

	// Replace all location terms with the resolved one.
	wp_set_object_terms( $post_id, array( (int) $correct_term->term_id ), 'location' );
}

/**
 * Resolve the canonical `location` term for a venue's city/state/zip.
 *
 * Single source of truth for venue-city → location-term resolution, shared by
 * the create-time normalizer and the reconcile-event-locations ability
 * (extrachill-events#145). Resolution order:
 *
 * 1. Market mapping — venue maps to a canonical market (NYC borough by zip, or
 *    municipality → market rollup). See extrachill_events_get_market_slug_for_venue.
 * 2. Exact city-name match — venue city directly matches an existing location
 *    term name. When multiple terms share the name, disambiguate by venue state
 *    against the term's parent (state-level) term.
 *
 * Returns null when the city is empty, no mapping/term matches, or an
 * ambiguous city name can't be disambiguated by state.
 *
 * @param string $venue_city  Venue city.
 * @param string $venue_state Venue state (abbreviation like "SC" or full name).
 * @param string $venue_zip   Venue zip.
 * @return \WP_Term|null Resolved location term, or null when unresolved.
 */
function extrachill_events_resolve_location_term_for_venue_city( $venue_city, $venue_state = '', $venue_zip = '' ): ?\WP_Term {
	$venue_city = trim( (string) $venue_city );
	if ( '' === $venue_city ) {
		return null;
	}

	// 1. Market mapping.
	$market_slug = extrachill_events_get_market_slug_for_venue( $venue_city, $venue_state, $venue_zip );
	if ( $market_slug ) {
		$market_term = get_term_by( 'slug', $market_slug, 'location' );
		if ( $market_term instanceof \WP_Term ) {
			return $market_term;
		}
	}

	// 2. Exact city-name match against existing location terms.
	$city_key = strtolower( $venue_city );
	$matches  = extrachill_events_get_location_terms_by_name()[ $city_key ] ?? array();

	if ( 1 === count( $matches ) ) {
		return reset( $matches );
	}

	if ( count( $matches ) > 1 ) {
		return extrachill_events_disambiguate_location_by_state( $matches, (string) $venue_state );
	}

	return null;
}

/**
 * Disambiguate multiple same-named location terms using venue state.
 *
 * Location terms are hierarchical (Country > State > City). Compares the
 * venue's state (abbreviation or full name) against each candidate's parent
 * (state-level) term name.
 *
 * @param array<int, \WP_Term> $matches     Location terms sharing a city name.
 * @param string               $venue_state Venue state ("SC" or "South Carolina").
 * @return \WP_Term|null Matched term, or null if state doesn't resolve it.
 */
function extrachill_events_disambiguate_location_by_state( array $matches, string $venue_state ): ?\WP_Term {
	$venue_state = trim( $venue_state );
	if ( '' === $venue_state ) {
		return null;
	}

	$state_lower = strtolower( $venue_state );
	$abbrev_map  = extrachill_events_get_state_abbreviation_map();
	$full_name   = $abbrev_map[ strtoupper( $venue_state ) ] ?? null;

	foreach ( $matches as $match ) {
		if ( $match->parent <= 0 ) {
			continue;
		}

		$parent = get_term( $match->parent, 'location' );
		if ( ! $parent instanceof \WP_Term ) {
			continue;
		}

		$parent_lower = strtolower( $parent->name );

		if ( $parent_lower === $state_lower ) {
			return $match;
		}

		if ( $full_name && strtolower( $full_name ) === $parent_lower ) {
			return $match;
		}
	}

	return null;
}

/**
 * Get all location terms grouped by lowercase name.
 *
 * Cached per-request via a static to avoid re-querying when resolving many
 * events in one pass (e.g. a concert-import batch).
 *
 * @return array<string, array<int, \WP_Term>>
 */
function extrachill_events_get_location_terms_by_name(): array {
	static $by_name = null;

	if ( null !== $by_name ) {
		return $by_name;
	}

	$by_name = array();

	$terms = get_terms(
		array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return $by_name;
	}

	foreach ( $terms as $term ) {
		$key = strtolower( $term->name );
		if ( ! isset( $by_name[ $key ] ) ) {
			$by_name[ $key ] = array();
		}
		$by_name[ $key ][] = $term;
	}

	return $by_name;
}

/**
 * US state abbreviation → full name map.
 *
 * @return array<string, string>
 */
function extrachill_events_get_state_abbreviation_map(): array {
	return array(
		'AL' => 'Alabama',
		'AK' => 'Alaska',
		'AZ' => 'Arizona',
		'AR' => 'Arkansas',
		'CA' => 'California',
		'CO' => 'Colorado',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',
		'GA' => 'Georgia',
		'HI' => 'Hawaii',
		'ID' => 'Idaho',
		'IL' => 'Illinois',
		'IN' => 'Indiana',
		'IA' => 'Iowa',
		'KS' => 'Kansas',
		'KY' => 'Kentucky',
		'LA' => 'Louisiana',
		'ME' => 'Maine',
		'MD' => 'Maryland',
		'MA' => 'Massachusetts',
		'MI' => 'Michigan',
		'MN' => 'Minnesota',
		'MS' => 'Mississippi',
		'MO' => 'Missouri',
		'MT' => 'Montana',
		'NE' => 'Nebraska',
		'NV' => 'Nevada',
		'NH' => 'New Hampshire',
		'NJ' => 'New Jersey',
		'NM' => 'New Mexico',
		'NY' => 'New York',
		'NC' => 'North Carolina',
		'ND' => 'North Dakota',
		'OH' => 'Ohio',
		'OK' => 'Oklahoma',
		'OR' => 'Oregon',
		'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',
		'SC' => 'South Carolina',
		'SD' => 'South Dakota',
		'TN' => 'Tennessee',
		'TX' => 'Texas',
		'UT' => 'Utah',
		'VT' => 'Vermont',
		'VA' => 'Virginia',
		'WA' => 'Washington',
		'WV' => 'West Virginia',
		'WI' => 'Wisconsin',
		'WY' => 'Wyoming',
	);
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
		'alexandria'          => 'washington-d-c',
		'allentown'           => 'philadelphia',
		'alpharetta'          => 'atlanta',
		'anaheim'             => 'los-angeles',
		'anderson'            => 'greenville',
		'ann arbor'           => 'detroit',
		'annapolis'           => 'washington-d-c',
		'ardmore'             => 'philadelphia',
		'arlington'           => array(
			'virginia' => 'washington-d-c',
			'va'       => 'washington-d-c',
			'texas'    => 'dallas',
			'tx'       => 'dallas',
		),
		'arnoldsville'        => 'athens',
		'aurora'              => array(
			'colorado' => 'denver',
			'co'       => 'denver',
			'illinois' => 'chicago',
			'il'       => 'chicago',
		),
		'belleville'          => array(
			'michigan' => 'detroit',
			'mi'       => 'detroit',
		),
		'bensalem'            => 'philadelphia',
		'berkeley'            => 'oakland',
		'berwyn'              => 'chicago',
		'bethesda'            => 'washington-d-c',
		'bethlehem'           => 'philadelphia',
		'black mountain'      => 'asheville',
		'bloomingdale'        => 'savannah',
		'bristow'             => 'washington-d-c',
		'bronx'               => 'new-york-city',
		'buda'                => 'austin',
		'cambridge'           => 'boston',
		'carrollton'          => array(
			'texas' => 'dallas',
			'tx'    => 'dallas',
		),
		'cedar park'          => 'austin',
		'chandler'            => 'phoenix',
		'cherokee'            => 'asheville',
		'chula vista'         => 'san-diego',
		'clarkston'           => 'detroit',
		'clearwater'          => 'tampa',
		'clemson'             => 'greenville',
		'cleveland heights'   => 'cleveland',
		'columbia'            => array(
			'maryland' => 'baltimore',
			'md'       => 'baltimore',
		),
		'conroe'              => 'houston',
		'costa mesa'          => 'los-angeles',
		'council bluffs'      => 'omaha',
		'covington'           => array(
			'kentucky' => 'cincinnati',
			'ky'       => 'cincinnati',
			'ohio'     => 'dayton',
			'oh'       => 'dayton',
		),
		'cuyahoga falls'      => 'akron',
		'danielsville'        => 'athens',
		'decatur'             => 'atlanta',
		'del mar'             => 'san-diego',
		'duluth'              => 'atlanta',
		'durham'              => 'raleigh',
		'easley'              => 'greenville',
		'el cajon'            => 'san-diego',
		'englewood'           => array(
			'colorado' => 'denver',
			'co'       => 'denver',
		),
		'evanston'            => 'chicago',
		'everett'             => array(
			'massachusetts' => 'boston',
			'ma'            => 'boston',
			'washington'    => 'seattle',
			'wa'            => 'seattle',
		),
		'ferndale'            => 'detroit',
		'folly beach'         => 'charleston',
		'fontana'             => 'los-angeles',
		'forest park'         => 'chicago',
		'fort worth'          => 'dallas',
		'foxboro'             => 'boston',
		'franklin'            => 'nashville',
		'frenchtown'          => 'philadelphia',
		'ft lauderdale'       => 'miami',
		'ft worth'            => 'dallas',
		'garden grove'        => 'los-angeles',
		'georgetown'          => array(
			'texas' => 'austin',
		),
		'germantown'          => 'memphis',
		'gilbert'             => 'phoenix',
		'glen allen'          => 'richmond',
		'glendale'            => 'phoenix',
		'glenside'            => 'philadelphia',
		'granbury'            => 'dallas',
		'grand prairie'       => 'dallas',
		'hamtramck'           => 'detroit',
		'henderson'           => 'las-vegas',
		'highland park'       => array(
			'california' => 'los-angeles',
			'ca'         => 'los-angeles',
			'illinois'   => 'chicago',
			'il'         => 'chicago',
		),
		'hobart'              => 'chicago',
		'hollywood'           => array(
			'california'     => 'los-angeles',
			'florida'        => 'miami',
			'south carolina' => 'charleston',
		),
		'huber heights'       => 'dayton',
		'humble'              => 'houston',
		'inglewood'           => 'los-angeles',
		'irving'              => 'dallas',
		'island lake'         => 'chicago',
		'isle of palms'       => 'charleston',
		'joliet'              => 'chicago',
		'katy'                => 'houston',
		'la vista'            => 'omaha',
		'laguna beach'        => 'los-angeles',
		'lake jackson'        => 'houston',
		'lakewood'            => 'cleveland',
		'leesburg'            => 'washington-d-c',
		'lewiston'            => array(
			'new york' => 'buffalo',
			'ny'       => 'buffalo',
		),
		'libertyville'        => 'chicago',
		'lockhart'            => 'austin',
		'mableton'            => 'atlanta',
		'manhattan'           => 'new-york-city',
		'maryland heights'    => 'st-louis',
		'mckees rocks'        => 'pittsburgh',
		'menlo park'          => 'san-francisco',
		'mesa'                => 'phoenix',
		'metairie'            => 'new-orleans',
		'miami beach'         => 'miami',
		'midlothian'          => array(
			'virginia' => 'richmond',
			'va'       => 'richmond',
			'texas'    => 'dallas',
			'tx'       => 'dallas',
		),
		'midvale'             => 'salt-lake-city',
		'millvale'            => 'pittsburgh',
		'morrison'            => array(
			'colorado' => 'denver',
			'co'       => 'denver',
		),
		'mountain view'       => 'san-jose',
		'murfreesboro'        => 'nashville',
		'napa'                => 'sacramento',
		'new york'            => 'new-york-city',
		'new york city'       => 'new-york-city',
		'newport'             => array(
			'kentucky' => 'cincinnati',
			'ky'       => 'cincinnati',
		),
		'niagara falls'       => 'buffalo',
		'noblesville'         => 'indianapolis',
		'north charleston'    => 'charleston',
		'north little rock'   => 'little-rock',
		'north myrtle beach'  => 'myrtle-beach',
		'northfield'          => array(
			'ohio' => 'akron',
			'oh'   => 'akron',
		),
		'novato'              => 'san-francisco',
		'ontario'             => array(
			'california' => 'los-angeles',
			'ca'         => 'los-angeles',
		),
		'palatine'            => 'chicago',
		'palmdale'            => 'los-angeles',
		'pasadena'            => array(
			'texas'      => 'houston',
			'tx'         => 'houston',
			'california' => 'los-angeles',
			'ca'         => 'los-angeles',
		),
		'pearland'            => 'houston',
		'pflugerville'        => 'austin',
		'pomona'              => 'los-angeles',
		'pontiac'             => array(
			'michigan' => 'detroit',
			'mi'       => 'detroit',
		),
		'port wentworth'      => 'savannah',
		'pottstown'           => 'philadelphia',
		'queens'              => 'queens',
		'rancho cucamonga'    => 'los-angeles',
		'reading'             => 'philadelphia',
		'ridgewood'           => 'queens',
		'riverside'           => array(
			'california' => 'los-angeles',
			'ca'         => 'los-angeles',
		),
		'rosemont'            => 'chicago',
		'roseville'           => array(
			'california' => 'sacramento',
			'ca'         => 'sacramento',
		),
		'round rock'          => 'austin',
		'royal oak'           => 'detroit',
		'saint augustine'     => 'st-augustine',
		'saint louis'         => 'st-louis',
		'saint paul'          => 'st-paul',
		'san juan capistrano' => 'los-angeles',
		'san leandro'         => 'oakland',
		'san marcos'          => 'austin',
		'sandy'               => array(
			'utah' => 'salt-lake-city',
			'ut'   => 'salt-lake-city',
		),
		'santa ana'           => 'los-angeles',
		'saratoga'            => array(
			'california' => 'san-jose',
			'ca'         => 'san-jose',
		),
		'sauget'              => 'st-louis',
		'scottsdale'          => 'phoenix',
		'shakopee'            => 'st-paul',
		'silver spring'       => 'washington-d-c',
		'simpsonville'        => 'greenville',
		'skokie'              => 'chicago',
		'solana beach'        => 'san-diego',
		'somerville'          => array(
			'massachusetts' => 'boston',
			'ma'            => 'boston',
		),
		'spartanburg'         => 'greenville',
		'spring'              => 'houston',
		'st. paul'            => 'minneapolis',
		'staten island'       => 'new-york-city',
		'sterling heights'    => 'detroit',
		'sugar land'          => 'houston',
		'summerville'         => 'charleston',
		'tempe'               => 'phoenix',
		'texas city'          => 'houston',
		'the woodlands'       => 'houston',
		'thousand oaks'       => 'los-angeles',
		'tinley park'         => 'chicago',
		'wake forest'         => 'raleigh',
		'warren'              => array(
			'michigan' => 'detroit',
			'mi'       => 'detroit',
		),
		'washington'          => array(
			'dc'                   => 'washington-d-c',
			'd.c.'                 => 'washington-d-c',
			'district of columbia' => 'washington-d-c',
		),
		'watkinsville'        => 'athens',
		'waukee'              => 'des-moines',
		'waukegan'            => 'chicago',
		'west columbia'       => array(
			'south carolina' => 'columbia',
			'sc'             => 'columbia',
		),
		'west des moines'     => 'des-moines',
		'west hollywood'      => 'los-angeles',
		'west valley city'    => 'salt-lake-city',
		'westland'            => 'detroit',
		'wilmington'          => 'philadelphia',
		'wilton manors'       => 'fort-lauderdale',
		'windsor'             => array(
			'michigan' => 'detroit',
			'mi'       => 'detroit',
		),
		'winter park'         => 'orlando',
		'winterville'         => 'athens',
		'woodinville'         => 'seattle',
		'worcester'           => 'boston',
		'wyandotte'           => 'detroit',
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
