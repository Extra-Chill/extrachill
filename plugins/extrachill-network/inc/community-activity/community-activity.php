<?php
/**
 * Community Activity
 *
 * Provides community activity data fetching and rendering for the multisite network.
 * Queries bbPress activity from community site with caching.
 *
 * Callers can opt in to recent approved article and event comments
 * (`include_comments`), read from extrachill-users' cached network reader
 * (ec_users_get_recent_comment_activity()) and merged chronologically with
 * topics and replies. Without the flag the items stay forum-only, so existing
 * callers (the blog homepage community card) are unchanged.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_get_community_activity_items' ) ) {
	/**
	 * Retrieve recent community activity items from community site.
	 *
	 * @param int   $limit Number of items to return.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type bool $include_comments Merge recent article and event comments
	 *                                  into the list. Default false.
	 * }
	 * @return array[] Array of activity items.
	 */
	function extrachill_get_community_activity_items( $limit = 5, $args = array() ) {
		$limit = max( 1, absint( $limit ) );
		$args  = wp_parse_args( $args, array( 'include_comments' => false ) );

		$activities = extrachill_get_community_forum_activity_items();

		if ( $args['include_comments'] ) {
			$activities = extrachill_merge_community_activity_items( $activities, extrachill_get_community_comment_activity_items( $limit ) );
		}

		return array_slice( $activities, 0, $limit );
	}
}

if ( ! function_exists( 'extrachill_get_community_forum_activity_items' ) ) {
	/**
	 * Retrieve (cached) recent forum topics and replies from the community site.
	 *
	 * @return array[] Up to 10 forum activity items, newest first.
	 */
	function extrachill_get_community_forum_activity_items() {
		$cache_key  = 'extrachill_community_activity_all';
		$activities = wp_cache_get( $cache_key );

		if ( false === $activities ) {
			$current_blog_id   = get_current_blog_id();
			$community_blog_id = ec_get_blog_id( 'community' );
			$query_limit       = 10;
			$activities        = array();

			if ( ! $community_blog_id ) {
				return $activities;
			}

			$switched = false;
			if ( $community_blog_id !== $current_blog_id ) {
				switch_to_blog( $community_blog_id );
				$switched = true;
			}

			try {
				$args = array(
					'post_type'      => array( 'topic', 'reply' ),
					'post_status'    => 'publish',
					'posts_per_page' => $query_limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
				);

				$query = new WP_Query( $args );

				if ( $query->have_posts() ) {
					while ( $query->have_posts() ) {
						$query->the_post();
						$post_id   = get_the_ID();
						$post_type = get_post_type( $post_id );
						$author_id = get_the_author_meta( 'ID' );
						$date_time = get_the_date( 'c' );

						$forum_id = absint( get_post_meta( $post_id, '_bbp_forum_id', true ) );
						$topic_id = ( 'reply' === $post_type )
							? absint( get_post_meta( $post_id, '_bbp_topic_id', true ) )
							: $post_id;

						if ( 'reply' === $post_type && ! $topic_id ) {
							$topic_id = absint( get_post_field( 'post_parent', $post_id ) );
						}

						if ( ! $forum_id && $topic_id ) {
							$forum_id = absint( get_post_meta( $topic_id, '_bbp_forum_id', true ) );
						}

						$forum_title      = $forum_id ? get_the_title( $forum_id ) : '';
						$topic_title      = $topic_id ? get_the_title( $topic_id ) : '';
						$forum_url        = $forum_id ? get_permalink( $forum_id ) : '';
						$topic_url        = $topic_id ? get_permalink( $topic_id ) : '';
						$username         = get_the_author();
						$user_profile_url = ( $author_id && function_exists( 'extrachill_get_user_profile_url' ) )
							? extrachill_get_user_profile_url( $author_id )
							: '';

						if ( ! $topic_url || ! $forum_url ) {
							continue;
						}

						$activities[] = array(
							'id'               => $post_id,
							'type'             => ( 'reply' === $post_type ) ? 'Reply' : 'Topic',
							'username'         => $username,
							'user_profile_url' => $user_profile_url,
							'topic_title'      => $topic_title,
							'forum_title'      => $forum_title,
							'date_time'        => $date_time,
							'forum_url'        => $forum_url,
							'topic_url'        => $topic_url,
						);
					}
					wp_reset_postdata();
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}

			wp_cache_set( $cache_key, $activities, '', 10 * MINUTE_IN_SECONDS );
		}

		return $activities;
	}
}

if ( ! function_exists( 'extrachill_get_community_comment_activity_items' ) ) {
	/**
	 * Map recent network comments onto the community activity item shape.
	 *
	 * Comment items use `type` 'Comment'. `topic_title`/`topic_url` carry the
	 * article or event title and the comment anchor; the forum fields are empty.
	 *
	 * @param int $limit Maximum items.
	 * @return array[] Comment activity items, newest first.
	 */
	function extrachill_get_community_comment_activity_items( $limit = 5 ) {
		if ( ! function_exists( 'ec_users_get_recent_comment_activity' ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) ec_users_get_recent_comment_activity( max( 1, absint( $limit ) ) ) as $comment ) {
			if ( ! is_array( $comment ) || empty( $comment['comment_url'] ) || empty( $comment['date_gmt'] ) ) {
				continue;
			}

			$user_id          = isset( $comment['user_id'] ) ? (int) $comment['user_id'] : 0;
			$user_profile_url = ( $user_id > 0 && function_exists( 'extrachill_get_user_profile_url' ) )
				? extrachill_get_user_profile_url( $user_id )
				: '';
			$timestamp        = strtotime( $comment['date_gmt'] . ' UTC' );

			if ( false === $timestamp ) {
				continue;
			}

			$items[] = array(
				'id'               => (int) $comment['comment_id'],
				'type'             => 'Comment',
				'username'         => isset( $comment['author_name'] ) ? (string) $comment['author_name'] : '',
				'user_profile_url' => $user_profile_url,
				'topic_title'      => isset( $comment['post_title'] ) ? (string) $comment['post_title'] : '',
				'forum_title'      => '',
				'date_time'        => gmdate( 'c', $timestamp ),
				'forum_url'        => '',
				'topic_url'        => (string) $comment['comment_url'],
			);
		}

		return $items;
	}
}

if ( ! function_exists( 'extrachill_merge_community_activity_items' ) ) {
	/**
	 * Merge forum and comment activity items chronologically, newest first.
	 *
	 * Stable: items with the same timestamp keep forum-before-comment order.
	 *
	 * @param array[] $forum_items   Forum activity items.
	 * @param array[] $comment_items Comment activity items.
	 * @return array[]
	 */
	function extrachill_merge_community_activity_items( array $forum_items, array $comment_items ) {
		$merged = array();
		foreach ( array_merge( $forum_items, $comment_items ) as $index => $item ) {
			$merged[] = array(
				'time'  => (int) strtotime( (string) $item['date_time'] ),
				'index' => $index,
				'item'  => $item,
			);
		}

		usort(
			$merged,
			static function ( $a, $b ) {
				if ( $a['time'] !== $b['time'] ) {
					return $b['time'] <=> $a['time'];
				}
				return $a['index'] <=> $b['index'];
			}
		);

		return array_column( $merged, 'item' );
	}
}

if ( ! function_exists( 'extrachill_render_community_activity' ) ) {
	/**
	 * Render community activity items.
	 *
	 * @param array $args Rendering arguments.
	 * @return void
	 */
	function extrachill_render_community_activity( $args = array() ) {
		$defaults = array(
			'limit'            => 5,
			'wrapper_tag'      => 'div',
			'wrapper_class'    => 'community-activity-list',
			'item_class'       => '',
			'empty_class'      => '',
			'render_wrapper'   => true,
			'counter_offset'   => 0,
			'items'            => null,
			'include_comments' => false,
		);

		$args  = wp_parse_args( $args, $defaults );
		$items = is_array( $args['items'] )
			? array_slice( $args['items'], 0, $args['limit'] )
			: extrachill_get_community_activity_items( $args['limit'], array( 'include_comments' => (bool) $args['include_comments'] ) );

		$item_class = trim( $args['item_class'] );
		$item_class = $item_class ? 'community-activity-card ' . $item_class : 'community-activity-card';

		$empty_class = trim( $args['empty_class'] );
		$empty_class = $empty_class ? 'community-activity-empty ' . $empty_class : 'community-activity-empty';

		if ( ! empty( $items ) ) {
			if ( $args['render_wrapper'] ) {
				printf( '<%1$s class="%2$s">', esc_attr( $args['wrapper_tag'] ), esc_attr( $args['wrapper_class'] ) );
			}

			foreach ( $items as $index => $activity ) {
				if ( ! is_array( $activity ) ) {
					continue;
				}

				$counter   = $index + 1 + (int) $args['counter_offset'];
				$time_text = sprintf(
					/* translators: %s: human-readable time difference */
					esc_html__( '%s ago', 'extrachill-network' ),
					human_time_diff( strtotime( $activity['date_time'] ) )
				);

				$username      = esc_html( $activity['username'] );
				$username_html = $activity['user_profile_url']
					? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $activity['user_profile_url'] ), $username )
					: $username;

				$topic_html = sprintf(
					'<a id="topic-%1$d" href="%2$s">%3$s</a>',
					$counter,
					esc_url( $activity['topic_url'] ),
					esc_html( $activity['topic_title'] )
				);

				$forum_html = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $activity['forum_url'] ),
					esc_html( $activity['forum_title'] )
				);

				if ( 'Comment' === $activity['type'] ) {
					$content = sprintf(
						'%1$s %2$s %3$s',
						$username_html,
						esc_html__( 'commented on', 'extrachill-network' ),
						$topic_html
					);
				} elseif ( 'Reply' === $activity['type'] ) {
					$content = sprintf(
						'%1$s %2$s %3$s %4$s %5$s',
						$username_html,
						esc_html__( 'replied to', 'extrachill-network' ),
						$topic_html,
						esc_html__( 'in', 'extrachill-network' ),
						$forum_html
					);
				} else {
					$content = sprintf(
						'%1$s %2$s %3$s %4$s %5$s',
						$username_html,
						esc_html__( 'posted', 'extrachill-network' ),
						$topic_html,
						esc_html__( 'in', 'extrachill-network' ),
						$forum_html
					);
				}

				printf(
					'<div class="%1$s">%2$s - %3$s</div>',
					esc_attr( $item_class ),
					wp_kses_post( $content ),
					esc_html( $time_text )
				);
			}

			if ( $args['render_wrapper'] ) {
				printf( '</%s>', esc_attr( $args['wrapper_tag'] ) );
			}
		} else {
			$empty_message = __( 'No recent activity.', 'extrachill-network' );

			if ( $args['render_wrapper'] ) {
				printf( '<%1$s class="%2$s">', esc_attr( $args['wrapper_tag'] ), esc_attr( $args['wrapper_class'] ) );
			}

			printf(
				'<div class="%1$s">%2$s</div>',
				esc_attr( $empty_class ),
				esc_html( $empty_message )
			);

			if ( $args['render_wrapper'] ) {
				printf( '</%s>', esc_attr( $args['wrapper_tag'] ) );
			}
		}
	}
}
