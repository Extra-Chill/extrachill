<?php
/** Regression coverage for the reply edit breadcrumb and form heading. */

define( 'ABSPATH', __DIR__ );

function add_filter() {}
function do_action() {}
function ec_get_blog_id() { return 2; }
function get_current_blog_id() { return 2; }
function is_front_page() { return false; }
function ec_get_site_url() { return 'https://extrachill.com'; }
function home_url() { return 'https://community.extrachill.com'; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( $url ) { return esc_html( $url ); }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_html_e( $text ) { echo esc_html( $text ); }

function bbp_is_reply_edit() { return 'reply' === $GLOBALS['_breadcrumb_edit_type']; }
function bbp_is_topic_edit() { return 'topic' === $GLOBALS['_breadcrumb_edit_type']; }
function bbp_get_reply_id() { return 50; }
function bbp_get_reply_topic_id( $id ) { return 50 === $id ? 40 : 0; }
function bbp_get_topic_id() { return bbp_is_topic_edit() ? 40 : 0; }
function bbp_get_topic_forum_id( $id ) { return 40 === $id ? $GLOBALS['_breadcrumb_forum_id'] : 0; }
function bbp_get_forum_ancestors( $id ) { return 30 === $id ? array( 20, 10 ) : array(); }
function bbp_get_forum_permalink( $id ) { return home_url() . '/forum/' . $id; }
function bbp_get_forum_title( $id ) { return array( 10 => 'Music', 20 => 'Artists', 30 => 'Artist Corner' )[ $id ]; }
function bbp_get_topic_permalink( $id ) { return home_url() . '/t/' . $id; }
function bbp_get_topic_title( $id = 40 ) { return 40 === $id ? 'Rock & Roll' : ''; }

// Render the page and its nested form with a minimal stand-in for the theme's breadcrumb renderer.
function extrachill_breadcrumbs() {
	echo '<nav class="breadcrumbs">' . extrachill_community_breadcrumb_root( '' ) . ' › ' . extrachill_community_breadcrumb_trail( '' ) . '</nav>';
}
function get_header() {}
function get_footer() {}
function bbp_is_single_user() { return false; }
function get_the_title() { return 'Reply To: Rock & Roll'; }
function have_posts() { return ! $GLOBALS['_breadcrumb_post_rendered']; }
function the_post() { $GLOBALS['_breadcrumb_post_rendered'] = true; }
function the_content() { require dirname( __DIR__ ) . '/bbpress/form-reply.php'; }
function bbp_current_user_can_access_create_reply_form() { return true; }
function bbp_topic_id() { echo '40'; }
function bbp_get_form_reply_to() { return 0; }
function bbp_is_topic_open() { return true; }
function bbp_is_forum_closed() { return false; }
function bbp_get_template_part() {}
function bbp_the_content() {}
function bbp_use_wp_editor() { return true; }
function bbp_allow_topic_tags() { return false; }
function bbp_is_subscriptions_active() { return false; }
function current_user_can() { return false; }
function bbp_allow_revisions() { return false; }
function bbp_reply_form_fields() {}

function reply_edit_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/inc/core/breadcrumb-filter.php';

$GLOBALS['_breadcrumb_edit_type']     = 'reply';
$GLOBALS['_breadcrumb_forum_id']      = 30;
$GLOBALS['_breadcrumb_post_rendered'] = false;

ob_start();
require dirname( __DIR__ ) . '/bbpress/bbpress.php';
$page = ob_get_clean();

$topic_link = '<a href="https://community.extrachill.com/t/40">Rock &amp; Roll</a>';
$edit       = '<span class="breadcrumb-title">Edit</span>';
$trail      = '<a href="https://community.extrachill.com/forum/10">Music</a> › '
	. '<a href="https://community.extrachill.com/forum/20">Artists</a> › '
	. '<a href="https://community.extrachill.com/forum/30">Artist Corner</a> › '
	. $topic_link . ' › ' . $edit;
$root       = '<a href="https://extrachill.com">Extra Chill</a> › <a href="https://community.extrachill.com">Community</a>';

reply_edit_assert( 1 === substr_count( $page, '<nav class="breadcrumbs">' ), 'Reply edit must render exactly one breadcrumb.' );
reply_edit_assert( false !== strpos( $page, "<nav class=\"breadcrumbs\">$root › $trail</nav>" ), 'Reply edit must link to its forums and topic in hierarchy order, ending in Edit.' );
reply_edit_assert( 1 === substr_count( $page, 'Reply To: Rock &amp; Roll' ) && 1 === preg_match( '/<legend>\s*Edit Reply\s*<\/legend>/', $page ), 'The edit form must have a distinct legend instead of repeating the page title.' );

$GLOBALS['_breadcrumb_forum_id'] = 0;
reply_edit_assert( $topic_link . ' › ' . $edit === extrachill_community_breadcrumb_trail( '' ), 'A missing forum must not leave an empty breadcrumb.' );

$GLOBALS['_breadcrumb_edit_type'] = 'topic';
$GLOBALS['_breadcrumb_forum_id']  = 30;
reply_edit_assert( $trail === extrachill_community_breadcrumb_trail( '' ), 'Topic edit must retain the same full trail.' );

$GLOBALS['_breadcrumb_edit_type'] = '';
ob_start();
require dirname( __DIR__ ) . '/bbpress/form-reply.php';
$form = ob_get_clean();
reply_edit_assert( 1 === preg_match( '/<legend>\s*Reply To: Rock &amp; Roll\s*<\/legend>/', $form ), 'New replies must keep their original legend.' );

echo "PASS: Reply edit renders one complete breadcrumb and a distinct form legend.\n";
