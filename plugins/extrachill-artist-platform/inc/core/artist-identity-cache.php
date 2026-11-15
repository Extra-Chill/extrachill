<?php
/**
 * Artist identity cache invalidation for artist profile lifecycle events.
 *
 * Extra Chill Network owns the cached identity read, ec_get_artist_identity().
 * Artist Platform owns the artist_profile post, so it forgets the cached
 * identity whenever the profile's name, slug, status, existence or image
 * changes. Binding mutations forget it in artist-term-binding.php, and the
 * network forgets it for main-blog term changes.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forget the cached identity of one artist profile.
 *
 * Main-blog term records that point at the profile are retired by the
 * network's generation bump, so the term ID is not needed here.
 *
 * @param int $profile_id Artist profile post ID.
 * @return void
 */
function ec_artist_platform_forget_profile_identity( $profile_id ) {
	$profile_id = (int) $profile_id;
	if ( 0 >= $profile_id || ! function_exists( 'ec_forget_artist_identity' ) ) {
		return;
	}
	ec_forget_artist_identity( $profile_id, 0 );
}

/**
 * Forget the identity after an artist profile is saved.
 *
 * Covers title, slug and content edits made through wp_insert_post() and
 * wp_update_post().
 *
 * @param int $post_id Artist profile post ID.
 * @return void
 */
function ec_artist_platform_identity_on_save( $post_id ) {
	ec_artist_platform_forget_profile_identity( (int) $post_id );
}
add_action( 'save_post_artist_profile', 'ec_artist_platform_identity_on_save', 10, 1 );

/**
 * Forget the identity when an artist profile changes status.
 *
 * Covers status changes that skip save_post, such as wp_publish_post() and
 * scheduled publishing.
 *
 * @param string       $new_status New post status.
 * @param string       $old_status Previous post status.
 * @param WP_Post|null $post       Post object.
 * @return void
 */
function ec_artist_platform_identity_on_status_transition( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type ) {
		return;
	}
	ec_artist_platform_forget_profile_identity( (int) $post->ID );
}
add_action( 'transition_post_status', 'ec_artist_platform_identity_on_status_transition', 10, 3 );

/**
 * Forget the identity after an artist profile is deleted.
 *
 * @param int          $post_id Deleted post ID.
 * @param WP_Post|null $post    Deleted post object.
 * @return void
 */
function ec_artist_platform_identity_on_delete( $post_id, $post ) {
	if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type ) {
		return;
	}
	ec_artist_platform_forget_profile_identity( (int) $post_id );
}
add_action( 'deleted_post', 'ec_artist_platform_identity_on_delete', 10, 2 );

/**
 * Forget the identity when an artist profile's featured image changes.
 *
 * The network also listens for `_thumbnail_id` writes. This keeps the owner's
 * contract explicit when the network hook is unavailable.
 *
 * @param int|int[] $meta_ids  Meta ID(s). Unused.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @return void
 */
function ec_artist_platform_identity_on_thumbnail_change( $meta_ids, $object_id, $meta_key ) {
	unset( $meta_ids );
	if ( '_thumbnail_id' !== $meta_key || 'artist_profile' !== get_post_type( (int) $object_id ) ) {
		return;
	}
	ec_artist_platform_forget_profile_identity( (int) $object_id );
}
add_action( 'added_post_meta', 'ec_artist_platform_identity_on_thumbnail_change', 10, 3 );
add_action( 'updated_post_meta', 'ec_artist_platform_identity_on_thumbnail_change', 10, 3 );
add_action( 'deleted_post_meta', 'ec_artist_platform_identity_on_thumbnail_change', 10, 3 );
