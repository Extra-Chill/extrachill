<?php
/**
 * Standalone coverage of the user artists route reading ec_get_artist_identity().
 *
 * @package ExtraChillAPI
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/support/artist-identity-wp-error.php';
require_once __DIR__ . '/support/artist-identity-rest-request.php';
require_once __DIR__ . '/support/artist-identity-fixture.php';
require_once dirname( __DIR__ ) . '/inc/routes/users/artists.php';

$GLOBALS['ec_test_failures'] = 0;

/**
 * Report one assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $label     Description.
 * @return void
 */
function ec_api_identity_assert( $condition, $label ) {
	if ( $condition ) {
		echo 'PASS: ' . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		return;
	}
	++$GLOBALS['ec_test_failures'];
	echo 'FAIL: ' . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
}

/**
 * Build an identity record.
 *
 * @param int    $profile_id   Profile ID.
 * @param string $slug         Profile slug.
 * @param string $status       Post status.
 * @param int    $thumbnail_id Featured image ID.
 * @param string $thumb_url    Featured image URL.
 * @return array
 */
function ec_api_identity_record( $profile_id, $slug, $status, $thumbnail_id, $thumb_url ) {
	return array(
		'profile_id'    => $profile_id,
		'term_id'       => $profile_id + 1000,
		'slug'          => $slug,
		'name'          => ucwords( str_replace( '-', ' ', $slug ) ),
		'status'        => $status,
		'permalink'     => 'https://artist.test/artists/' . $slug,
		'thumbnail_id'  => $thumbnail_id,
		'thumbnail_url' => $thumb_url,
		'term_slug'     => $slug,
		'term_name'     => $slug,
		'binding_valid' => true,
	);
}

$GLOBALS['ec_test_identities'] = array(
	10 => ec_api_identity_record( 10, 'the-band', 'publish', 55, 'https://artist.test/thumb.jpg' ),
	11 => ec_api_identity_record( 11, 'draft-band', 'draft', 0, '' ),
	12 => ec_api_identity_record( 12, 'broken-image', 'publish', 66, '' ),
);
$GLOBALS['ec_test_rosters']    = array( 7 => array( 10, 12, 14 ) );
$GLOBALS['ec_test_switches']   = array();
$GLOBALS['ec_test_added']      = array();

$listed = extrachill_api_user_artists_get_handler( new WP_REST_Request( array( 'id' => 7 ) ) );
ec_api_identity_assert(
	array(
		array(
			'id'                => 10,
			'name'              => 'The Band',
			'slug'              => 'the-band',
			'profile_image_url' => 'https://artist.test/thumb.jpg',
		),
		array(
			'id'                => 12,
			'name'              => 'Broken Image',
			'slug'              => 'broken-image',
			'profile_image_url' => false,
		),
	) === $listed,
	'GET lists existing profiles with the original image null/false semantics'
);

$added = extrachill_api_user_artists_post_handler(
	new WP_REST_Request(
		array(
			'id'        => 7,
			'artist_id' => 11,
		)
	)
);
ec_api_identity_assert( is_array( $added ) && true === $added['success'] && array( array( 7, 11 ) ) === $GLOBALS['ec_test_added'], 'POST accepts an existing profile in any status' );

$missing = extrachill_api_user_artists_post_handler(
	new WP_REST_Request(
		array(
			'id'        => 7,
			'artist_id' => 14,
		)
	)
);
ec_api_identity_assert( $missing instanceof WP_Error && 'invalid_artist' === $missing->code && 1 === count( $GLOBALS['ec_test_added'] ), 'POST rejects a missing profile before writing' );

ec_api_identity_assert( array() === $GLOBALS['ec_test_switches'], 'the route never switched blogs' );

exit( 0 === $GLOBALS['ec_test_failures'] ? 0 : 1 );
