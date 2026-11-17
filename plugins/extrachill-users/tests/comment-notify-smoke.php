<?php
/**
 * Standalone smoke for comment notifications (#1634).
 *
 * Exercises recipients, self-exclusion, the reply/event de-duplication,
 * member blocks, the approval edges, and per-comment idempotency keys against
 * a recording notification substrate. The WordPress-backed suite lives in
 * tests/unit/CommentNotifyTest.php.
 *
 * Run: php tests/comment-notify-smoke.php
 *
 * @package ExtraChill\Users
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Comment {
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

class WP_Post {
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

const EC_USERS_COMMENT_ACTIVITY_TYPES = array( 'comment', '' );

$GLOBALS['cn_blog']      = 1;
$GLOBALS['cn_comments']  = array();
$GLOBALS['cn_posts']     = array();
$GLOBALS['cn_marks']     = array();
$GLOBALS['cn_meta']      = array();
$GLOBALS['cn_rows']      = array();
$GLOBALS['cn_calls']     = array();
$GLOBALS['cn_actions']   = array();
$GLOBALS['cn_filters']   = array();
$GLOBALS['cn_scheduled'] = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['cn_actions'][ $hook ][] = $callback;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['cn_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}
function add_filter( $hook, $callback ) {
	$GLOBALS['cn_filters'][ $hook ] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['cn_filters'][ $hook ] ) ? $GLOBALS['cn_filters'][ $hook ]( $value, ...$args ) : $value;
}
function get_current_blog_id() {
	return $GLOBALS['cn_blog'];
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['cn_prev_blog'] = $GLOBALS['cn_blog'];
	$GLOBALS['cn_blog']      = (int) $blog_id;
}
function restore_current_blog() {
	$GLOBALS['cn_blog'] = $GLOBALS['cn_prev_blog'];
}
function ec_users_is_comment_activity_blog( $blog_id ) {
	return in_array( (int) $blog_id, array( 1, 3 ), true );
}
function get_comment( $id ) {
	return $GLOBALS['cn_comments'][ $GLOBALS['cn_blog'] ][ (int) $id ] ?? null;
}
function get_post( $id ) {
	return $GLOBALS['cn_posts'][ $GLOBALS['cn_blog'] ][ (int) $id ] ?? null;
}
function get_userdata( $id ) {
	return in_array( (int) $id, array( 11, 12, 13, 14, 15 ), true ) ? (object) array( 'ID' => (int) $id ) : false;
}
function get_comment_link( $comment ) {
	return 'https://blog' . $GLOBALS['cn_blog'] . '.test/p/' . (int) $comment->comment_post_ID . '/#comment-' . (int) $comment->comment_ID;
}
function get_permalink( $post ) {
	return 'https://blog' . $GLOBALS['cn_blog'] . '.test/p/' . (int) $post->ID . '/';
}
function get_the_title( $post ) {
	return $post->post_title;
}
function get_user_meta( $user_id, $key ) {
	return $GLOBALS['cn_meta'][ (int) $user_id ][ $key ] ?? '';
}
function ec_users_get_event_marked_user_ids( int $event_id, int $blog_id = 0 ): array {
	return $GLOBALS['cn_marks'][ $blog_id ][ $event_id ] ?? array();
}
function as_enqueue_async_action( $hook, $args, $group, $unique ) {
	$GLOBALS['cn_scheduled'][] = array( $hook, $args, $group, $unique );
	return 1;
}

/**
 * Recording substrate with the real unique (user, producer + key) contract.
 */
function ec_users_notify_with_receipts( $user_ids, array $data ): array {
	$GLOBALS['cn_calls'][] = array( (array) $user_ids, $data );
	foreach ( (array) $user_ids as $user_id ) {
		$GLOBALS['cn_rows'][ $user_id . '|' . $data['producer'] . '|' . $data['idempotency_key'] ] = array_merge( $data, array( 'user_id' => (int) $user_id ) );
	}
	return array();
}

require dirname( __DIR__ ) . '/inc/notifications/comment-notify.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
};

$rows_of = static function ( $type ) {
	$users = array();
	foreach ( $GLOBALS['cn_rows'] as $row ) {
		if ( $type === $row['type'] ) {
			$users[] = $row['user_id'];
		}
	}
	sort( $users );
	return $users;
};

$make_comment = static function ( $blog, $id, $post, $user, $parent = 0, $approved = '1', $type = 'comment' ) {
	$GLOBALS['cn_comments'][ $blog ][ $id ] = new WP_Comment(
		array(
			'comment_ID'       => $id,
			'comment_post_ID'  => $post,
			'user_id'          => $user,
			'comment_parent'   => $parent,
			'comment_approved' => $approved,
			'comment_type'     => $type,
		)
	);
	return $GLOBALS['cn_comments'][ $blog ][ $id ];
};

// Fixtures: blog 1 = main (article 100), blog 3 = events (event 200), blog 9 = not hosting.
$GLOBALS['cn_posts'][1][100] = new WP_Post( array(
	'ID'            => 100,
	'post_type'     => 'post',
	'post_status'   => 'publish',
	'post_password' => '',
	'post_title'    => 'A Review',
) );
$GLOBALS['cn_posts'][3][200] = new WP_Post( array(
	'ID'            => 200,
	'post_type'     => 'data_machine_events',
	'post_status'   => 'publish',
	'post_password' => '',
	'post_title'    => 'Amanda Russell at The Royal',
) );
$GLOBALS['cn_posts'][9][300] = new WP_Post( array(
	'ID'            => 300,
	'post_type'     => 'post',
	'post_status'   => 'publish',
	'post_password' => '',
	'post_title'    => 'Elsewhere',
) );
$GLOBALS['cn_marks'][3][200] = array( 11, 12, 13, 14 );
$GLOBALS['cn_marks'][1][100] = array( 13 );

// (a) Reply to another member's comment on an article.
$make_comment( 1, 1, 100, 11 );
$make_comment( 1, 2, 100, 12, 1 );
$addressed = ec_users_comment_notify_deliver( 1, 2 );
$assert( array( 11 ) === $addressed['reply'] && array() === $addressed['event'], 'An article reply notifies only the parent comment author.' );
$row = $GLOBALS['cn_rows'][ '11|' . EC_USERS_COMMENT_REPLY_PRODUCER . '|comment:2:blog:1' ];
$assert( 'comment_reply' === $row['type'] && 12 === $row['actor_id'] && 100 === $row['item_id'], 'Reply rows carry type, actor, and post.' );
$assert( 'A Review' === $row['title'] && 'https://blog1.test/p/100/#comment-2' === $row['link'], 'Reply rows link to the comment anchor with the post title.' );

// Self-reply: nothing.
$GLOBALS['cn_rows'] = array();
$make_comment( 1, 3, 100, 11, 1 );
ec_users_comment_notify_deliver( 1, 3 );
$assert( array() === $GLOBALS['cn_rows'], 'Replying to your own comment notifies nobody.' );

// (b) Event comment replying to a member who is also going.
$GLOBALS['cn_rows'] = array();
$make_comment( 3, 10, 200, 11 );
$make_comment( 3, 11, 200, 12, 10 );
$addressed = ec_users_comment_notify_deliver( 3, 11 );
$assert( array( 11 ) === $rows_of( 'comment_reply' ), 'The parent author gets the reply notification.' );
$assert( array( 13, 14 ) === $rows_of( 'event_comment' ), 'Members going get the event notification, minus commenter and reply recipient.' );
$assert( array( 13, 14 ) === $addressed['event'], 'Delivery reports the event recipients.' );
$event_row = $GLOBALS['cn_rows'][ '13|' . EC_USERS_EVENT_COMMENT_PRODUCER . '|comment:11:blog:3' ];
$assert( 'Amanda Russell at The Royal' === $event_row['title'] && 200 === $event_row['item_id'], 'Event rows name the event.' );

// Idempotency: replays (inline, comment_post, transition) reuse the same keys.
$before = $GLOBALS['cn_rows'];
add_filter( 'ec_users_comment_notify_async', static function () { return false;
} );
ec_users_comment_notify_deliver( 3, 11 );
$GLOBALS['cn_blog'] = 3;
do_action( 'comment_post', 11, 1 );
do_action( 'transition_comment_status', 'approved', 'unapproved', get_comment( 11 ) );
$GLOBALS['cn_blog'] = 1;
$assert( $before === $GLOBALS['cn_rows'], 'Replays converge on one row per recipient.' );
foreach ( array_slice( $GLOBALS['cn_calls'], -6 ) as $call ) {
	$assert( 'comment:11:blog:3' === $call[1]['idempotency_key'], 'Every replay uses the comment + blog idempotency key.' );
}

// Approval edges.
$GLOBALS['cn_rows']  = array();
$GLOBALS['cn_calls'] = array();
$pending             = $make_comment( 3, 12, 200, 15, 0, '0' );
$GLOBALS['cn_blog']  = 3;
do_action( 'comment_post', 12, 0 );
$assert( array() === $GLOBALS['cn_calls'], 'Held comments notify nobody.' );
do_action( 'transition_comment_status', 'approved', 'approved', $pending );
$assert( array() === $GLOBALS['cn_calls'], 'Non-edge transitions are ignored.' );
$pending->comment_approved = '1';
do_action( 'transition_comment_status', 'approved', 'unapproved', $pending );
$GLOBALS['cn_blog'] = 1;
$assert( array( 11, 12, 13, 14 ) === $rows_of( 'event_comment' ), 'Pending -> approved notifies everyone going.' );

// Member blocks.
$GLOBALS['cn_rows']     = array();
$GLOBALS['cn_meta'][13] = array( 'ec_member_blocked_user_ids' => array( 12 ) );
$GLOBALS['cn_meta'][11] = array( 'ec_member_blocked_user_ids' => array( '12' ) );
ec_users_comment_notify_deliver( 3, 11 );
$assert( array() === $rows_of( 'comment_reply' ), 'A parent author who blocked the replier is not notified.' );
$assert( array( 14 ) === $rows_of( 'event_comment' ), 'Members going who blocked the commenter are skipped.' );
$GLOBALS['cn_meta'] = array();

// Guests, non-discussion types, unpublished posts, and non-hosting blogs.
$GLOBALS['cn_rows'] = array();
$make_comment( 3, 20, 200, 0, 10 );
$make_comment( 3, 21, 200, 12, 10, '1', 'pingback' );
$make_comment( 9, 30, 300, 11 );
$make_comment( 9, 31, 300, 12, 30 );
ec_users_comment_notify_deliver( 3, 20 );
ec_users_comment_notify_deliver( 3, 21 );
ec_users_comment_notify_deliver( 9, 31 );
$GLOBALS['cn_posts'][3][200]->post_status = 'draft';
ec_users_comment_notify_deliver( 3, 11 );
$GLOBALS['cn_posts'][3][200]->post_status = 'publish';
$assert( array() === $GLOBALS['cn_rows'], 'Guests, pingbacks, non-hosting blogs, and unpublished events notify nobody.' );

// Async dispatch queues one unique action per comment.
$GLOBALS['cn_filters'] = array();
$GLOBALS['cn_blog']    = 3;
do_action( 'comment_post', 11, 1 );
$GLOBALS['cn_blog'] = 1;
$assert( array( array( EC_USERS_COMMENT_NOTIFY_ACTION, array( 3, 11 ), 'extrachill-users-notifications', true ) ) === $GLOBALS['cn_scheduled'], 'Async dispatch queues one unique action keyed by blog and comment.' );
$assert( array() === $GLOBALS['cn_rows'], 'Async dispatch defers delivery.' );

echo "PASS: Comment notifications address the right members exactly once per comment.\n";
