<?php
/**
 * Community Activity
 *
 * Network-wide adapter onto the one activity feed, for the sidebar widget and
 * the blog homepage community card.
 *
 * The feed itself (forum topics and replies merged with network comments and
 * any other `ec_activity_feed_sources` source) is built by
 * extrachill_build_activity_feed() in extrachill-community. That plugin is
 * only active on the community site and switch_to_blog() does not load it, so
 * this adapter reads the feed through its portable cross-site door, the
 * `extrachill/community-recent-public-activity` ability, via
 * ec_cross_site_rest_request() (in-process on the community site, HTTP
 * loopback elsewhere). Nothing here queries or merges activity.
 *
 * Results are read as an anonymous viewer and cached network-wide.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EXTRACHILL_COMMUNITY_ACTIVITY_CACHE_TTL' ) ) {
	define( 'EXTRACHILL_COMMUNITY_ACTIVITY_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
}

if ( ! function_exists( 'extrachill_get_community_activity_items' ) ) {
	/**
	 * Retrieve recent activity items from the community activity feed.
	 *
	 * @param int   $limit Number of items to return (1-20).
	 * @param array $args {
	 *     Optional. Feed scope.
	 *
	 *     @type string[] $activity_types Portable item types to include
	 *                                    (discussion, reply, comment).
	 *                                    Default every type.
	 * }
	 * @return array[] Activity items, newest first.
	 */
	function extrachill_get_community_activity_items( $limit = 5, $args = array() ) {
		$input = array( 'limit' => max( 1, min( 20, absint( $limit ) ) ) );
		if ( isset( $args['activity_types'] ) ) {
			$input['activity_types'] = array_values( array_map( 'strval', (array) $args['activity_types'] ) );
		}

		$cache_key = 'ec_community_activity_' . md5( (string) wp_json_encode( $input ) );
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$items = array();
		$ttl   = MINUTE_IN_SECONDS;
		if ( function_exists( 'ec_cross_site_rest_request' ) ) {
			$response = ec_cross_site_rest_request(
				'community',
				'GET',
				'/wp-abilities/v1/abilities/extrachill/community-recent-public-activity/run',
				array(
					'query'   => array( 'input' => $input ),
					'user_id' => 0,
					'timeout' => 5,
				)
			);

			if ( is_array( $response ) && isset( $response['items'] ) && is_array( $response['items'] ) ) {
				foreach ( $response['items'] as $activity ) {
					$item = extrachill_map_community_activity_item( $activity );
					if ( $item ) {
						$items[] = $item;
					}
				}
				$ttl = EXTRACHILL_COMMUNITY_ACTIVITY_CACHE_TTL;
			}
		}

		// A failed read is cached briefly so an unreachable community site does
		// not cost a loopback on every page view.
		set_site_transient( $cache_key, $items, $ttl );

		return $items;
	}
}

if ( ! function_exists( 'extrachill_map_community_activity_item' ) ) {
	/**
	 * Map one portable activity item onto the renderer's item shape.
	 *
	 * @param mixed $activity Item from `extrachill/community-recent-public-activity`.
	 * @return array|null {type, username, user_profile_url, topic_title,
	 *                    forum_title, date_time, forum_url, topic_url}, or null.
	 */
	function extrachill_map_community_activity_item( $activity ) {
		$types = array(
			'discussion' => 'Topic',
			'reply'      => 'Reply',
			'comment'    => 'Comment',
		);

		if ( ! is_array( $activity ) || ! isset( $types[ $activity['activity_type'] ?? '' ] ) || empty( $activity['canonical_url'] ) || empty( $activity['timestamp'] ) ) {
			return null;
		}

		$actor = isset( $activity['actor'] ) && is_array( $activity['actor'] ) ? $activity['actor'] : array();
		$forum = isset( $activity['relationships']['forum'] ) && is_array( $activity['relationships']['forum'] ) ? $activity['relationships']['forum'] : array();
		$post  = isset( $activity['relationships']['post'] ) && is_array( $activity['relationships']['post'] ) ? $activity['relationships']['post'] : array();

		return array(
			'type'             => $types[ $activity['activity_type'] ],
			'username'         => (string) ( $actor['display_name'] ?? '' ),
			'user_profile_url' => (string) ( $actor['profile_url'] ?? '' ),
			'topic_title'      => (string) ( $post['name'] ?? $activity['title'] ?? '' ),
			'forum_title'      => (string) ( $forum['name'] ?? '' ),
			'date_time'        => (string) $activity['timestamp'],
			'forum_url'        => (string) ( $forum['canonical_url'] ?? '' ),
			'topic_url'        => (string) $activity['canonical_url'],
		);
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
			'limit'          => 5,
			'wrapper_tag'    => 'div',
			'wrapper_class'  => 'community-activity-list',
			'item_class'     => '',
			'empty_class'    => '',
			'render_wrapper' => true,
			'counter_offset' => 0,
			'items'          => null,
		);

		$args  = wp_parse_args( $args, $defaults );
		$items = is_array( $args['items'] )
			? array_slice( $args['items'], 0, $args['limit'] )
			: extrachill_get_community_activity_items( $args['limit'] );

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
