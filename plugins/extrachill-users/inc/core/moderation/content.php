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
			$post_ids = (array) $wpdb->get_col(
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

	// bbPress ancestry (reply → topic → forum) must be read BEFORE deleting —
	// the rows disappear, and a reply's topic may itself be purged later in the
	// same loop, leaving nothing to walk.
	$bbp_context = extrachill_users_capture_bbp_context( $objects );

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

			// Force-delete the same way for every post type — wp_delete_post()
			// bypasses the trash and removes meta/terms. bbPress' own delete
			// callbacks never fire here because bbPress is not loaded in the
			// switched context, so denormalized counters are recomputed after
			// the loop (extrachill_users_recompute_bbp_counters()).
			//
			// switch_to_blog() also leaves the target site's post types (topic,
			// reply, artist_link_page, ...) unregistered, and core cap checks
			// against unregistered types emit _doing_it_wrong notices from
			// map_meta_cap(). Register a minimal 'post'-capability stub for the
			// duration of the delete, then remove it before restoring the blog.
			$stub_registered = false;
			if ( '' !== $post_type && ! post_type_exists( $post_type ) ) {
				$stub_registered = extrachill_users_register_post_type_stub( $post_type );
			}

			try {
				if ( wp_delete_post( $post_id, true ) ) {
					++$results['posts'];
				}
			} finally {
				if ( $stub_registered ) {
					extrachill_users_unregister_post_type_stub( $post_type );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	// Denormalized bbPress counters (topic + parent forum) are stale after the
	// raw deletes above; recompute them directly from the database.
	extrachill_users_recompute_bbp_counters( $bbp_context );

	return $results;
}

/**
 * Register a minimal post type stub so deletions of cross-site posts do not
 * trigger map_meta_cap() _doing_it_wrong notices.
 *
 * Because switch_to_blog() does not load the target site's plugins, post types
 * owned by that site (bbPress topic/reply, artist platform CPTs) are not
 * registered in the current request. Any capability check made against a post
 * of such a type fires a _doing_it_wrong notice from map_meta_cap().
 *
 * The stub deliberately uses map_meta_cap => true with the default
 * 'post' capability type: that maps delete_post/edit_post checks to the same
 * standard caps a regular post uses (delete_others_posts, etc.), preserving
 * whatever the cap-check outcome would have been. A map_meta_cap => false
 * stub would instead map to per-type primitive caps nobody is granted,
 * silently flipping cap-check results in hooked code.
 *
 * Must be paired with extrachill_users_unregister_post_type_stub() before
 * restore_current_blog(), because post type registrations are global state.
 *
 * @param string $post_type Post type to stub.
 * @return bool True if a stub was registered, false if the type already exists.
 */
function extrachill_users_register_post_type_stub( string $post_type ): bool {
	if ( post_type_exists( $post_type ) ) {
		return false;
	}

	register_post_type(
		$post_type,
		array(
			'public'          => false,
			'show_in_rest'    => false,
			'map_meta_cap'    => true,
			'capability_type' => 'post',
		)
	);

	return true;
}

/**
 * Remove a post type stub registered by extrachill_users_register_post_type_stub().
 *
 * @param string $post_type Post type to unregister.
 */
function extrachill_users_unregister_post_type_stub( string $post_type ): void {
	if ( post_type_exists( $post_type ) ) {
		unregister_post_type( $post_type );
	}
}

/**
 * Capture bbPress ancestry (reply → topic → forum) before purging posts.
 *
 * The rows are about to disappear, and a purged reply's topic may itself be
 * purged later in the same loop, so the chain has to be read up front.
 * Because switch_to_blog() does not load bbPress, the chain is read directly
 * from the posts table; threaded replies are resolved by climbing post_parent
 * up to the topic, mirroring bbPress' own resolution, with a depth guard.
 *
 * @param array $objects Content objects queued for purge.
 * @return array<int, array{topics: array<int, int>, forums: array<int, bool>}> Blog ID => affected topics (topic ID => forum ID) and forums.
 */
function extrachill_users_capture_bbp_context( array $objects ): array {
	$ids_by_blog = array();

	foreach ( $objects as $object ) {
		if ( 'post' !== ( $object['type'] ?? '' ) ) {
			continue;
		}

		$post_type = isset( $object['post_type'] ) ? (string) $object['post_type'] : '';
		if ( ! in_array( $post_type, array( 'topic', 'reply' ), true ) ) {
			continue;
		}

		$blog_id = (int) $object['blog_id'];
		$ids_by_blog[ $blog_id ][ (int) $object['object_id'] ] = true;
	}

	if ( empty( $ids_by_blog ) ) {
		return array();
	}

	$context = array();

	foreach ( $ids_by_blog as $blog_id => $ids ) {
		switch_to_blog( $blog_id );
		try {
			foreach ( array_keys( $ids ) as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$post_parent = (int) get_post_field( 'post_parent', $post );
				if ( 'topic' === $post->post_type ) {
					$topic_id = (int) $post->ID;
					$forum_id = $post_parent;
				} else {
					$topic_id = extrachill_users_resolve_reply_topic_id( $post_parent );
					if ( $topic_id <= 0 ) {
						continue;
					}
					$forum_id = (int) get_post_field( 'post_parent', $topic_id );
				}

				$context[ $blog_id ]['topics'][ $topic_id ] = $forum_id;

				if ( $forum_id > 0 ) {
					$context[ $blog_id ]['forums'][ $forum_id ] = true;
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	return $context;
}

/**
 * Resolve the topic ID a reply belongs to by climbing post_parent.
 *
 * Handles flat replies (parent is the topic) and threaded replies (parent is
 * another reply), mirroring bbPress' hierarchy resolution without bbPress
 * being loaded. Depth-guarded against corrupted parent chains.
 *
 * @param int $parent_id The reply's post_parent.
 * @return int Topic ID, or 0 if no non-reply parent was reached.
 */
function extrachill_users_resolve_reply_topic_id( int $parent_id ): int {
	for ( $depth = 0; $parent_id > 0 && $depth < 10; ++$depth ) {
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post ) {
			return 0;
		}
		if ( 'reply' !== $parent->post_type ) {
			return (int) $parent->ID;
		}
		$parent_id = (int) get_post_field( 'post_parent', $parent );
	}

	return 0;
}

/**
 * Recompute bbPress denormalized counters for every blog touched by a purge.
 *
 * The bbPress plugin is not loaded in the switched-to-blog context, so its
 * delete-time counter updates (bbp_update_topic_* / bbp_update_forum_*) never
 * fire during the purge loop. This recomputes the same meta keys directly from
 * the posts and postmeta tables, mirroring bbPress' own semantics (see
 * extrachill_users_bbp_update_topic_counters() and
 * extrachill_users_bbp_update_forum_counters()).
 *
 * @param array $bbp_context Result of extrachill_users_capture_bbp_context().
 */
function extrachill_users_recompute_bbp_counters( array $bbp_context ): void {
	foreach ( $bbp_context as $blog_id => $data ) {
		switch_to_blog( (int) $blog_id );
		try {
			foreach ( array_keys( $data['topics'] ?? array() ) as $topic_id ) {
				extrachill_users_bbp_update_topic_counters( (int) $topic_id );
			}

			foreach ( array_keys( $data['forums'] ?? array() ) as $forum_id ) {
				extrachill_users_bbp_update_forum_counters( (int) $forum_id );
			}
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Recompute a topic's denormalized reply counters and last-activity fields.
 *
 * Matches bbPress semantics without bbPress being loaded:
 * - _bbp_reply_count: public replies linked by the _bbp_topic_id meta mirror,
 *   falling back to post_parent for rows missing meta (bbPress counts via the
 *   meta mirror; the parent fallback reflects rows bbPress itself would still
 *   resolve via bbp_get_reply_topic_id()).
 * - _bbp_last_reply_id: most recent public reply by post_date DESC, ID DESC,
 *   parented to the topic (bbp_get_public_child_last_id() ordering); 0 if none.
 * - _bbp_last_active_id: that reply, or the topic itself when no replies remain.
 * - _bbp_last_active_time: the active post's post_date (only written when set).
 * - _bbp_reply_count_hidden: trash/spam/pending replies, same linkage rules.
 *
 * @param int $topic_id Topic post ID.
 */
function extrachill_users_bbp_update_topic_counters( int $topic_id ): void {
	if ( ! get_post( $topic_id ) ) {
		return;
	}

	global $wpdb;

	$reply_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_topic_id'
			 WHERE p.post_type = 'reply'
			   AND p.post_status = 'publish'
			   AND ( pm.meta_value = %s OR p.post_parent = %d )",
			(string) $topic_id,
			$topic_id
		)
	);

	$reply_count_hidden = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_topic_id'
			 WHERE p.post_type = 'reply'
			   AND p.post_status IN ( 'trash', 'spam', 'pending' )
			   AND ( pm.meta_value = %s OR p.post_parent = %d )",
			(string) $topic_id,
			$topic_id
		)
	);

	$last_reply_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT ID
			 FROM {$wpdb->posts}
			 WHERE post_type = 'reply'
			   AND post_status = 'publish'
			   AND post_parent = %d
			 ORDER BY post_date DESC, ID DESC
			 LIMIT 1",
			$topic_id
		)
	);

	update_post_meta( $topic_id, '_bbp_reply_count', $reply_count );
	update_post_meta( $topic_id, '_bbp_reply_count_hidden', $reply_count_hidden );
	update_post_meta( $topic_id, '_bbp_last_reply_id', $last_reply_id );

	$last_active_id = $last_reply_id > 0 ? $last_reply_id : $topic_id;
	update_post_meta( $topic_id, '_bbp_last_active_id', $last_active_id );

	$last_active_time = (string) get_post_field( 'post_date', $last_active_id );
	if ( '' !== $last_active_time ) {
		update_post_meta( $topic_id, '_bbp_last_active_time', $last_active_time );
	}
}

/**
 * Recompute a forum's denormalized counters and last-activity fields.
 *
 * Matches bbPress semantics without bbPress being loaded:
 * - _bbp_topic_count / _bbp_topic_count_hidden: public/hidden topics linked by
 *   the _bbp_forum_id meta mirror, falling back to post_parent.
 * - _bbp_reply_count / _bbp_reply_count_hidden: public/hidden replies via the
 *   _bbp_forum_id meta mirror only (a reply's post_parent is always a topic,
 *   so there is no parent fallback — same as bbPress' meta-based counting).
 * - _bbp_total_topic_count / _bbp_total_reply_count: direct count plus public
 *   sub-forum totals, recursive like bbp_update_forum_topic_count().
 * - _bbp_last_topic_id: public topic parented to the forum, most recent by
 *   _bbp_last_active_time (post_date fallback), ID DESC tiebreak.
 * - _bbp_last_reply_id / _bbp_last_active_id: most recent public reply across
 *   the forum's topics, compared against the highest topic ID, larger wins
 *   (bbPress' own comparison); 0 when the forum has no public topics.
 * - _bbp_last_active_time: post_date of the last active ID (skipped when 0).
 *
 * @param int $forum_id Forum post ID.
 */
function extrachill_users_bbp_update_forum_counters( int $forum_id ): void {
	if ( ! get_post( $forum_id ) ) {
		return;
	}

	global $wpdb;

	$topic_count        = extrachill_users_bbp_count_public_topics( $forum_id );
	$topic_count_hidden = extrachill_users_bbp_count_hidden_topics( $forum_id );

	$reply_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_forum_id'
			 WHERE p.post_type = 'reply'
			   AND p.post_status = 'publish'
			   AND pm.meta_value = %s",
			(string) $forum_id
		)
	);

	$reply_count_hidden = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_forum_id'
			 WHERE p.post_type = 'reply'
			   AND p.post_status IN ( 'trash', 'spam', 'pending' )
			   AND pm.meta_value = %s",
			(string) $forum_id
		)
	);

	update_post_meta( $forum_id, '_bbp_topic_count', $topic_count );
	update_post_meta( $forum_id, '_bbp_topic_count_hidden', $topic_count_hidden );
	update_post_meta( $forum_id, '_bbp_reply_count', $reply_count );
	update_post_meta( $forum_id, '_bbp_reply_count_hidden', $reply_count_hidden );

	$descendants = extrachill_users_bbp_forum_descendant_totals( $forum_id );
	update_post_meta( $forum_id, '_bbp_total_topic_count', $topic_count + $descendants['topics'] );
	update_post_meta( $forum_id, '_bbp_total_reply_count', $reply_count + $descendants['replies'] );

	$last_topic_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_last_active_time'
			 WHERE p.post_type = 'topic'
			   AND p.post_status IN ( 'publish', 'closed' )
			   AND p.post_parent = %d
			 ORDER BY COALESCE( pm.meta_value, p.post_date ) DESC, p.ID DESC
			 LIMIT 1",
			$forum_id
		)
	);
	update_post_meta( $forum_id, '_bbp_last_topic_id', $last_topic_id );

	$last_reply_id  = 0;
	$last_active_id = 0;

	// bbPress resolves pointer fields via topics structurally parented to the
	// forum (publish/closed), unlike the meta-based counts above. The reply
	// lookup stays parent-list based (like bbPress' own WP_Query usage) — a
	// posts-to-posts self-join is avoided because shared-table SQL engines
	// used by sandboxed runtimes reject it ("Can't reopen table").
	$topic_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT ID
			 FROM {$wpdb->posts}
			 WHERE post_type = 'topic'
			   AND post_status IN ( 'publish', 'closed' )
			   AND post_parent = %d
			 ORDER BY ID DESC",
			$forum_id
		)
	);
	$topic_ids = array_map( 'intval', (array) $topic_ids );

	if ( ! empty( $topic_ids ) ) {
		$reply_ids = get_posts(
			array(
				'post_type'              => 'reply',
				'post_status'            => 'publish',
				'post_parent__in'        => $topic_ids,
				'posts_per_page'         => 1,
				'orderby'                => array(
					'post_date' => 'DESC',
					'ID'        => 'DESC',
				),
				'fields'                 => 'ids',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
			)
		);

		$last_reply_id = empty( $reply_ids ) ? 0 : (int) $reply_ids[0];

		// bbPress compares the newest reply ID with the highest topic ID.
		$last_reply_id  = max( $last_reply_id, max( $topic_ids ) );
		$last_active_id = $last_reply_id;
	}

	update_post_meta( $forum_id, '_bbp_last_reply_id', $last_reply_id );
	update_post_meta( $forum_id, '_bbp_last_active_id', $last_active_id );

	$last_active_time = $last_active_id > 0 ? (string) get_post_field( 'post_date', $last_active_id ) : '';
	if ( '' !== $last_active_time ) {
		update_post_meta( $forum_id, '_bbp_last_active_time', $last_active_time );
	}
}

/**
 * Count a forum's public topics (publish/closed), linked by the _bbp_forum_id
 * meta mirror, falling back to post_parent.
 *
 * @param int $forum_id Forum post ID.
 * @return int Topic count.
 */
function extrachill_users_bbp_count_public_topics( int $forum_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_forum_id'
			 WHERE p.post_type = 'topic'
			   AND p.post_status IN ( 'publish', 'closed' )
			   AND ( pm.meta_value = %s OR p.post_parent = %d )",
			(string) $forum_id,
			$forum_id
		)
	);
}

/**
 * Count a forum's hidden topics (trash/spam/pending), linked by the
 * _bbp_forum_id meta mirror, falling back to post_parent.
 *
 * @param int $forum_id Forum post ID.
 * @return int Topic count.
 */
function extrachill_users_bbp_count_hidden_topics( int $forum_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_forum_id'
			 WHERE p.post_type = 'topic'
			   AND p.post_status IN ( 'trash', 'spam', 'pending' )
			   AND ( pm.meta_value = %s OR p.post_parent = %d )",
			(string) $forum_id,
			$forum_id
		)
	);
}

/**
 * Sum public topic/reply totals across a forum's public sub-forum tree,
 * mirroring bbPress' recursive _bbp_total_* counters.
 *
 * @param int   $forum_id Forum post ID whose descendants are summed.
 * @param array $visited  Visited forum IDs (cycle guard).
 * @return array{topics:int, replies:int}
 */
function extrachill_users_bbp_forum_descendant_totals( int $forum_id, array &$visited = array() ): array {
	global $wpdb;

	$totals = array(
		'topics'  => 0,
		'replies' => 0,
	);

	$subforum_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT ID
			 FROM {$wpdb->posts}
			 WHERE post_type = 'forum'
			   AND post_status = 'publish'
			   AND post_parent = %d",
			$forum_id
		)
	);

	foreach ( array_map( 'intval', (array) $subforum_ids ) as $subforum_id ) {
		if ( $subforum_id <= 0 || isset( $visited[ $subforum_id ] ) ) {
			continue;
		}
		$visited[ $subforum_id ] = true;

		$grand_children = extrachill_users_bbp_forum_descendant_totals( $subforum_id, $visited );

		$totals['topics']  += extrachill_users_bbp_count_public_topics( $subforum_id ) + $grand_children['topics'];
		$totals['replies'] += extrachill_users_bbp_count_public_replies( $subforum_id ) + $grand_children['replies'];
	}

	return $totals;
}

/**
 * Count a forum's public replies via the _bbp_forum_id meta mirror.
 *
 * @param int $forum_id Forum post ID.
 * @return int Reply count.
 */
function extrachill_users_bbp_count_public_replies( int $forum_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bbPress CPTs are not registered in the moderation request context.
		$wpdb->prepare(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbp_forum_id'
			 WHERE p.post_type = 'reply'
			   AND p.post_status = 'publish'
			   AND pm.meta_value = %s",
			(string) $forum_id
		)
	);
}
