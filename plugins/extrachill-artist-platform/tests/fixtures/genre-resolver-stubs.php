<?php
/**
 * Minimal stand-in for the extrachill-network genre resolver (issue #191).
 *
 * Vocabulary and alias shape mirror the upstream contract so plugin tests can
 * exercise genre resolution standalone. The plugin guards every resolver call
 * with function_exists(), so tests that do NOT load this file cover the
 * fail-closed path (see ArtistGenresFailClosedTest).
 *
 * Loaded via require_once by the test files that need resolution.
 *
 * @package ExtraChillArtistPlatform
 */

$GLOBALS['ec_test_genre_vocabulary'] = array(
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

$GLOBALS['ec_test_genre_aliases'] = array(
	'rap'               => 'hip-hop',
	'hiphop'            => 'hip-hop',
	'hip hop'           => 'hip-hop',
	'r-b'               => 'rnb',
	'rhythm and blues'  => 'rnb',
	'edm'               => 'electronic',
	'jamband'           => 'jam',
	'singer songwriter' => 'singer-songwriter',
	'songwriter'        => 'singer-songwriter',
);

/**
 * Resolve one value (slug, name, or alias) to a canonical slug or ''.
 *
 * @param string $value Raw value.
 * @return string
 */
function ec_test_resolve_genre_value( $value ) {
	$vocabulary = $GLOBALS['ec_test_genre_vocabulary'];
	$aliases    = $GLOBALS['ec_test_genre_aliases'];
	$value      = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$needle = strtolower( $value );
	if ( isset( $aliases[ $needle ] ) ) {
		return $aliases[ $needle ];
	}
	$slug = sanitize_title( $value );
	if ( isset( $vocabulary[ $slug ] ) ) {
		return $slug;
	}
	if ( isset( $aliases[ $slug ] ) ) {
		return $aliases[ $slug ];
	}
	foreach ( $vocabulary as $term_slug => $name ) {
		if ( strtolower( $name ) === $needle ) {
			return $term_slug;
		}
	}
	return '';
}

/**
 * Resolve one value to a canonical genre slug (network contract #191).
 *
 * @param string $value Raw value.
 * @return string Canonical slug or '' when unresolvable.
 */
function extrachill_network_resolve_genre( $value ) {
	return ec_test_resolve_genre_value( $value );
}

/**
 * Resolve a multi-genre string to canonical slugs, capped and de-duped.
 *
 * @param string $value Raw multi-genre string.
 * @param int    $cap   Maximum number of slugs.
 * @return string[]
 */
function extrachill_network_resolve_genres( $value, $cap = 3 ) {
	$parts = preg_split( '/[,\/|&+]| and /i', (string) $value );
	$slugs = array();
	foreach ( (array) $parts as $part ) {
		$slug = ec_test_resolve_genre_value( $part );
		if ( '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
			$slugs[] = $slug;
		}
		if ( count( $slugs ) >= $cap ) {
			break;
		}
	}
	return array_slice( $slugs, 0, $cap );
}
