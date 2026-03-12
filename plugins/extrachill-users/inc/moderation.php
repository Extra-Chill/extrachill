<?php
/**
 * User Moderation System
 *
 * Shared moderation helpers for bans and suspensions across auth surfaces.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_moderation_meta_key() {
	return 'extrachill_user_moderation';
}

function extrachill_users_legacy_ban_meta_key() {
	return 'extrachill_user_ban';
}

function extrachill_users_get_moderation_policy_definitions() {
	return array(
		'spam' => array(
			'label'   => __( 'Spam', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => true,
				'mark_content_spam' => true,
			),
		),
		'abuse' => array(
			'label'   => __( 'Abuse', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'impersonation' => array(
			'label'   => __( 'Impersonation', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'fraud' => array(
			'label'   => __( 'Fraud', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'other' => array(
			'label'   => __( 'Other', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
	);
}

function extrachill_users_get_moderation_policy( string $reason_key ) {
	$policies = extrachill_users_get_moderation_policy_definitions();
	return $policies[ $reason_key ] ?? $policies['other'];
}

function extrachill_users_get_moderation_status( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array(
			'active' => true,
			'state'  => 'active',
			'user_id' => $user_id,
		);
	}

	$record = get_user_meta( $user_id, extrachill_users_moderation_meta_key(), true );

	if ( ( ! is_array( $record ) || empty( $record['state'] ) ) ) {
		$legacy = get_user_meta( $user_id, extrachill_users_legacy_ban_meta_key(), true );
		if ( is_array( $legacy ) && ! empty( $legacy['banned'] ) ) {
			$record = array(
				'state'      => 'banned',
				'reason_key' => 'other',
				'reason'     => isset( $legacy['reason'] ) ? (string) $legacy['reason'] : '',
				'note'       => isset( $legacy['note'] ) ? (string) $legacy['note'] : '',
				'source'     => isset( $legacy['source'] ) ? (string) $legacy['source'] : '',
				'acted_at'   => isset( $legacy['banned_at'] ) ? (int) $legacy['banned_at'] : 0,
				'acted_by'   => isset( $legacy['banned_by'] ) ? (int) $legacy['banned_by'] : 0,
				'effects'    => extrachill_users_get_moderation_policy( 'other' )['effects'],
			);
		}
	}

	if ( ! is_array( $record ) || empty( $record['state'] ) || 'active' === $record['state'] ) {
		return array(
			'active' => true,
			'state'  => 'active',
			'user_id' => $user_id,
		);
	}

	$reason_key = isset( $record['reason_key'] ) ? (string) $record['reason_key'] : 'other';
	$policy     = extrachill_users_get_moderation_policy( $reason_key );

	return array(
		'active'      => false,
		'state'       => isset( $record['state'] ) ? (string) $record['state'] : 'banned',
		'reason_key'  => $reason_key,
		'reason'      => isset( $record['reason'] ) ? (string) $record['reason'] : '',
		'note'        => isset( $record['note'] ) ? (string) $record['note'] : '',
		'source'      => isset( $record['source'] ) ? (string) $record['source'] : '',
		'acted_at'    => isset( $record['acted_at'] ) ? (int) $record['acted_at'] : 0,
		'acted_by'    => isset( $record['acted_by'] ) ? (int) $record['acted_by'] : 0,
		'effects'     => isset( $policy['effects'] ) ? $policy['effects'] : array(),
		'policy'      => $policy,
		'user_id'     => $user_id,
	);
}

function extrachill_users_is_blocked( int $user_id ): bool {
	$status = extrachill_users_get_moderation_status( $user_id );
	return empty( $status['active'] ) && ! empty( $status['effects']['block_login'] );
}

function extrachill_users_send_moderation_email( WP_User $user, array $status ) {
	$reason_key = isset( $status['reason_key'] ) ? (string) $status['reason_key'] : 'other';
	$state      = isset( $status['state'] ) ? (string) $status['state'] : 'banned';
	$reason     = isset( $status['reason'] ) ? (string) $status['reason'] : '';

	if ( 'spam' === $reason_key ) {
		$subject = __( 'Your Extra Chill account has been permanently banned for spam', 'extrachill-users' );
		$message = __( 'Your account has been permanently banned for spam and all associated public content has been removed from public view.', 'extrachill-users' );
	} elseif ( 'suspended' === $state ) {
		$subject = __( 'Your Extra Chill account has been suspended', 'extrachill-users' );
		$message = __( 'Your account has been suspended. Please contact support if you believe this is a mistake.', 'extrachill-users' );
	} else {
		$subject = __( 'Your Extra Chill account has been banned', 'extrachill-users' );
		$message = __( 'Your account has been banned. Please contact support if you believe this is a mistake.', 'extrachill-users' );
	}

	if ( $reason ) {
		$message .= "\n\n" . sprintf( __( 'Reason: %s', 'extrachill-users' ), $reason );
	}

	return wp_mail( $user->user_email, $subject, $message );
}

function extrachill_users_get_user_content_objects( int $user_id ): array {
	$objects = array();
	$sites   = get_sites( array( 'number' => 0, 'spam' => 0, 'deleted' => 0, 'archived' => 0 ) );

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

			if ( is_array( $post_ids ) ) {
				foreach ( $post_ids as $post_id ) {
					$objects[] = array(
						'type'      => 'post',
						'blog_id'   => (int) $site->blog_id,
						'object_id' => (int) $post_id,
						'post_type' => get_post_type( $post_id ),
					);
				}
			}

			$comments = get_comments(
				array(
					'user_id' => $user_id,
					'status'  => 'all',
					'fields'  => 'ids',
					'number'  => 0,
				)
			);

			if ( is_array( $comments ) ) {
				foreach ( $comments as $comment_id ) {
					$objects[] = array(
						'type'      => 'comment',
						'blog_id'   => (int) $site->blog_id,
						'object_id' => (int) $comment_id,
					);
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	return $objects;
}

function extrachill_users_get_owned_artist_platform_objects( int $user_id ): array {
	$objects         = array();
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

			if ( is_array( $link_pages ) ) {
				foreach ( $link_pages as $link_page_id ) {
					$objects[] = array(
						'type'      => 'post',
						'blog_id'   => $artist_blog_id,
						'object_id' => (int) $link_page_id,
						'post_type' => 'artist_link_page',
					);
				}
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
			function ( $carry, $object ) {
				$key = $object['type'] . ':' . $object['blog_id'] . ':' . $object['object_id'];
				$carry[ $key ] = $object;
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
					$results['comments']++;
				}
				continue;
			}

			$post_id   = (int) $object['object_id'];
			$post_type = isset( $object['post_type'] ) ? (string) $object['post_type'] : '';

			if ( 'topic' === $post_type && function_exists( 'bbp_spam_topic' ) ) {
				bbp_spam_topic( $post_id );
				$results['posts']++;
				continue;
			}

			if ( 'reply' === $post_type && function_exists( 'bbp_spam_reply' ) ) {
				bbp_spam_reply( $post_id );
				$results['posts']++;
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
			$results['posts']++;
		} finally {
			restore_current_blog();
		}
	}

	return $results;
}

function extrachill_users_apply_moderation_action( int $user_id, array $args = array() ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ) );
	}

	$state      = isset( $args['state'] ) ? sanitize_key( (string) $args['state'] ) : 'banned';
	$reason_key = isset( $args['reason_key'] ) ? sanitize_key( (string) $args['reason_key'] ) : 'other';
	$actor_id   = isset( $args['acted_by'] ) ? (int) $args['acted_by'] : get_current_user_id();
	$policy     = extrachill_users_get_moderation_policy( $reason_key );

	$payload = array(
		'state'      => $state,
		'reason_key' => $reason_key,
		'reason'     => isset( $args['reason'] ) ? sanitize_text_field( (string) $args['reason'] ) : '',
		'note'       => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
		'source'     => isset( $args['source'] ) ? sanitize_text_field( (string) $args['source'] ) : '',
		'acted_at'   => time(),
		'acted_by'   => $actor_id > 0 ? $actor_id : 0,
		'effects'    => isset( $policy['effects'] ) ? $policy['effects'] : array(),
	);

	update_user_meta( $user_id, extrachill_users_moderation_meta_key(), $payload );
	delete_user_meta( $user_id, extrachill_users_legacy_ban_meta_key() );

	if ( ! empty( $policy['effects']['revoke_sessions'] ) && function_exists( 'WP_Session_Tokens' ) ) {
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$manager->destroy_all();
	}

	$results = array();
	if ( ! empty( $policy['effects']['mark_content_spam'] ) || ! empty( $policy['effects']['hide_content'] ) ) {
		$results['content'] = extrachill_users_apply_spam_visibility_to_user_content( $user_id );
	}

	$status = extrachill_users_get_moderation_status( $user_id );

	if ( ! empty( $policy['effects']['send_email'] ) ) {
		extrachill_users_send_moderation_email( $user, $status );
	}

	if ( ! empty( $results ) ) {
		$status['results'] = $results;
	}

	return $status;
}

function extrachill_users_clear_moderation_action( int $user_id ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	delete_user_meta( $user_id, extrachill_users_moderation_meta_key() );
	delete_user_meta( $user_id, extrachill_users_legacy_ban_meta_key() );

	return array(
		'active'  => true,
		'state'   => 'active',
		'user_id' => $user_id,
	);
}

function extrachill_users_block_moderated_cookie_auth( $user, $username, $password ) {
	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		return $user;
	}

	if ( extrachill_users_is_blocked( (int) $user->ID ) ) {
		return new WP_Error(
			'extrachill_user_blocked',
			__( 'This account has been suspended. Please contact support if you believe this is a mistake.', 'extrachill-users' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'extrachill_users_block_moderated_cookie_auth', 30, 3 );
