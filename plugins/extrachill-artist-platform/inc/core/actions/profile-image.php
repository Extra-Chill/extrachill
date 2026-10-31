<?php
/**
 * Blog-qualified profile image materialization.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Run a callback on a blog, always restoring the caller's context. */
function ec_artist_with_image_blog( $blog_id, $callback ) {
	$switched = (int) $blog_id !== get_current_blog_id();
	if ( $switched ) {
		switch_to_blog( $blog_id );
	}
	try {
		return $callback();
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Resolve/copy a source image into the current blog. Never reuse a bare ID.
 * Existing migration copies are adopted only after verifying file contents.
 * With $create=false this is a read-only identity comparison for backfills.
 *
 * @return int|WP_Error Local attachment ID (zero if absent in read-only mode).
 */
function ec_artist_materialize_profile_image( $source_blog, $source_id, $candidate_id = 0, $create = true ) {
	$source_id = absint( $source_id );
	if ( ! $source_id ) {
		return 0;
	}
	$source = ec_artist_with_image_blog(
		$source_blog,
		static function () use ( $source_id ) {
			if ( ! wp_attachment_is_image( $source_id ) ) {
				return new WP_Error( 'invalid_profile_image', 'The source profile image is not an image attachment.' );
			}
			return array(
				'file'   => get_attached_file( $source_id ),
				'title'  => get_the_title( $source_id ),
				'alt'    => get_post_meta( $source_id, '_wp_attachment_image_alt', true ),
				'origin' => get_post_meta( $source_id, '_ec_source_attachment', true ),
			);
		}
	);
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	if ( (int) $source_blog === get_current_blog_id() ) {
		return $source_id;
	}
	$key = (int) $source_blog . ':' . $source_id;
	// A copy sent back to its originating blog must resolve to the original.
	$origin = explode( ':', (string) $source['origin'] );
	if ( 2 === count( $origin ) && (int) $origin[0] === get_current_blog_id() && wp_attachment_is_image( (int) $origin[1] ) ) {
		return (int) $origin[1];
	}
	$matches = get_posts( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids',
		'posts_per_page' => 1, 'meta_key' => '_ec_source_attachment', 'meta_value' => $key,
	) );
	if ( $matches && wp_attachment_is_image( $matches[0] ) ) {
		return (int) $matches[0];
	}
	// Migration preserved IDs, but an equal ID alone is not evidence of identity.
	foreach ( array_unique( array( (int) $candidate_id, $source_id ) ) as $candidate ) {
		if ( ! $candidate || ! wp_attachment_is_image( $candidate ) ) {
			continue;
		}
		$file = get_attached_file( $candidate );
		if ( $file && is_readable( $file ) && $source['file'] && is_readable( $source['file'] ) && hash_file( 'sha256', $file ) === hash_file( 'sha256', $source['file'] ) ) {
			if ( $create ) {
				update_post_meta( $candidate, '_ec_source_attachment', $key );
			}
			return $candidate;
		}
	}
	if ( ! $create ) {
		return 0;
	}
	if ( ! $source['file'] || ! is_readable( $source['file'] ) ) {
		return new WP_Error( 'profile_image_file_missing', 'The source profile image file is unavailable.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$temp = wp_tempnam( $source['file'] );
	if ( ! $temp || ! copy( $source['file'], $temp ) ) {
		if ( $temp ) {
			wp_delete_file( $temp );
		}
		return new WP_Error( 'profile_image_copy_failed', 'The profile image could not be copied.' );
	}
	$id = media_handle_sideload( array( 'name' => wp_basename( $source['file'] ), 'tmp_name' => $temp ), 0, $source['title'] );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $temp );
		return $id;
	}
	update_post_meta( $id, '_ec_source_attachment', $key );
	update_post_meta( $id, '_wp_attachment_image_alt', $source['alt'] );
	return (int) $id;
}

/** Read/write the artist thumbnail from any storage context. */
function ec_artist_profile_thumbnail( $artist_id, $image_id = null ) {
	$blog = (int) ec_get_blog_id( 'artist' );
	return ec_artist_with_image_blog( $blog, static function () use ( $artist_id, $image_id ) {
		if ( null !== $image_id ) {
			$image_id ? set_post_thumbnail( $artist_id, $image_id ) : delete_post_thumbnail( $artist_id );
			if ( (int) get_post_thumbnail_id( $artist_id ) !== (int) $image_id ) {
				return new WP_Error( 'artist_profile_image_save_failed', 'The artist profile image could not be synchronized.' );
			}
		}
		return (int) get_post_thumbnail_id( $artist_id );
	} );
}

/** Apply a storage-site image to its artist, materializing the reverse copy. */
function ec_artist_set_profile_image_from_page( $artist_id, $image_id ) {
	$source_blog = get_current_blog_id();
	$artist_blog = (int) ec_get_blog_id( 'artist' );
	$local = ec_artist_with_image_blog( $artist_blog, static function () use ( $source_blog, $image_id, $artist_id ) {
		return ec_artist_materialize_profile_image( $source_blog, $image_id, get_post_thumbnail_id( $artist_id ) );
	} );
	if ( is_wp_error( $local ) ) {
		return $local;
	}
	// The page save owns persistence/compensation; do not re-enter it from
	// the artist thumbnail metadata hook while a composed save is active.
	$was_syncing = ArtistDataSyncManager::is_syncing();
	ArtistDataSyncManager::start_sync();
	try {
		return ec_artist_profile_thumbnail( $artist_id, $local );
	} finally {
		if ( ! $was_syncing ) {
			ArtistDataSyncManager::stop_sync();
		}
	}
}
