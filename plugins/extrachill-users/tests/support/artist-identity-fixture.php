<?php
/**
 * Function doubles for the standalone artist identity consumer smoke.
 *
 * ec_get_artist_identity() serves records seeded in
 * $GLOBALS['ec_test_identities'], keyed by profile ID, and counts reads.
 * switch_to_blog() counts calls so the smoke can prove consumers no longer
 * switch to the artist blog.
 *
 * @package ExtraChill\Users
 */

/**
 * Seed an artist identity record.
 *
 * @param int    $profile_id   Profile ID.
 * @param string $name         Profile title.
 * @param string $status       Post status.
 * @param int    $thumbnail_id Featured image ID.
 * @param string $thumb_url    Featured image URL.
 * @return void
 */
function ec_users_test_seed_identity( $profile_id, $name, $status = 'publish', $thumbnail_id = 0, $thumb_url = '' ) {
	$GLOBALS['ec_test_identities'][ $profile_id ] = array(
		'profile_id'    => $profile_id,
		'term_id'       => $profile_id + 1000,
		'slug'          => strtolower( str_replace( ' ', '-', $name ) ),
		'name'          => $name,
		'status'        => $status,
		'permalink'     => 'https://artist.test/artists/' . strtolower( str_replace( ' ', '-', $name ) ),
		'thumbnail_id'  => $thumbnail_id,
		'thumbnail_url' => $thumb_url,
		'term_slug'     => strtolower( str_replace( ' ', '-', $name ) ),
		'term_name'     => $name,
		'binding_valid' => true,
	);
}

/**
 * Identity double.
 *
 * @param array $by Lookup.
 * @return array|null
 */
function ec_get_artist_identity( array $by ) {
	$GLOBALS['ec_test_identity_reads'][] = $by;
	$profile_id                          = $by['profile_id'] ?? 0;
	if ( ! is_int( $profile_id ) || 0 >= $profile_id ) {
		return null;
	}
	return $GLOBALS['ec_test_identities'][ $profile_id ] ?? null;
}

/**
 * Blog switch double.
 *
 * @param int $blog_id Blog ID.
 * @return bool
 */
function switch_to_blog( $blog_id ) {
	$GLOBALS['ec_test_switches'][] = (int) $blog_id;
	return true;
}

/**
 * Blog restore double.
 *
 * @return bool
 */
function restore_current_blog() {
	return true;
}

/**
 * Blog ID double.
 *
 * @param string $key Site key.
 * @return int
 */
function ec_get_blog_id( $key ) {
	return 'artist' === $key ? 4 : 1;
}

/**
 * Site URL double.
 *
 * @param string $key Site key.
 * @return string
 */
function ec_get_site_url( $key ) {
	return 'https://' . $key . '.test';
}

/**
 * Trailing slash double.
 *
 * @param string $value URL.
 * @return string
 */
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}

/**
 * Current user double.
 *
 * @return int
 */
function get_current_user_id() {
	return (int) ( $GLOBALS['ec_test_current_user'] ?? 0 );
}

/**
 * Capability double: user 1 is the only administrator.
 *
 * @param int    $user_id    User ID.
 * @param string $capability Capability.
 * @return bool
 */
function user_can( $user_id, $capability ) {
	return 1 === (int) $user_id && 'manage_options' === $capability;
}

/**
 * Current user capability double.
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return user_can( get_current_user_id(), $capability );
}

/**
 * User lookup double.
 *
 * @param int $user_id User ID.
 * @return object|false
 */
function get_userdata( $user_id ) {
	return 0 < (int) $user_id ? (object) array( 'ID' => (int) $user_id ) : false;
}

/**
 * Absint double.
 *
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Hook registration double.
 *
 * @return void
 */
function add_action() {}

/**
 * WP_Error check double.
 *
 * @param mixed $value Value.
 * @return bool
 */
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
