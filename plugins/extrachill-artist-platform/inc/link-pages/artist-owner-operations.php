<?php
/**
 * Artist Platform operation provider for Link Pages.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the canonical owner reference for an artist profile.
 *
 * @param int $artist_id Artist profile post ID.
 * @return string|WP_Error
 */
function ec_artist_link_page_owner_reference( $artist_id ) {
	$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();

	return ec_normalize_link_page_owner_reference(
		array(
			'kind'      => 'post',
			'blog_id'   => $blog_id,
			'subtype'   => 'artist_profile',
			'object_id' => absint( $artist_id ),
		)
	);
}

/**
 * Return the current Artist Platform callbacks for a supported owner.
 *
 * @param array $resolved Resolved operation target.
 * @return array|null
 */
function ec_artist_link_page_operation_provider( $resolved ) {
	$owner          = $resolved['owner'];
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	if ( 'post' !== $owner['kind'] || $artist_blog_id !== $owner['blog_id'] || 'artist_profile' !== $owner['subtype'] ) {
		return null;
	}

	return array(
		'authorize' => 'ec_artist_link_page_operation_authorize',
		'read'      => 'ec_artist_link_page_operation_read',
		'save'      => 'ec_artist_link_page_operation_save',
	);
}

/**
 * Apply current Artist Platform membership and capability policy.
 *
 * @param array  $resolved  Resolved operation target.
 * @param string $operation Operation name.
 * @return bool
 */
function ec_artist_link_page_operation_authorize( $resolved, $operation ) {
	if ( ! in_array( $operation, array( 'read', 'save' ), true ) ) {
		return false;
	}

	return extrachill_artist_platform_ability_artist_permission(
		array( 'artist_id' => (int) $resolved['owner']['object_id'] )
	);
}

/**
 * Read through the existing Artist Platform projection.
 *
 * @param array $resolved Resolved operation target.
 * @return array
 */
function ec_artist_link_page_operation_read( $resolved ) {
	return ec_get_link_page_data( (int) $resolved['owner']['object_id'], (int) $resolved['link_page_id'] );
}

/**
 * Save through the existing Artist Platform persistence and projection.
 *
 * @param array $resolved Resolved operation target.
 * @param array $data     Prepared save data.
 * @return array|WP_Error
 */
function ec_artist_link_page_operation_save( $resolved, $data ) {
	$result = ec_handle_link_page_save( (int) $resolved['link_page_id'], $data );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return ec_artist_link_page_operation_read( $resolved );
}

/**
 * Execute an Artist mutation under the exact associated Link Page lock.
 *
 * Standalone supplies the advisory scope. Rolling fallback uses an equivalent
 * request-local guard so current production does not gain a hard dependency.
 *
 * @param int      $artist_id        Artist profile ID.
 * @param callable $callback         Mutation callback receiving the Link Page ID.
 * @param bool     $require_link_page Whether absence should retain the historical error.
 * @return mixed|WP_Error
 */
function ec_artist_with_link_page_lock( $artist_id, $callback, $require_link_page = false ) {
	if ( ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_artist_link_page_callback', 'The Artist Link Page mutation callback is invalid.' );
	}
	if ( function_exists( 'ec_with_link_page_storage_blog' ) && function_exists( 'ec_get_link_page_storage_blog_id' ) ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $artist_id, $callback, $require_link_page ) {
				return ec_artist_with_link_page_lock_on_storage( $artist_id, $callback, $require_link_page );
			}
		);
	}
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	$did_switch     = $artist_blog_id && get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch && ( ! switch_to_blog( $artist_blog_id ) || get_current_blog_id() !== $artist_blog_id ) ) {
		return new WP_Error( 'artist_blog_switch_failed', 'The Artist mutation could not enter the Artist site.' );
	}
	try {
		return ec_artist_with_link_page_lock_on_storage( $artist_id, $callback, $require_link_page );
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}
}

/**
 * Resolve, lock, revalidate, and mutate from canonical storage context.
 *
 * @param int      $artist_id        Artist profile ID.
 * @param callable $callback         Mutation callback.
 * @param bool     $require_link_page Whether the Link Page is required.
 * @return mixed|WP_Error
 */
function ec_artist_with_link_page_lock_on_storage( $artist_id, $callback, $require_link_page = false ) {
	if ( ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_artist_link_page_callback', 'The Artist Link Page mutation callback is invalid.' );
	}
	$artist_id    = absint( $artist_id );
	$link_page_id = 0;
	$reference    = function_exists( 'ec_artist_link_page_owner_reference' ) ? ec_artist_link_page_owner_reference( $artist_id ) : null;
	if ( ! is_wp_error( $reference ) && $reference && function_exists( 'ec_get_link_page_id_for_owner' ) ) {
		$resolved = ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$link_page_id = (int) $resolved;
	} elseif ( function_exists( 'ec_get_reciprocal_link_page_id' ) ) {
		$resolved = ec_get_reciprocal_link_page_id( $artist_id, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$link_page_id = (int) $resolved;
	} elseif ( function_exists( 'ec_get_link_page_for_artist' ) ) {
		$link_page_id = (int) ec_get_link_page_for_artist( $artist_id );
	}
	if ( $require_link_page && ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}
	$locked_callback = static function () use ( $artist_id, $link_page_id, $reference, $callback ) {
		if ( $link_page_id ) {
			if ( (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true ) !== $artist_id ) {
				return new WP_Error( 'artist_link_page_owner_mismatch', 'The Link Page no longer belongs to this artist.' );
			}
			if ( $reference && ! is_wp_error( $reference ) && function_exists( 'ec_get_link_page_owner' ) ) {
				$owner = ec_get_link_page_owner( $link_page_id );
				if ( is_wp_error( $owner ) || $owner['reference'] !== $reference ) {
					return new WP_Error( 'artist_link_page_owner_mismatch', 'The canonical Link Page owner changed before the mutation.' );
				}
			}
		}
		return call_user_func( $callback, $link_page_id );
	};
	if ( $link_page_id && function_exists( 'ec_with_link_page_lock_scope' ) ) {
		return ec_with_link_page_lock_scope( $link_page_id, $locked_callback, 'separate' );
	}
	$scope = $GLOBALS['ec_artist_link_page_local_lock'] ?? null;
	$key   = $artist_id . ':' . $link_page_id;
	if ( $scope && $scope !== $key ) {
		return new WP_Error( 'link_page_lock_scope_conflict', 'A different Artist Link Page mutation is already active.' );
	}
	$outer = ! $scope;
	if ( $outer ) {
		$GLOBALS['ec_artist_link_page_local_lock'] = $key;
	}
	try {
		return $locked_callback();
	} finally {
		if ( $outer ) {
			unset( $GLOBALS['ec_artist_link_page_local_lock'] );
		}
	}
}

/**
 * Complete a successful owner-only mutation that changes public Link Page output.
 *
 * @param int $artist_id Artist profile ID.
 * @return bool
 */
function ec_artist_link_page_complete_owner_save( $artist_id ) {
	$result = ec_artist_with_link_page_lock(
		$artist_id,
		static function ( $link_page_id ) {
			if ( ! $link_page_id ) {
				return false;
			}
			do_action( 'ec_link_page_save', $link_page_id );
			return true;
		}
	);
	return ! is_wp_error( $result ) && true === $result;
}
