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
	$blog_id = (int) ec_get_blog_id( 'artist' );

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
	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
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
 * @param int      $artist_id        Artist profile ID.
 * @param callable $callback         Mutation callback receiving the Link Page ID.
 * @param bool     $require_link_page Whether absence should retain the historical error.
 * @return mixed|WP_Error
 */
function ec_artist_with_link_page_lock( $artist_id, $callback, $require_link_page = false ) {
	// @phpstan-ignore phpstan.function.alreadyNarrowedType (defensive: the callback arrives through public wrappers and is verified at runtime.)
	if ( ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_artist_link_page_callback', 'The Artist Link Page mutation callback is invalid.' );
	}
	return ec_with_link_page_storage_blog(
		static function () use ( $artist_id, $callback, $require_link_page ) {
			return ec_artist_with_link_page_lock_on_storage( $artist_id, $callback, $require_link_page );
		}
	);
}

/**
 * Resolve, lock, revalidate, and mutate from canonical storage context.
 *
 * The owner reference is the only binding checked before and inside the lock.
 *
 * @param int      $artist_id        Artist profile ID.
 * @param callable $callback         Mutation callback.
 * @param bool     $require_link_page Whether the Link Page is required.
 * @return mixed|WP_Error
 */
function ec_artist_with_link_page_lock_on_storage( $artist_id, $callback, $require_link_page = false ) {
	// @phpstan-ignore phpstan.function.alreadyNarrowedType (defensive: the callback arrives through public wrappers and is verified at runtime.)
	if ( ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_artist_link_page_callback', 'The Artist Link Page mutation callback is invalid.' );
	}
	$artist_id = absint( $artist_id );
	$reference = ec_artist_link_page_owner_reference( $artist_id );
	if ( is_wp_error( $reference ) ) {
		return $reference;
	}
	$link_page_id = ec_get_link_page_id_for_owner( $reference );
	if ( is_wp_error( $link_page_id ) ) {
		return $link_page_id;
	}
	$link_page_id = (int) $link_page_id;
	if ( ! $link_page_id ) {
		return $require_link_page ? new WP_Error( 'no_link_page', 'No link page exists for this artist.' ) : call_user_func( $callback, 0 );
	}
	return ec_with_link_page_lock_scope(
		$link_page_id,
		static function () use ( $link_page_id, $reference, $callback ) {
			$owner = ec_get_link_page_owner( $link_page_id );
			if ( is_wp_error( $owner ) || $owner['reference'] !== $reference ) {
				return new WP_Error( 'artist_link_page_owner_mismatch', 'The canonical Link Page owner changed before the mutation.' );
			}
			return call_user_func( $callback, $link_page_id );
		},
		'separate'
	);
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
