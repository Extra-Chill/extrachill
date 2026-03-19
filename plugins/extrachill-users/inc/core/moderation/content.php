<?php
/**
 * Moderation Content Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_get_user_content_objects( int $user_id ): array {
	$objects = array();
	$sites   = get_sites( array(
		'number'   => 0,
		'spam'     => 0,
		'deleted'  => 0,
		'archived' => 0,
	) );

	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		try {
			$post_ids = get_posts(
				array(
					'author'         => $user_id,
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $post_ids as $post_id ) {
				$objects[] = array(
					'type'      => 'post',
					'blog_id'   => (int) $site->blog_id,
					'object_id' => (int) $post_id,
					'post_type' => get_post_type( $post_id ),
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

			foreach ( $comments as $comment_id ) {
				$objects[] = array(
					'type'      => 'comment',
					'blog_id'   => (int) $site->blog_id,
					'object_id' => (int) $comment_id,
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

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			++$results['posts'];
		} finally {
			restore_current_blog();
		}
	}

	return $results;
}
