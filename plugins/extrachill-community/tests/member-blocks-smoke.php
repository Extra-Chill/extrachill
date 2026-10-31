<?php
/** Member-to-member block abilities and centralized read filtering. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
}

class WP_Query {
	public $query_vars;
	public function __construct( $vars = array() ) { $this->query_vars = $vars; }
	public function get( $key ) { return $this->query_vars[ $key ] ?? ''; }
}

$GLOBALS['_mb_user']      = 0;
$GLOBALS['_mb_meta']      = array();
$GLOBALS['_mb_users']     = array( 1 => 'Alice', 2 => 'Bob', 3 => 'Carol' );
$GLOBALS['_mb_abilities'] = array();
$GLOBALS['_mb_filters']   = array();
$GLOBALS['_mb_actions']   = array();

function add_action( $hook, $cb ) { $GLOBALS['_mb_filters'][ $hook ] = $cb; }
function add_filter( $hook, $cb ) { $GLOBALS['_mb_filters'][ $hook ] = $cb; }
function do_action( $hook, ...$args ) { $GLOBALS['_mb_actions'][] = array( $hook, $args ); }
function __( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_user_logged_in() { return $GLOBALS['_mb_user'] > 0; }
function get_current_user_id() { return $GLOBALS['_mb_user']; }
function get_user_meta( $user_id, $key ) { return $GLOBALS['_mb_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['_mb_meta'][ $user_id ][ $key ] = $value; }
function delete_user_meta( $user_id, $key ) { unset( $GLOBALS['_mb_meta'][ $user_id ][ $key ] ); }
function get_userdata( $id ) {
	return isset( $GLOBALS['_mb_users'][ $id ] ) ? (object) array( 'ID' => $id, 'display_name' => $GLOBALS['_mb_users'][ $id ] ) : false;
}
function extrachill_get_user_profile_url( $id ) { return 'https://community.example.test/u/' . (int) $id; }
function wp_register_ability( $name, $args ) { $GLOBALS['_mb_abilities'][ $name ] = $args; }
function bbp_get_reply_post_type() { return 'reply'; }

$GLOBALS['wpdb'] = new class() {
	public $posts = 'wp_2_posts';
	public function prepare( $query, $args ) {
		return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $query ), $args );
	}
};

function mb_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function mb_run( $name, $input = array() ) {
	$ability = $GLOBALS['_mb_abilities'][ $name ];
	if ( ! call_user_func( $ability['permission_callback'] ) ) {
		return 'forbidden';
	}
	return call_user_func( $ability['execute_callback'], $input );
}

require dirname( __DIR__ ) . '/inc/moderation/member-blocks.php';
require dirname( __DIR__ ) . '/inc/moderation/member-block-abilities.php';
extrachill_community_register_member_block_abilities();

// Registration contract.
foreach ( array( 'extrachill/community-block-user', 'extrachill/community-unblock-user', 'extrachill/community-list-blocked-users' ) as $name ) {
	mb_assert( isset( $GLOBALS['_mb_abilities'][ $name ] ), "{$name} must be registered." );
	mb_assert( true === $GLOBALS['_mb_abilities'][ $name ]['meta']['show_in_rest'], "{$name} must be REST exposed." );
	mb_assert( false === $GLOBALS['_mb_abilities'][ $name ]['input_schema']['additionalProperties'], "{$name} input must be exact." );
}
mb_assert( array() === $GLOBALS['_mb_abilities']['extrachill/community-list-blocked-users']['input_schema']['properties'], 'Listing is self-scoped: it accepts no user_id.' );
mb_assert( true === $GLOBALS['_mb_abilities']['extrachill/community-list-blocked-users']['meta']['annotations']['readonly'], 'Listing must be read-only.' );

// Permissions: anonymous callers are refused.
$GLOBALS['_mb_user'] = 0;
mb_assert( 'forbidden' === mb_run( 'extrachill/community-block-user', array( 'user_id' => 2 ) ), 'Anonymous callers cannot block.' );
mb_assert( 'forbidden' === mb_run( 'extrachill/community-unblock-user', array( 'user_id' => 2 ) ), 'Anonymous callers cannot unblock.' );
mb_assert( 'forbidden' === mb_run( 'extrachill/community-list-blocked-users' ), 'Anonymous callers cannot list blocks.' );

// Validation.
$GLOBALS['_mb_user'] = 1;
$self = mb_run( 'extrachill/community-block-user', array( 'user_id' => 1 ) );
mb_assert( is_wp_error( $self ) && 'cannot_block_self' === $self->get_error_code() && 400 === $self->data['status'], 'Members cannot block themselves.' );
$missing = mb_run( 'extrachill/community-block-user', array( 'user_id' => 999 ) );
mb_assert( is_wp_error( $missing ) && 'user_not_found' === $missing->get_error_code(), 'Unknown members cannot be blocked.' );
$bad = mb_run( 'extrachill/community-block-user', array() );
mb_assert( is_wp_error( $bad ) && 'missing_user_id' === $bad->get_error_code(), 'user_id is required.' );

// Block is idempotent and self-scoped.
$blocked = mb_run( 'extrachill/community-block-user', array( 'user_id' => 2 ) );
mb_assert( array( 'user_id' => 2, 'blocked' => true, 'total' => 1 ) === $blocked, 'Blocking returns the new state.' );
$again = mb_run( 'extrachill/community-block-user', array( 'user_id' => 2 ) );
mb_assert( 1 === $again['total'], 'Blocking twice does not duplicate.' );
mb_assert( 1 === count( array_filter( $GLOBALS['_mb_actions'], static function ( $a ) { return 'extrachill_community_member_blocked' === $a[0]; } ) ), 'Block action fires once.' );
mb_assert( array( 2 ) === $GLOBALS['_mb_meta'][1][ EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META ], 'Blocks are stored on the blocking member only.' );
mb_assert( empty( $GLOBALS['_mb_meta'][2] ), 'Nothing is written to the blocked member.' );

$list = mb_run( 'extrachill/community-list-blocked-users' );
mb_assert( 1 === $list['total'] && 'Bob' === $list['users'][0]['display_name'] && 2 === $list['users'][0]['user_id'], 'Listing returns the caller\'s blocks.' );

$GLOBALS['_mb_user'] = 2;
$bob_list = mb_run( 'extrachill/community-list-blocked-users' );
mb_assert( 0 === $bob_list['total'], 'The blocked member never sees who blocked them.' );

// Unblock.
$GLOBALS['_mb_user'] = 1;
$unblocked = mb_run( 'extrachill/community-unblock-user', array( 'user_id' => 2 ) );
mb_assert( array( 'user_id' => 2, 'blocked' => false, 'total' => 0 ) === $unblocked, 'Unblocking returns the new state.' );
mb_assert( ! isset( $GLOBALS['_mb_meta'][1][ EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META ] ), 'Empty block lists are deleted.' );
$self_unblock = mb_run( 'extrachill/community-unblock-user', array( 'user_id' => 1 ) );
mb_assert( is_wp_error( $self_unblock ), 'Self-unblock is refused like self-block.' );

// Cap.
$GLOBALS['_mb_meta'][1][ EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META ] = range( 1000, 1000 + EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX - 1 );
$capped = mb_run( 'extrachill/community-block-user', array( 'user_id' => 3 ) );
mb_assert( is_wp_error( $capped ) && 'block_limit_reached' === $capped->get_error_code(), 'Block list is capped.' );
$GLOBALS['_mb_meta'][1][ EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META ] = array( 2, '3', 2, 0, -4, 'x' );
mb_assert( array( 2, 3 ) === extrachill_community_get_member_blocked_ids( 1 ), 'Stored lists are normalized.' );

// Central read filter.
$args = extrachill_community_exclude_member_blocked_authors( array( 'post_type' => 'topic', 'author__not_in' => array( 9 ) ) );
mb_assert( array( 9, 2, 3 ) === $args['author__not_in'], 'Blocked authors merge into author__not_in.' );
$GLOBALS['_mb_user'] = 0;
mb_assert( ! isset( extrachill_community_exclude_member_blocked_authors( array() )['author__not_in'] ), 'Anonymous viewers are unfiltered.' );
$GLOBALS['_mb_user'] = 3;
mb_assert( ! isset( extrachill_community_exclude_member_blocked_authors( array() )['author__not_in'] ), 'Viewers without blocks are unfiltered.' );

// bbPress web views.
$GLOBALS['_mb_user'] = 1;
mb_assert( array( 2, 3 ) === call_user_func( $GLOBALS['_mb_filters']['bbp_after_has_topics_parse_args'], array() )['author__not_in'], 'bbPress topic loops hide blocked authors.' );
$reply_args = call_user_func( $GLOBALS['_mb_filters']['bbp_after_has_replies_parse_args'], array( 'post_type' => array( 'topic', 'reply' ) ) );
mb_assert( ! isset( $reply_args['author__not_in'] ) && array( 2, 3 ) === $reply_args['ec_member_blocked_reply_authors'], 'bbPress reply loops keep the lead topic and mark reply authors for exclusion.' );
$where = extrachill_community_member_blocks_posts_where( ' AND 1=1', new WP_Query( $reply_args ) );
mb_assert( " AND 1=1 AND NOT ( wp_2_posts.post_type = 'reply' AND wp_2_posts.post_author IN ( 2, 3 ) )" === $where, 'Reply exclusion is scoped to reply posts: ' . $where );
mb_assert( ' AND 1=1' === extrachill_community_member_blocks_posts_where( ' AND 1=1', new WP_Query( array() ) ), 'Unrelated queries are untouched.' );

echo "PASS: Member blocks are self-scoped, capped, and filter community reads.\n";
