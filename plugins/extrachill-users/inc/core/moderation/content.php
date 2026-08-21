<?php
/**
 * Moderation Content Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_get_user_content_objects( int $user_id ): array {
	$objects = array();
	$sites   = get_sites(
		array(
			'number'   => 0,
			'spam'     => 0,
			'deleted'  => 0,
			'archived' => 0,
		)
	);

	global $wpdb;

	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		try {
			// Query the posts table directly. Avoids `post_type => 'any'` which
			// only expands to post types registered in the current request
			// context. switch_to_blog() does not load per-site plugins, so
			// types like bbPress `topic` / `reply` and per-site CPTs are NOT
			// registered when this runs from another site's request — and
			// would be silently skipped, leaving banned users' content live.
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_author = %d
					   AND post_type NOT IN ( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' )",
					$user_id
				)
			);

			foreach ( $post_ids as $post_id ) {
				$post_id   = (int) $post_id;
				$objects[] = array(
					'type'      => 'post',
					'blog_id'   => (int) $site->blog_id,
					'object_id' => $post_id,
					'post_type' => get_post_field( 'post_type', $post_id ),
				);
			}

			$comments = get_comments(
				array(
					'user_id' => $user_id,
					'status'  => 'all',
					'fields'  => 'ids',
					'number'  => 0,
				)
			);

			// `'fields' => 'ids'` makes get_comments() return int[], but its
			// declared return type is array<int|WP_Comment>|int. Guard so the
			// foreach is safe on the WP_Comment branch and on the int branch
			// (which our params won't actually trigger).
			if ( ! is_array( $comments ) ) {
				continue;
			}

			foreach ( $comments as $comment_id ) {
				$objects[] = array(
					'type'      => 'comment',
					'blog_id'   => (int) $site->blog_id,
					'object_id' => $comment_id instanceof WP_Comment ? (int) $comment_id->comment_ID : (int) $comment_id,
				);
			}
		} finally {
			restore_current_blog();
		}
	}

	return $objects;
}

function extrachill_users_get_owned_artist_platform_objects( int $user_id ): array {
	$objects            = array();
	$artist_profile_ids = get_user_meta( $user_id, '_artist_profile_ids', true );

	if ( ! is_array( $artist_profile_ids ) || empty( $artist_profile_ids ) ) {
		return $objects;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
	if ( $artist_blog_id <= 0 ) {
		return $objects;
	}

	switch_to_blog( $artist_blog_id );
	try {
		foreach ( $artist_profile_ids as $artist_id ) {
			$artist_id = (int) $artist_id;
			if ( $artist_id <= 0 || get_post_type( $artist_id ) !== 'artist_profile' ) {
				continue;
			}

			$objects[] = array(
				'type'      => 'post',
				'blog_id'   => $artist_blog_id,
				'object_id' => $artist_id,
				'post_type' => 'artist_profile',
			);

			$link_pages = get_posts(
				array(
					'post_type'      => 'artist_link_page',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_associated_artist_profile_id',
					'meta_value'     => (string) $artist_id,
				)
			);

			foreach ( $link_pages as $link_page_id ) {
				$objects[] = array(
					'type'      => 'post',
					'blog_id'   => $artist_blog_id,
					'object_id' => (int) $link_page_id,
					'post_type' => 'artist_link_page',
				);
			}
		}
	} finally {
		restore_current_blog();
	}

	return $objects;
}

function extrachill_users_apply_spam_visibility_to_user_content( int $user_id ) {
	$objects = array_merge(
		extrachill_users_get_user_content_objects( $user_id ),
		extrachill_users_get_owned_artist_platform_objects( $user_id )
	);

	$objects = array_values(
		array_reduce(
			$objects,
			function ( $carry, $object_value ) {
				$key           = $object_value['type'] . ':' . $object_value['blog_id'] . ':' . $object_value['object_id'];
				$carry[ $key ] = $object_value;
				return $carry;
			},
			array()
		)
	);

	$results = array(
		'posts'    => 0,
		'comments' => 0,
	);

	foreach ( $objects as $object ) {
		switch_to_blog( (int) $object['blog_id'] );
		try {
			if ( 'comment' === $object['type'] ) {
				if ( function_exists( 'wp_spam_comment' ) ) {
					wp_spam_comment( (int) $object['object_id'] );
					++$results['comments'];
				}
				continue;
			}

			$post_id   = (int) $object['object_id'];
			$post_type = isset( $object['post_type'] ) ? (string) $object['post_type'] : '';

			if ( 'topic' === $post_type && function_exists( 'bbp_spam_topic' ) ) {
				bbp_spam_topic( $post_id );
				++$results['posts'];
				continue;
			}

			if ( 'reply' === $post_type && function_exists( 'bbp_spam_reply' ) ) {
				bbp_spam_reply( $post_id );
				++$results['posts'];
				continue;
			}

			if ( 'attachment' === $post_type ) {
				continue;
			}

			if ( in_array( $post_type, array( 'artist_profile', 'artist_link_page' ), true ) ) {
				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cross-site CPTs are not registered in the moderation request context.
					$wpdb->posts,
					array( 'post_status' => 'draft' ),
					array( 'ID' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);
				clean_post_cache( $post_id );
			} else {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}
			++$results['posts'];
		} finally {
			restore_current_blog();
		}
	}

	return $results;
}

/**
 * Hard-delete (purge) all of a user's content network-wide.
 *
 * DESTRUCTIVE AND IRREVERSIBLE. This is an explicit, opt-in operation and is
 * NEVER the default moderation behavior — hiding (draft/spam) remains the
 * default for every reason. Purge is only invoked when an operator explicitly
 * requests it (e.g. the `purge_content` ability input / `--purge` CLI flag).
 *
 * Reuses the same network-wide post/comment collection and artist-platform
 * collection that the hide path uses, so it catches the identical scope —
 * including bbPress `topic`/`reply` CPTs and artist link pages. Unlike the
 * hide path (which skips attachments), purge ALSO removes the user's owned
 * attachments and any attachments parented to the deleted posts, so orphaned
 * link-page logo attachments are caught.
 *
 * @param int $user_id User whose content should be permanently deleted.
 * @return array{posts:int,comments:int,attachments:int} Counts of removed objects.
 */
function extrachill_users_purge_user_content( int $user_id ) {
	$objects = array_merge(
		extrachill_users_get_user_content_objects( $user_id ),
		extrachill_users_get_owned_artist_platform_objects( $user_id )
	);

	$objects = array_values(
		array_reduce(
			$objects,
			function ( $carry, $object_value ) {
				$key           = $object_value['type'] . ':' . $object_value['blog_id'] . ':' . $object_value['object_id'];
				$carry[ $key ] = $object_value;
				return $carry;
			},
			array()
		)
	);

	$results = array(
		'posts'       => 0,
		'comments'    => 0,
		'attachments' => 0,
	);

	// Defer attachment deletion so we delete child attachments alongside their
	// parent post in the same loop iteration, and standalone author-owned
	// attachments after, avoiding double-deleting a child we already removed.
	$deleted_attachments = array();

	foreach ( $objects as $object ) {
		switch_to_blog( (int) $object['blog_id'] );
		try {
			if ( 'comment' === $object['type'] ) {
				if ( wp_delete_comment( (int) $object['object_id'], true ) ) {
					++$results['comments'];
				}
				continue;
			}

			$post_id   = (int) $object['object_id'];
			$post_type = isset( $object['post_type'] ) ? (string) $object['post_type'] : '';

			if ( 'attachment' === $post_type ) {
				$key = (int) $object['blog_id'] . ':' . $post_id;
				if ( empty( $deleted_attachments[ $key ] ) ) {
					if ( wp_delete_attachment( $post_id, true ) ) {
						++$results['attachments'];
						$deleted_attachments[ $key ] = true;
					}
				}
				continue;
			}

			// Remove attachments parented to this post (e.g. link-page logos)
			// before deleting the post, so they don't linger as orphans.
			$child_attachments = get_children(
				array(
					'post_parent'    => $post_id,
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( is_array( $child_attachments ) ) {
				foreach ( $child_attachments as $attachment_id ) {
					$attachment_id = (int) $attachment_id;
					$key           = (int) $object['blog_id'] . ':' . $attachment_id;
					if ( ! empty( $deleted_attachments[ $key ] ) ) {
						continue;
					}
					if ( wp_delete_attachment( $attachment_id, true ) ) {
						++$results['attachments'];
						$deleted_attachments[ $key ] = true;
					}
				}
			}

			// Featured image (thumbnail) may not be a post_parent child.
			$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id > 0 ) {
				$key = (int) $object['blog_id'] . ':' . $thumbnail_id;
				if ( empty( $deleted_attachments[ $key ] ) ) {
					if ( wp_delete_attachment( $thumbnail_id, true ) ) {
						++$results['attachments'];
						$deleted_attachments[ $key ] = true;
					}
				}
			}

			// bbPress topics/replies and all other posts are force-deleted the
			// same way — wp_delete_post( $id, true ) bypasses the trash and
			// removes meta/terms. For topics this also unhooks replies via
			// bbPress' own delete callbacks where registered.
			if ( wp_delete_post( $post_id, true ) ) {
				++$results['posts'];
			}
		} finally {
			restore_current_blog();
		}
	}

	return $results;
}
