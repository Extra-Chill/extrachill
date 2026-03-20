<?php
/**
 * Community CLI Command
 *
 * Wraps community abilities from extrachill-community.
 *
 * @package ExtraChill\CLI\Commands\Community
 */

namespace ExtraChill\CLI\Commands\Community;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommunityCommand {

	/**
	 * Show overall community statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community stats --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function stats( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/community-get-stats' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-get-stats ability not available. Is extrachill-community active on this site?' );
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$items = array(
			array( 'Metric' => 'Forums', 'Value' => $result['forums'] ),
			array( 'Metric' => 'Topics', 'Value' => $result['topics'] ),
			array( 'Metric' => 'Replies', 'Value' => $result['replies'] ),
			array( 'Metric' => 'Active Users', 'Value' => $result['active_users'] ),
			array( 'Metric' => 'Total Upvotes', 'Value' => $result['total_upvotes'] ),
		);

		Utils\format_items( 'table', $items, array( 'Metric', 'Value' ) );
	}

	/**
	 * Show community leaderboard.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of users to show.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Pagination offset.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community leaderboard --url=community.extrachill.com
	 *     wp extrachill community leaderboard --limit=10 --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function leaderboard( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/community-get-leaderboard' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-get-leaderboard ability not available. Is extrachill-community active on this site?' );
		}

		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 25;
		$offset = isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$result = $ability->execute(
			array(
				'limit'  => $limit,
				'offset' => $offset,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( sprintf( 'Total users: %d', $result['total'] ) );

		if ( empty( $result['users'] ) ) {
			WP_CLI::log( 'No users found.' );
			return;
		}

		Utils\format_items( $format, $result['users'], array( 'rank', 'user_login', 'display_name', 'total_points', 'rank_name' ) );
	}

	/**
	 * Get user points and rank.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--recalculate]
	 * : Force recalculation of points.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community points chubes --url=community.extrachill.com
	 *     wp extrachill community points 1 --recalculate --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function points( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$recalculate = Utils\get_flag_value( $assoc_args, 'recalculate', false );

		if ( $recalculate ) {
			$ability = wp_get_ability( 'extrachill/community-recalculate-points' );
			if ( ! $ability ) {
				WP_CLI::error( 'extrachill/community-recalculate-points ability not available.' );
			}

			$result = $ability->execute( array( 'user_id' => (int) $user->ID ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::log( 'Points recalculated.' );
		}

		$ability = wp_get_ability( 'extrachill/community-get-user-points' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-get-user-points ability not available.' );
		}

		$result = $ability->execute( array( 'user_id' => (int) $user->ID ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$items = array(
			array( 'Field' => 'User', 'Value' => sprintf( '%s (%d)', $result['user_login'], $result['user_id'] ) ),
			array( 'Field' => 'Display Name', 'Value' => $result['display_name'] ),
			array( 'Field' => 'Points', 'Value' => $result['total_points'] ),
			array( 'Field' => 'Rank', 'Value' => $result['rank'] ),
		);

		Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
	}

	/**
	 * Recalculate points for all users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community recalculate-all --url=community.extrachill.com
	 *
	 * @subcommand recalculate-all
	 * @when after_wp_load
	 */
	public function recalculate_all( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/community-recalculate-points' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-recalculate-points ability not available.' );
		}

		WP_CLI::log( 'Recalculating points for all users...' );

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Recalculated points for %d users.', $result['recalculated'] ) );
	}

	/**
	 * Show notifications for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--unread]
	 * : Only show unread notifications.
	 *
	 * [--count]
	 * : Just show the count, not the full list.
	 *
	 * [--mark-read]
	 * : Mark all notifications as read.
	 *
	 * [--clear]
	 * : Clear old read notifications.
	 *
	 * [--clear-all]
	 * : Clear ALL notifications.
	 *
	 * [--limit=<limit>]
	 * : Max notifications to show.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community notifications chubes --url=community.extrachill.com
	 *     wp extrachill community notifications 1 --unread --url=community.extrachill.com
	 *     wp extrachill community notifications 1 --count --url=community.extrachill.com
	 *     wp extrachill community notifications 1 --mark-read --url=community.extrachill.com
	 *     wp extrachill community notifications 1 --clear --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function notifications( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$user_id = (int) $user->ID;

		// Handle --mark-read.
		if ( Utils\get_flag_value( $assoc_args, 'mark-read', false ) ) {
			$ability = wp_get_ability( 'extrachill/community-mark-notifications-read' );
			if ( ! $ability ) {
				WP_CLI::error( 'extrachill/community-mark-notifications-read ability not available.' );
			}

			$result = $ability->execute( array( 'user_id' => $user_id ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Marked %d notifications as read.', $result['marked'] ) );
			return;
		}

		// Handle --clear / --clear-all.
		if ( Utils\get_flag_value( $assoc_args, 'clear', false ) || Utils\get_flag_value( $assoc_args, 'clear-all', false ) ) {
			$ability = wp_get_ability( 'extrachill/community-clear-notifications' );
			if ( ! $ability ) {
				WP_CLI::error( 'extrachill/community-clear-notifications ability not available.' );
			}

			$clear_all = Utils\get_flag_value( $assoc_args, 'clear-all', false );
			$result    = $ability->execute(
				array(
					'user_id' => $user_id,
					'all'     => $clear_all,
				)
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Removed %d notifications.', $result['removed'] ) );
			return;
		}

		// Default: list notifications.
		$ability = wp_get_ability( 'extrachill/community-get-notifications' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-get-notifications ability not available.' );
		}

		$unread = Utils\get_flag_value( $assoc_args, 'unread', false );
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$result = $ability->execute(
			array(
				'user_id' => $user_id,
				'unread'  => $unread,
				'limit'   => $limit,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		// Handle --count.
		if ( Utils\get_flag_value( $assoc_args, 'count', false ) ) {
			WP_CLI::log( sprintf( 'Total: %d | Unread: %d', $result['total'], $result['unread_count'] ) );
			return;
		}

		WP_CLI::log( sprintf( 'Showing %d of %d notifications (unread: %d)', count( $result['notifications'] ), $result['total'], $result['unread_count'] ) );

		if ( empty( $result['notifications'] ) ) {
			WP_CLI::log( 'No notifications.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result['notifications'], JSON_PRETTY_PRINT ) );
			return;
		}

		// Table format: flatten for display.
		$rows = array();
		foreach ( $result['notifications'] as $n ) {
			$rows[] = array(
				'type'        => isset( $n['type'] ) ? $n['type'] : '',
				'from'        => isset( $n['actor_display_name'] ) ? $n['actor_display_name'] : '',
				'subject'     => isset( $n['topic_title'] ) ? mb_substr( $n['topic_title'], 0, 50 ) : '',
				'time'        => isset( $n['time'] ) ? $n['time'] : '',
				'read'        => empty( $n['read'] ) ? 'no' : 'yes',
			);
		}

		Utils\format_items( 'table', $rows, array( 'type', 'from', 'subject', 'time', 'read' ) );
	}

	/**
	 * List forums.
	 *
	 * ## OPTIONS
	 *
	 * [--homepage]
	 * : Only show forums visible on the homepage.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community forums --url=community.extrachill.com
	 *     wp extrachill community forums --homepage --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function forums( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/community-list-forums' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-list-forums ability not available.' );
		}

		$homepage_only = Utils\get_flag_value( $assoc_args, 'homepage', false );
		$format        = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$result = $ability->execute( array( 'homepage_only' => $homepage_only ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['forums'] ) ) {
			WP_CLI::log( 'No forums found.' );
			return;
		}

		// Convert boolean to string for table display.
		$forums = array_map(
			function ( $f ) {
				$f['show_on_homepage'] = $f['show_on_homepage'] ? 'yes' : 'no';
				return $f;
			},
			$result['forums']
		);

		Utils\format_items( $format, $forums, array( 'forum_id', 'title', 'parent_id', 'topic_count', 'reply_count', 'show_on_homepage' ) );
	}

	/**
	 * Toggle a forum's homepage visibility.
	 *
	 * ## OPTIONS
	 *
	 * <forum_id>
	 * : Forum post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community toggle-homepage 123 --url=community.extrachill.com
	 *
	 * @subcommand toggle-homepage
	 * @when after_wp_load
	 */
	public function toggle_homepage( $args ) {
		$forum_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $forum_id ) {
			WP_CLI::error( 'A forum_id is required.' );
		}

		$ability = wp_get_ability( 'extrachill/community-toggle-forum-homepage' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-toggle-forum-homepage ability not available.' );
		}

		$result = $ability->execute( array( 'forum_id' => $forum_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$state = $result['show_on_homepage'] ? 'shown on' : 'hidden from';
		WP_CLI::success( sprintf( 'Forum "%s" (%d) is now %s the homepage.', $result['title'], $result['forum_id'], $state ) );
	}

	/**
	 * Toggle an upvote on a topic or reply.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : bbPress topic or reply post ID.
	 *
	 * <type>
	 * : Post type: topic or reply.
	 *
	 * [--user=<user>]
	 * : User ID, login, or email (defaults to current user).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community upvote 456 topic --user=chubes --url=community.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function upvote( $args, $assoc_args ) {
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$type    = isset( $args[1] ) ? (string) $args[1] : '';

		if ( ! $post_id ) {
			WP_CLI::error( 'A post_id is required.' );
		}
		if ( ! in_array( $type, array( 'topic', 'reply' ), true ) ) {
			WP_CLI::error( 'Type must be "topic" or "reply".' );
		}

		$input = array(
			'post_id' => $post_id,
			'type'    => $type,
		);

		if ( isset( $assoc_args['user'] ) ) {
			$user = $this->resolve_user( (string) $assoc_args['user'] );
			if ( ! $user ) {
				WP_CLI::error( 'User not found.' );
			}
			$input['user_id'] = (int) $user->ID;
		}

		$ability = wp_get_ability( 'extrachill/community-upvote' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-upvote ability not available.' );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
		}

		WP_CLI::success( sprintf( '%s (count: %d)', $result['message'], $result['new_count'] ) );
	}

	/**
	 * Flush community caches.
	 *
	 * Clears leaderboard, recent feed, forum stats, and edge caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill community flush-cache --url=community.extrachill.com
	 *
	 * @subcommand flush-cache
	 * @when after_wp_load
	 */
	public function flush_cache( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/community-flush-cache' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/community-flush-cache ability not available.' );
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Community caches flushed.' );
	}

	/**
	 * Resolve a user identifier to a WP_User.
	 *
	 * @param string $identifier User ID, login, or email.
	 * @return \WP_User|false
	 */
	private function resolve_user( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return get_user_by( 'id', (int) $identifier );
		}

		if ( is_email( $identifier ) ) {
			return get_user_by( 'email', $identifier );
		}

		return get_user_by( 'login', $identifier );
	}
}
