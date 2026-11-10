<?php
/**
 * Canonical artist membership primitives backed by the network team store.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Add an active artist member. Repeated calls are idempotent. */
function ec_add_artist_membership( $user_id, $artist_id ) {
	ec_set_artist_membership_failure();
	$user_id   = absint( $user_id );
	$artist_id = absint( $artist_id );
	if ( ! $user_id || ! $artist_id || ! get_userdata( $user_id ) ) {
		ec_set_artist_membership_failure( 'invalid_artist_membership', __( 'The artist membership target is invalid.', 'extrachill-artist-platform' ), array( 'status' => 400, 'retryable' => false, 'partial_state_created' => false ) );
		return false;
	}
	$blog_id = ec_get_blog_id( 'artist' );
	if ( ! $blog_id ) {
		ec_set_artist_membership_failure( 'artist_site_unavailable', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ), array( 'status' => 503, 'retryable' => true, 'partial_state_created' => false ) );
		return false;
	}
	switch_to_blog( $blog_id );
	try {
		$valid = 'artist_profile' === get_post_type( $artist_id ) && 'publish' === get_post_status( $artist_id );
	} finally {
		restore_current_blog();
	}
	if ( ! $valid ) {
		ec_set_artist_membership_failure( 'invalid_artist_profile', __( 'The artist profile is unavailable.', 'extrachill-artist-platform' ), array( 'status' => 404, 'retryable' => false, 'partial_state_created' => false ) );
		return false;
	}
	return ec_write_artist_team_membership( $user_id, $artist_id, true );
}

/** Revoke an artist member. Missing or already revoked pairs are successful. */
function ec_remove_artist_membership( $user_id, $artist_id ) {
	ec_set_artist_membership_failure();
	$user_id   = absint( $user_id );
	$artist_id = absint( $artist_id );
	if ( ! $user_id || ! $artist_id ) {
		ec_set_artist_membership_failure( 'invalid_artist_membership', __( 'The artist membership target is invalid.', 'extrachill-artist-platform' ), array( 'status' => 400, 'retryable' => false ) );
		return false;
	}
	if ( ! ec_get_blog_id( 'artist' ) ) {
		ec_set_artist_membership_failure( 'artist_site_unavailable', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ), array( 'status' => 503, 'retryable' => true ) );
		return false;
	}
	return ec_write_artist_team_membership( $user_id, $artist_id, false );
}

/** Persist a single-row transition with optimistic conflict retries. */
function ec_write_artist_team_membership( $user_id, $artist_id, $add ) {
	if ( class_exists( '\ExtraChill\Users\Teams\TeamMembershipRepository' ) ) {
		$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$row = $repository->get( 'artist', $artist_id, $user_id );
			if ( is_wp_error( $row ) ) {
				break;
			}
			if ( ! $row ) {
				if ( ! $add || $repository->insert( 'artist', $repository->new_row( 'artist', $artist_id, $user_id, true, get_current_user_id() ) ) ) {
					return true;
				}
				// A competing insert may have won the unique key. Read it again.
				continue;
			}
			if ( ( $add ? 'active' : 'revoked' ) === $row['status'] ) {
				return true;
			}
			if ( $add ) {
				$result = $repository->update( 'artist', $artist_id, $user_id, $row['version'], array(
					'status' => 'active', 'is_owner' => 1, 'version' => $row['version'] + 1,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_at' => null, 'revoked_by_user_id' => null,
				) );
			} else {
				$result = $repository->mutate( 'artist', $artist_id, $user_id, $row['version'], null, true, get_current_user_id() );
			}
			if ( 1 === $result ) {
				return true;
			}
			if ( false === $result ) {
				break;
			}
		}
	}
	ec_set_artist_membership_failure( $add ? 'artist_roster_update_failed' : 'artist_membership_remove_failed', __( 'The artist membership could not be updated.', 'extrachill-artist-platform' ), array( 'status' => 503, 'retryable' => true, 'partial_state_created' => false ) );
	return false;
}

/** Store or clear the last actionable membership failure for this request. */
function ec_set_artist_membership_failure( $code = '', $message = '', $data = array() ) {
	$GLOBALS['ec_artist_membership_failure'] = $code ? new WP_Error( $code, $message, $data ) : null;
}

/** Get the last actionable membership failure. */
function ec_get_artist_membership_failure() {
	$failure = $GLOBALS['ec_artist_membership_failure'] ?? null;
	return $failure instanceof WP_Error ? $failure : null;
}

/** Return WP_User objects for active members of a published artist profile. */
function ec_get_linked_members( $artist_profile_id ) {
	$artist_profile_id = absint( $artist_profile_id );
	$blog_id           = ec_get_blog_id( 'artist' );
	if ( ! $artist_profile_id || ! $blog_id || ! class_exists( '\ExtraChill\Users\Teams\TeamMembershipRepository' ) ) {
		return array();
	}
	switch_to_blog( $blog_id );
	try {
		$valid = 'artist_profile' === get_post_type( $artist_profile_id ) && 'publish' === get_post_status( $artist_profile_id );
	} finally {
		restore_current_blog();
	}
	if ( ! $valid ) {
		return array();
	}
	$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
	$rows       = $repository->select( 'artist', array( 'term' => $artist_profile_id, 'status' => 'active' ), 'id ASC' );
	return array_values( array_filter( array_map( 'get_userdata', array_column( $rows, 'user_id' ) ) ) );
}
