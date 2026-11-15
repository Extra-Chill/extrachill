<?php
/**
 * Function doubles for the standalone user artists route smoke.
 *
 * ec_get_artist_identity() serves records seeded in
 * $GLOBALS['ec_test_identities'], keyed by profile ID. switch_to_blog()
 * counts calls so the smoke can prove the route no longer switches blogs.
 *
 * @package ExtraChillAPI
 */

/**
 * Identity double.
 *
 * @param array $by Lookup.
 * @return array|null
 */
function ec_get_artist_identity( array $by ) {
	$profile_id = $by['profile_id'] ?? 0;
	if ( array( 'profile_id' ) !== array_keys( $by ) || ! is_int( $profile_id ) || 0 >= $profile_id ) {
		return null;
	}
	return $GLOBALS['ec_test_identities'][ $profile_id ] ?? null;
}

/**
 * Membership double.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function ec_get_artists_for_user( $user_id ) {
	return $GLOBALS['ec_test_rosters'][ (int) $user_id ] ?? array();
}

/**
 * Membership write double.
 *
 * @param int $user_id   User ID.
 * @param int $artist_id Artist profile ID.
 * @return bool
 */
function ec_add_artist_membership( $user_id, $artist_id ) {
	$GLOBALS['ec_test_added'][] = array( (int) $user_id, (int) $artist_id );
	return true;
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
 * @return int
 */
function ec_get_blog_id() {
	return 4;
}

/**
 * REST response double.
 *
 * @param mixed $value Response data.
 * @return mixed
 */
function rest_ensure_response( $value ) {
	return $value;
}

/**
 * Hook registration double.
 *
 * @return void
 */
function add_action() {}
