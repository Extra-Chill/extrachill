<?php
/**
 * Standalone coverage of the users consumers of ec_get_artist_identity().
 *
 * Proves membership, management and seller reads keep their publication
 * policy and record shape without switching to the artist blog.
 *
 * @package ExtraChill\Users
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/support/artist-identity-wp-error.php';
require_once __DIR__ . '/support/artist-identity-team-repository.php';
require_once __DIR__ . '/support/artist-identity-fixture.php';
require_once dirname( __DIR__ ) . '/inc/artist-profiles.php';
require_once dirname( __DIR__ ) . '/inc/shop-sellers.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-user-artists.php';

$GLOBALS['ec_test_failures'] = 0;

/**
 * Report one assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $label     Description.
 * @return void
 */
function ec_users_identity_assert( $condition, $label ) {
	if ( $condition ) {
		echo 'PASS: ' . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		return;
	}
	++$GLOBALS['ec_test_failures'];
	echo 'FAIL: ' . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
}

ec_users_test_seed_identity( 10, 'The Band', 'publish', 55, 'https://artist.test/thumb.jpg' );
ec_users_test_seed_identity( 11, 'Draft Band', 'draft' );
ec_users_test_seed_identity( 12, 'Broken Image', 'publish', 66, '' );
ec_users_test_seed_identity( 13, 'No Image', 'publish' );

// Profile 14 does not exist; 0 and -3 are invalid roster entries.
$GLOBALS['ec_test_rosters'] = array(
	7 => array( 10, 11, 12, 13, 14, 0, -3 ),
	8 => array( 11 ),
);

$GLOBALS['ec_test_switches'] = array();

ec_users_identity_assert( array( 10, 12, 13 ) === ec_get_artists_for_user( 7 ), 'memberships keep only existing published profiles, absint-normalised' );
ec_users_identity_assert( array() === ec_get_artists_for_user( 8 ), 'a draft-only roster yields no artists' );
ec_users_identity_assert( array() === ec_get_artists_for_user( 0 ), 'no user yields no artists' );

ec_users_identity_assert( true === ec_can_manage_artist( 7, 10 ), 'a member manages a published profile' );
ec_users_identity_assert( false === ec_can_manage_artist( 7, 11 ), 'a member cannot manage a draft profile' );
ec_users_identity_assert( false === ec_can_manage_artist( 7, 14 ), 'a missing profile cannot be managed' );
ec_users_identity_assert( false === ec_can_manage_artist( 8, 10 ), 'a non-member cannot manage a published profile' );
ec_users_identity_assert( true === ec_can_manage_artist( 1, 13 ), 'an administrator manages any published profile' );
ec_users_identity_assert( false === ec_can_manage_artist( 1, 11 ), 'an administrator cannot manage a draft profile' );

$sellers = ec_get_manageable_shop_artist_sellers( 7 );
ec_users_identity_assert(
	array(
		array(
			'type'       => 'artist',
			'id'         => 10,
			'name'       => 'The Band',
			'manage_url' => 'https://artist.test/manage-artist/',
		),
		array(
			'type'       => 'artist',
			'id'         => 12,
			'name'       => 'Broken Image',
			'manage_url' => 'https://artist.test/manage-artist/',
		),
		array(
			'type'       => 'artist',
			'id'         => 13,
			'name'       => 'No Image',
			'manage_url' => 'https://artist.test/manage-artist/',
		),
	) === $sellers,
	'artist sellers carry the published profile names'
);

$GLOBALS['ec_test_current_user'] = 7;

$artists = extrachill_users_ability_get_user_artists( array( 'user_id' => 7 ) );
ec_users_identity_assert(
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
		array(
			'id'                => 13,
			'name'              => 'No Image',
			'slug'              => 'no-image',
			'profile_image_url' => null,
		),
	) === $artists,
	'the ability keeps the record shape and image null/false semantics'
);

$denied = extrachill_users_ability_get_user_artists( array( 'user_id' => 8 ) );
ec_users_identity_assert( $denied instanceof WP_Error && 'rest_forbidden' === $denied->code, 'the ability still refuses other users' );

ec_users_identity_assert( array() === $GLOBALS['ec_test_switches'], 'no consumer switched blogs' );

foreach ( $GLOBALS['ec_test_identity_reads'] as $lookup ) {
	if ( array( 'profile_id' ) !== array_keys( $lookup ) || ! is_int( $lookup['profile_id'] ) || 0 >= $lookup['profile_id'] ) {
		ec_users_identity_assert( false, 'identity lookups pass one positive integer profile_id' );
		exit( 1 );
	}
}
ec_users_identity_assert( true, 'identity lookups pass one positive integer profile_id' );

exit( 0 === $GLOBALS['ec_test_failures'] ? 0 : 1 );
