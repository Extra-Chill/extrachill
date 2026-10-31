<?php
/**
 * Content Reports
 *
 * Members report topics, replies, or member profiles for moderator review.
 * Reports are stored as a non-public `ec_report` custom post type on the
 * community site: the reporter is the post author, the optional note is the
 * post content, the review state is the post status, and the target lives in
 * post meta. Moderators work the queue from wp-admin
 * (see content-reports-admin.php).
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_COMMUNITY_REPORT_POST_TYPE = 'ec_report';
const EXTRACHILL_COMMUNITY_REPORT_OPEN      = 'ec_report_open';
const EXTRACHILL_COMMUNITY_REPORT_RESOLVED  = 'ec_report_resolved';
const EXTRACHILL_COMMUNITY_REPORT_DISMISSED = 'ec_report_dismissed';
const EXTRACHILL_COMMUNITY_REPORT_NOTE_MAX  = 1000;

/**
 * Allowed report reasons, keyed by slug.
 *
 * @return array<string,string>
 */
function extrachill_community_report_reasons() {
	return array(
		'spam'       => __( 'Spam', 'extra-chill-community' ),
		'harassment' => __( 'Harassment', 'extra-chill-community' ),
		'hate'       => __( 'Hate speech', 'extra-chill-community' ),
		'sexual'     => __( 'Sexual content', 'extra-chill-community' ),
		'other'      => __( 'Other', 'extra-chill-community' ),
	);
}

/**
 * Report statuses, keyed by post status.
 *
 * @return array<string,string>
 */
function extrachill_community_report_statuses() {
	return array(
		EXTRACHILL_COMMUNITY_REPORT_OPEN      => __( 'Open', 'extra-chill-community' ),
		EXTRACHILL_COMMUNITY_REPORT_RESOLVED  => __( 'Resolved', 'extra-chill-community' ),
		EXTRACHILL_COMMUNITY_REPORT_DISMISSED => __( 'Dismissed', 'extra-chill-community' ),
	);
}

/**
 * Register the private report post type and its review statuses.
 */
function extrachill_community_register_report_post_type() {
	register_post_type(
		EXTRACHILL_COMMUNITY_REPORT_POST_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Reports', 'extra-chill-community' ),
				'singular_name' => __( 'Report', 'extra-chill-community' ),
				'menu_name'     => __( 'Reports', 'extra-chill-community' ),
				'all_items'     => __( 'Reports', 'extra-chill-community' ),
				'edit_item'     => __( 'Review Report', 'extra-chill-community' ),
				'search_items'  => __( 'Search Reports', 'extra-chill-community' ),
				'not_found'     => __( 'No reports.', 'extra-chill-community' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-flag',
			'menu_position'       => 58,
			'supports'            => array( 'title' ),
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => false,
			'map_meta_cap'        => false,
			'capabilities'        => array(
				'edit_post'              => 'moderate',
				'read_post'              => 'moderate',
				'delete_post'            => 'moderate',
				'edit_posts'             => 'moderate',
				'edit_others_posts'      => 'moderate',
				'delete_posts'           => 'moderate',
				'delete_others_posts'    => 'moderate',
				'read_private_posts'     => 'moderate',
				'publish_posts'          => 'do_not_allow',
				'create_posts'           => 'do_not_allow',
				'edit_published_posts'   => 'moderate',
				'delete_published_posts' => 'moderate',
			),
		)
	);

	$label_counts = array(
		/* translators: %s: number of reports. */
		EXTRACHILL_COMMUNITY_REPORT_OPEN      => _n_noop( 'Open <span class="count">(%s)</span>', 'Open <span class="count">(%s)</span>', 'extra-chill-community' ),
		/* translators: %s: number of reports. */
		EXTRACHILL_COMMUNITY_REPORT_RESOLVED  => _n_noop( 'Resolved <span class="count">(%s)</span>', 'Resolved <span class="count">(%s)</span>', 'extra-chill-community' ),
		/* translators: %s: number of reports. */
		EXTRACHILL_COMMUNITY_REPORT_DISMISSED => _n_noop( 'Dismissed <span class="count">(%s)</span>', 'Dismissed <span class="count">(%s)</span>', 'extra-chill-community' ),
	);

	foreach ( extrachill_community_report_statuses() as $status => $label ) {
		register_post_status(
			$status,
			array(
				'label'                     => $label,
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => $label_counts[ $status ],
			)
		);
	}
}
add_action( 'init', 'extrachill_community_register_report_post_type' );

/**
 * Validate and describe a report target.
 *
 * @param array $input   Ability input.
 * @param int   $user_id Reporter ID.
 * @return array{type:string,id:int,author_id:int,label:string,url:string}|WP_Error
 */
function extrachill_community_resolve_report_target( $input, $user_id ) {
	$post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$target_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( ( $post_id > 0 ) === ( $target_id > 0 ) ) {
		return new WP_Error( 'invalid_report_target', 'Provide exactly one of post_id or user_id.', array( 'status' => 400 ) );
	}

	if ( $target_id > 0 ) {
		$target = get_userdata( $target_id );
		if ( ! $target ) {
			return new WP_Error( 'user_not_found', 'Member not found.', array( 'status' => 404 ) );
		}
		if ( $target_id === (int) $user_id ) {
			return new WP_Error( 'cannot_report_self', 'You cannot report yourself.', array( 'status' => 400 ) );
		}

		$url = function_exists( 'extrachill_get_user_profile_url' ) ? (string) extrachill_get_user_profile_url( $target_id ) : '';

		return array(
			'type'      => 'user',
			'id'        => $target_id,
			'author_id' => $target_id,
			'label'     => sprintf( 'member %s', $target->display_name ),
			'url'       => $url,
		);
	}

	if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.', array( 'status' => 500 ) );
	}

	$post       = get_post( $post_id );
	$topic_type = bbp_get_topic_post_type();
	$reply_type = bbp_get_reply_post_type();
	if ( ! $post || ! in_array( $post->post_type, array( $topic_type, $reply_type ), true ) ) {
		return new WP_Error( 'content_not_found', 'Only topics and replies can be reported.', array( 'status' => 404 ) );
	}

	$visible_statuses = array( bbp_get_public_status_id(), bbp_get_closed_status_id() );
	$topic            = $topic_type === $post->post_type ? $post : get_post( (int) $post->post_parent );
	if ( ! in_array( $post->post_status, $visible_statuses, true ) || ! $topic || ! in_array( $topic->post_status, $visible_statuses, true ) || ! extrachill_community_can_read_forum( (int) $topic->post_parent ) ) {
		return new WP_Error( 'content_not_found', 'Content is unavailable.', array( 'status' => 404 ) );
	}

	if ( (int) $post->post_author === (int) $user_id ) {
		return new WP_Error( 'cannot_report_self', 'You cannot report your own content.', array( 'status' => 400 ) );
	}

	$is_topic = $topic_type === $post->post_type;

	return array(
		'type'      => $is_topic ? 'topic' : 'reply',
		'id'        => (int) $post->ID,
		'author_id' => (int) $post->post_author,
		'label'     => sprintf( '%s "%s"', $is_topic ? 'topic' : 'reply in', html_entity_decode( get_the_title( $topic ), ENT_QUOTES, 'UTF-8' ) ),
		'url'       => $is_topic ? (string) bbp_get_topic_permalink( $post->ID ) : (string) bbp_get_reply_url( $post->ID ),
	);
}

/**
 * Find an open report by the same reporter against the same target.
 *
 * @param int    $user_id     Reporter ID.
 * @param string $target_type topic|reply|user.
 * @param int    $target_id   Target ID.
 * @return int Report ID, or 0.
 */
function extrachill_community_find_open_report( $user_id, $target_type, $target_id ) {
	$ids = get_posts(
		array(
			'post_type'        => EXTRACHILL_COMMUNITY_REPORT_POST_TYPE,
			'post_status'      => EXTRACHILL_COMMUNITY_REPORT_OPEN,
			'author'           => (int) $user_id,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded per-reporter lookup.
				array(
					'key'   => '_ec_report_target_type',
					'value' => $target_type,
				),
				array(
					'key'   => '_ec_report_target_id',
					'value' => (int) $target_id,
					'type'  => 'NUMERIC',
				),
			),
		)
	);

	return empty( $ids ) ? 0 : (int) $ids[0];
}

/**
 * Reports a member may file per hour.
 *
 * @return int
 */
function extrachill_community_report_rate_limit() {
	return max( 1, (int) apply_filters( 'extrachill_community_report_rate_limit', 10 ) );
}

/**
 * Timestamps of a member's reports within the last hour.
 *
 * @param int $user_id Reporter ID.
 * @return int[]
 */
function extrachill_community_recent_report_times( $user_id ) {
	$times  = get_transient( 'ec_report_rate_' . (int) $user_id );
	$cutoff = time() - HOUR_IN_SECONDS;

	return array_values(
		array_filter(
			is_array( $times ) ? array_map( 'intval', $times ) : array(),
			static function ( $time ) use ( $cutoff ) {
				return $time > $cutoff;
			}
		)
	);
}

/**
 * Record a filed report against the member's hourly rate limit.
 *
 * @param int $user_id Reporter ID.
 */
function extrachill_community_record_report_time( $user_id ) {
	$times   = extrachill_community_recent_report_times( $user_id );
	$times[] = time();
	set_transient( 'ec_report_rate_' . (int) $user_id, $times, HOUR_IN_SECONDS );
}

/**
 * Moderators who should be notified about new reports.
 *
 * @param int $reporter_id Reporter ID (never notified about their own report).
 * @return int[]
 */
function extrachill_community_report_moderator_ids( $reporter_id ) {
	$roles = array( 'administrator' );
	if ( function_exists( 'bbp_get_keymaster_role' ) ) {
		$roles[] = bbp_get_keymaster_role();
	}
	if ( function_exists( 'bbp_get_moderator_role' ) ) {
		$roles[] = bbp_get_moderator_role();
	}

	$ids = get_users(
		array(
			'role__in' => $roles,
			'fields'   => 'ID',
			'number'   => 25,
		)
	);

	/**
	 * Filter the moderators notified about a new content report.
	 *
	 * @param int[] $ids         Moderator user IDs.
	 * @param int   $reporter_id Reporter ID.
	 */
	$ids = (array) apply_filters( 'extrachill_community_report_moderator_ids', array_map( 'intval', (array) $ids ), (int) $reporter_id );

	return array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), array( (int) $reporter_id, 0 ) ) );
}

/**
 * Notify moderators about a new report (in-app notification + email).
 *
 * @param int   $report_id   Report ID.
 * @param int   $reporter_id Reporter ID.
 * @param array $target      Resolved target.
 * @param string $reason     Reason slug.
 * @param string $note       Reporter note.
 */
function extrachill_community_notify_report( $report_id, $reporter_id, array $target, $reason, $note ) {
	$reasons   = extrachill_community_report_reasons();
	$queue_url = admin_url( 'edit.php?post_type=' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE . '&post_status=' . EXTRACHILL_COMMUNITY_REPORT_OPEN );
	$title     = sprintf( 'Reported for %s: %s', strtolower( $reasons[ $reason ] ?? $reason ), $target['label'] );

	if ( function_exists( 'ec_users_notify_with_receipts' ) ) {
		$moderators = extrachill_community_report_moderator_ids( $reporter_id );
		if ( $moderators ) {
			ec_users_notify_with_receipts(
				$moderators,
				array(
					'actor_id'        => (int) $reporter_id,
					'type'            => 'content_report',
					'title'           => $title,
					'link'            => $queue_url,
					'item_id'         => (int) $report_id,
					'producer'        => 'extrachill-community/reports',
					'idempotency_key' => 'report:' . (int) $report_id,
				)
			);
		}
	}

	/**
	 * Filter the email recipients for new content reports.
	 *
	 * @param string[] $recipients Email addresses. Defaults to the site admin email.
	 * @param int      $report_id  Report ID.
	 */
	$recipients = array_filter( (array) apply_filters( 'extrachill_community_report_email_recipients', array( get_option( 'admin_email' ) ), (int) $report_id ) );
	if ( empty( $recipients ) ) {
		return;
	}

	$reporter = get_userdata( (int) $reporter_id );
	$lines    = array(
		'A community member filed a report.',
		'',
		'Target: ' . $target['label'] . ( $target['url'] ? ' (' . $target['url'] . ')' : '' ),
		'Reason: ' . ( $reasons[ $reason ] ?? $reason ),
		'Reporter: ' . ( $reporter ? $reporter->display_name . ' (#' . (int) $reporter_id . ')' : '#' . (int) $reporter_id ),
	);
	if ( '' !== $note ) {
		$lines[] = 'Note: ' . $note;
	}
	$lines[] = '';
	$lines[] = 'Review queue: ' . $queue_url;

	wp_mail( $recipients, '[Extra Chill Community] ' . $title, implode( "\n", $lines ) );
}

add_action( 'wp_abilities_api_init', 'extrachill_community_register_report_content_ability' );

/**
 * Register the report-content ability.
 */
function extrachill_community_register_report_content_ability() {
	wp_register_ability(
		'extrachill/community-report-content',
		array(
			'label'               => __( 'Report Content', 'extra-chill-community' ),
			'description'         => __( 'Report a topic, reply (post_id), or member profile (user_id) to the community moderators. Provide exactly one target.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Topic or reply ID to report.',
					),
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Member to report (profile).',
					),
					'reason'  => array(
						'type' => 'string',
						'enum' => array_keys( extrachill_community_report_reasons() ),
					),
					'note'    => array(
						'type'      => 'string',
						'maxLength' => EXTRACHILL_COMMUNITY_REPORT_NOTE_MAX,
					),
				),
				'required'             => array( 'reason' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'report_id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'status'       => array(
						'type' => 'string',
						'enum' => array( 'open' ),
					),
					'deduplicated' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'report_id', 'status', 'deduplicated' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_community_ability_report_content',
			'permission_callback' => 'extrachill_community_report_content_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Only logged-in members may report.
 *
 * @return bool
 */
function extrachill_community_report_content_permission() {
	return is_user_logged_in();
}

/**
 * File a content report.
 *
 * Re-reporting the same target while an earlier report from the same member
 * is still open returns that report (deduplicated) without counting against
 * the hourly rate limit.
 *
 * @param mixed $input Ability input (normalized to an array).
 * @return array|WP_Error
 */
function extrachill_community_ability_report_content( $input ) {
	$input   = is_array( $input ) ? $input : array();
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$reason = isset( $input['reason'] ) ? (string) $input['reason'] : '';
	if ( ! array_key_exists( $reason, extrachill_community_report_reasons() ) ) {
		return new WP_Error( 'invalid_reason', 'Reason must be one of: spam, harassment, hate, sexual, other.', array( 'status' => 400 ) );
	}

	$note = isset( $input['note'] ) ? trim( (string) $input['note'] ) : '';
	if ( mb_strlen( $note ) > EXTRACHILL_COMMUNITY_REPORT_NOTE_MAX ) {
		return new WP_Error( 'note_too_long', 'Note must be 1000 characters or fewer.', array( 'status' => 400 ) );
	}
	$note = sanitize_textarea_field( $note );

	$target = extrachill_community_resolve_report_target( $input, $user_id );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	$existing = extrachill_community_find_open_report( $user_id, $target['type'], $target['id'] );
	if ( $existing ) {
		return array(
			'report_id'    => $existing,
			'status'       => 'open',
			'deduplicated' => true,
		);
	}

	if ( count( extrachill_community_recent_report_times( $user_id ) ) >= extrachill_community_report_rate_limit() ) {
		return new WP_Error( 'rate_limited', 'You have filed too many reports recently. Please try again later.', array( 'status' => 429 ) );
	}

	$report_id = wp_insert_post(
		array(
			'post_type'    => EXTRACHILL_COMMUNITY_REPORT_POST_TYPE,
			'post_status'  => EXTRACHILL_COMMUNITY_REPORT_OPEN,
			'post_author'  => $user_id,
			'post_title'   => sprintf( '%s: %s', ucfirst( $reason ), $target['label'] ),
			'post_content' => $note,
			'meta_input'   => array(
				'_ec_report_target_type'   => $target['type'],
				'_ec_report_target_id'     => $target['id'],
				'_ec_report_target_author' => $target['author_id'],
				'_ec_report_target_url'    => $target['url'],
				'_ec_report_reason'        => $reason,
			),
		),
		true
	);

	if ( is_wp_error( $report_id ) ) {
		return new WP_Error( 'report_failed', 'The report could not be saved.', array( 'status' => 500 ) );
	}

	extrachill_community_record_report_time( $user_id );
	extrachill_community_notify_report( (int) $report_id, $user_id, $target, $reason, $note );

	/**
	 * Fires after a content report is filed.
	 *
	 * @param int    $report_id Report ID.
	 * @param int    $user_id   Reporter ID.
	 * @param array  $target    Resolved target (type, id, author_id, label, url).
	 * @param string $reason    Reason slug.
	 */
	do_action( 'extrachill_community_content_reported', (int) $report_id, $user_id, $target, $reason );

	return array(
		'report_id'    => (int) $report_id,
		'status'       => 'open',
		'deduplicated' => false,
	);
}
