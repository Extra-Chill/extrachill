<?php
/** Content report ability: permissions, validation, dedupe, rate limit, notification, moderation queue. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
}

class WP_Post {
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

$GLOBALS['_cr_user']          = 0;
$GLOBALS['_cr_posts']         = array();
$GLOBALS['_cr_meta']          = array();
$GLOBALS['_cr_transients']    = array();
$GLOBALS['_cr_notifications'] = array();
$GLOBALS['_cr_mail']          = array();
$GLOBALS['_cr_abilities']     = array();
$GLOBALS['_cr_filters']       = array();
$GLOBALS['_cr_next_id']       = 500;
$GLOBALS['_cr_users']         = array(
	1 => 'Reporter',
	2 => 'Author',
	3 => 'Moderator',
);

function add_action( $hook, $cb ) {}
function add_filter( $hook, $cb ) { $GLOBALS['_cr_filters'][ $hook ] = $cb; }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function _n_noop( $single, $plural ) { return array( $single, $plural ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_user_logged_in() { return $GLOBALS['_cr_user'] > 0; }
function get_current_user_id() { return $GLOBALS['_cr_user']; }
function current_user_can( $cap ) { return 'moderate' === $cap && 3 === $GLOBALS['_cr_user']; }
function get_userdata( $id ) {
	return isset( $GLOBALS['_cr_users'][ $id ] ) ? (object) array( 'ID' => $id, 'display_name' => $GLOBALS['_cr_users'][ $id ] ) : false;
}
function get_users() { return array( '3', '1' ); }
function get_option( $key ) { return 'admin_email' === $key ? 'admin@example.test' : false; }
function admin_url( $path = '' ) { return 'https://community.example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( $url ) { return $url . '&_wpnonce=x'; }
function sanitize_textarea_field( $value ) { return strip_tags( $value ); }
function wp_mail( $to, $subject, $body ) { $GLOBALS['_cr_mail'][] = compact( 'to', 'subject', 'body' ); return true; }
function ec_users_notify_with_receipts( $ids, $data ) { $GLOBALS['_cr_notifications'][] = compact( 'ids', 'data' ); return array(); }
function wp_register_ability( $name, $args ) { $GLOBALS['_cr_abilities'][ $name ] = $args; }
function extrachill_get_user_profile_url( $id ) { return 'https://community.example.test/u/' . (int) $id; }

function get_transient( $key ) { return $GLOBALS['_cr_transients'][ $key ] ?? false; }
function set_transient( $key, $value ) { $GLOBALS['_cr_transients'][ $key ] = $value; }
function get_post( $id ) { return $GLOBALS['_cr_posts'][ $id ] ?? null; }
function get_the_title( $post ) { return $post->post_title; }
function get_post_meta( $id, $key ) { return $GLOBALS['_cr_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['_cr_meta'][ $id ][ $key ] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['_cr_meta'][ $id ][ $key ] ); }
function wp_insert_post( $data ) {
	$id                         = $GLOBALS['_cr_next_id']++;
	$GLOBALS['_cr_posts'][ $id ] = new WP_Post( array_merge( $data, array( 'ID' => $id, 'post_parent' => 0 ) ) );
	$GLOBALS['_cr_meta'][ $id ]  = $data['meta_input'];
	return $id;
}
function wp_update_post( $data ) {
	$GLOBALS['_cr_posts'][ $data['ID'] ]->post_status = $data['post_status'];
	return $data['ID'];
}
function get_posts( $args ) {
	$matches = array();
	foreach ( $GLOBALS['_cr_posts'] as $post ) {
		if ( $post->post_type !== $args['post_type'] || ( isset( $args['post_status'] ) && $post->post_status !== $args['post_status'] ) ) {
			continue;
		}
		if ( isset( $args['author'] ) && (int) $post->post_author !== (int) $args['author'] ) {
			continue;
		}
		foreach ( $args['meta_query'] ?? array() as $clause ) {
			if ( (string) get_post_meta( $post->ID, $clause['key'] ) !== (string) $clause['value'] ) {
				continue 2;
			}
		}
		$matches[] = 'ids' === ( $args['fields'] ?? '' ) ? $post->ID : $post;
	}
	return $matches;
}

function bbp_get_forum_post_type() { return 'forum'; }
function bbp_get_topic_post_type() { return 'topic'; }
function bbp_get_reply_post_type() { return 'reply'; }
function bbp_get_public_status_id() { return 'publish'; }
function bbp_get_closed_status_id() { return 'closed'; }
function bbp_get_topic_permalink( $id ) { return 'https://community.example.test/t/' . (int) $id; }
function bbp_get_reply_url( $id ) { return 'https://community.example.test/r/' . (int) $id; }

function cr_post( $id, $type, $status, $parent, $author, $title = '' ) {
	$GLOBALS['_cr_posts'][ $id ] = new WP_Post(
		array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => $status,
			'post_parent' => $parent,
			'post_author' => $author,
			'post_title'  => $title ? $title : ucfirst( $type ) . ' ' . $id,
		)
	);
}

function cr_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function cr_run( $input ) {
	$ability = $GLOBALS['_cr_abilities']['extrachill/community-report-content'];
	if ( ! call_user_func( $ability['permission_callback'] ) ) {
		return 'forbidden';
	}
	return call_user_func( $ability['execute_callback'], $input );
}

function cr_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : null;
}

cr_post( 10, 'forum', 'publish', 0, 3 );
cr_post( 11, 'forum', 'private', 0, 3 );
cr_post( 100, 'topic', 'publish', 10, 2, 'Best venues' );
cr_post( 101, 'topic', 'publish', 10, 1 );
cr_post( 102, 'topic', 'publish', 11, 2 );
cr_post( 103, 'topic', 'draft', 10, 2 );
cr_post( 200, 'reply', 'publish', 100, 2 );
cr_post( 201, 'reply', 'publish', 100, 1 );

require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
require dirname( __DIR__ ) . '/inc/moderation/content-reports.php';
require dirname( __DIR__ ) . '/inc/moderation/content-reports-admin.php';
extrachill_community_register_report_content_ability();

$ability = $GLOBALS['_cr_abilities']['extrachill/community-report-content'];
cr_assert( true === $ability['meta']['show_in_rest'], 'Report ability must be REST exposed.' );
cr_assert( array( 'spam', 'harassment', 'hate', 'sexual', 'other' ) === $ability['input_schema']['properties']['reason']['enum'], 'Reason enum must match the contract.' );
cr_assert( 1000 === $ability['input_schema']['properties']['note']['maxLength'], 'Note must be capped at 1000 characters.' );
cr_assert( false === $ability['input_schema']['additionalProperties'], 'Report input must be exact.' );

// Permissions.
cr_assert( 'forbidden' === cr_run( array( 'post_id' => 100, 'reason' => 'spam' ) ), 'Anonymous callers cannot report.' );

// Validation.
$GLOBALS['_cr_user'] = 1;
cr_assert( 'invalid_report_target' === cr_code( cr_run( array( 'reason' => 'spam' ) ) ), 'A target is required.' );
cr_assert( 'invalid_report_target' === cr_code( cr_run( array( 'post_id' => 100, 'user_id' => 2, 'reason' => 'spam' ) ) ), 'Only one target is allowed.' );
cr_assert( 'invalid_reason' === cr_code( cr_run( array( 'post_id' => 100, 'reason' => 'meh' ) ) ), 'Unknown reasons are rejected.' );
cr_assert( 'note_too_long' === cr_code( cr_run( array( 'post_id' => 100, 'reason' => 'other', 'note' => str_repeat( 'x', 1001 ) ) ) ), 'Overlong notes are rejected.' );
cr_assert( 'content_not_found' === cr_code( cr_run( array( 'post_id' => 10, 'reason' => 'spam' ) ) ), 'Forums are not reportable content.' );
cr_assert( 'content_not_found' === cr_code( cr_run( array( 'post_id' => 102, 'reason' => 'spam' ) ) ), 'Content in unreadable forums cannot be reported (no existence leak).' );
cr_assert( 'content_not_found' === cr_code( cr_run( array( 'post_id' => 103, 'reason' => 'spam' ) ) ), 'Unpublished content cannot be reported.' );
cr_assert( 'user_not_found' === cr_code( cr_run( array( 'user_id' => 999, 'reason' => 'spam' ) ) ), 'Unknown members cannot be reported.' );

// Self reports.
cr_assert( 'cannot_report_self' === cr_code( cr_run( array( 'user_id' => 1, 'reason' => 'spam' ) ) ), 'Members cannot report themselves.' );
cr_assert( 'cannot_report_self' === cr_code( cr_run( array( 'post_id' => 101, 'reason' => 'spam' ) ) ), 'Members cannot report their own topics.' );
cr_assert( 'cannot_report_self' === cr_code( cr_run( array( 'post_id' => 201, 'reason' => 'spam' ) ) ), 'Members cannot report their own replies.' );
cr_assert( empty( $GLOBALS['_cr_mail'] ) && empty( $GLOBALS['_cr_notifications'] ), 'Rejected reports notify nobody.' );

// Happy path.
$report = cr_run( array( 'post_id' => 200, 'reason' => 'harassment', 'note' => '<b>rude</b>' ) );
cr_assert( is_array( $report ) && false === $report['deduplicated'] && 'open' === $report['status'], 'A valid report is filed.' );
$stored = get_post( $report['report_id'] );
cr_assert( EXTRACHILL_COMMUNITY_REPORT_POST_TYPE === $stored->post_type && EXTRACHILL_COMMUNITY_REPORT_OPEN === $stored->post_status && 1 === $stored->post_author, 'Reports are open ec_report posts authored by the reporter.' );
cr_assert( 'rude' === $stored->post_content, 'Notes are sanitized.' );
cr_assert( 'reply' === get_post_meta( $report['report_id'], '_ec_report_target_type' ) && 200 === get_post_meta( $report['report_id'], '_ec_report_target_id' ) && 2 === get_post_meta( $report['report_id'], '_ec_report_target_author' ), 'Target is stored in meta.' );
cr_assert( 1 === count( $GLOBALS['_cr_notifications'] ), 'Moderators get one in-app notification batch.' );
$notification = $GLOBALS['_cr_notifications'][0];
cr_assert( array( 3 ) === $notification['ids'], 'Moderators are notified, never the reporter.' );
cr_assert( 'content_report' === $notification['data']['type'] && 'report:' . $report['report_id'] === $notification['data']['idempotency_key'] && 1 === $notification['data']['actor_id'], 'Notification uses the network notification contract.' );
cr_assert( 1 === count( $GLOBALS['_cr_mail'] ) && array( 'admin@example.test' ) === $GLOBALS['_cr_mail'][0]['to'], 'Admin email is notified.' );
cr_assert( false !== strpos( $GLOBALS['_cr_mail'][0]['body'], 'Best venues' ), 'Email names the target.' );

// Dedupe.
$dupe = cr_run( array( 'post_id' => 200, 'reason' => 'spam' ) );
cr_assert( $report['report_id'] === $dupe['report_id'] && true === $dupe['deduplicated'], 'Re-reporting an open target returns the existing report.' );
cr_assert( 1 === count( $GLOBALS['_cr_mail'] ), 'Deduplicated reports do not re-notify.' );
cr_assert( 1 === count( extrachill_community_recent_report_times( 1 ) ), 'Deduplicated reports do not count against the rate limit.' );

// Profile report.
$profile = cr_run( array( 'user_id' => 2, 'reason' => 'spam' ) );
cr_assert( false === $profile['deduplicated'] && 'user' === get_post_meta( $profile['report_id'], '_ec_report_target_type' ), 'Profiles are reportable separately from their content.' );

// Moderation queue: resolving closes dedupe window.
$GLOBALS['_cr_user'] = 1;
cr_assert( array() === extrachill_community_report_row_actions( array( 'edit' => 'x' ), $stored ), 'Non-moderators get no row actions.' );
$GLOBALS['_cr_user'] = 3;
$actions = extrachill_community_report_row_actions( array( 'edit' => 'x' ), $stored );
cr_assert( array( 'ec_resolve', 'ec_dismiss' ) === array_keys( $actions ), 'Open reports offer Resolve and Dismiss.' );
cr_assert( 'ec_target' === extrachill_community_report_admin_primary_column( 'title', 'edit-ec_report' ), 'Target is the primary column.' );
cr_assert( array( 'cb', 'ec_target', 'ec_reason', 'ec_rep', 'ec_status', 'date' ) === array_keys( extrachill_community_report_admin_columns() ), 'Queue shows target, reason, reporter, status.' );
cr_assert( false === extrachill_community_set_report_status( $report['report_id'], 'publish', 3 ), 'Only report statuses are accepted.' );
cr_assert( true === extrachill_community_set_report_status( $report['report_id'], EXTRACHILL_COMMUNITY_REPORT_RESOLVED, 3 ), 'Moderators can resolve.' );
cr_assert( 3 === get_post_meta( $report['report_id'], '_ec_report_resolved_by' ), 'Resolver is recorded.' );
cr_assert( array( 'ec_reopen' ) === array_keys( extrachill_community_report_row_actions( array(), get_post( $report['report_id'] ) ) ), 'Closed reports offer Reopen.' );

$GLOBALS['_cr_user'] = 1;
$after_resolve = cr_run( array( 'post_id' => 200, 'reason' => 'spam' ) );
cr_assert( false === $after_resolve['deduplicated'] && $after_resolve['report_id'] !== $report['report_id'], 'A resolved report no longer dedupes new reports.' );

// Rate limit (3 filed so far this hour).
for ( $i = 0; $i < 7; $i++ ) {
	cr_post( 300 + $i, 'topic', 'publish', 10, 2 );
	$ok = cr_run( array( 'post_id' => 300 + $i, 'reason' => 'spam' ) );
	cr_assert( is_array( $ok ) && false === $ok['deduplicated'], 'Reports up to the hourly limit succeed.' );
}
cr_post( 400, 'topic', 'publish', 10, 2 );
$limited = cr_run( array( 'post_id' => 400, 'reason' => 'spam' ) );
cr_assert( 'rate_limited' === cr_code( $limited ) && 429 === $limited->data['status'], 'The 11th report in an hour is rate limited.' );
$still_dedupes = cr_run( array( 'post_id' => 300, 'reason' => 'spam' ) );
cr_assert( true === $still_dedupes['deduplicated'], 'Dedupe still answers while rate limited.' );
$GLOBALS['_cr_transients']['ec_report_rate_1'] = array_fill( 0, 10, time() - HOUR_IN_SECONDS - 1 );
cr_assert( is_array( cr_run( array( 'post_id' => 400, 'reason' => 'spam' ) ) ), 'Reports older than an hour no longer count.' );

echo "PASS: Content reports validate targets, dedupe, rate limit, notify moderators, and feed the queue.\n";
