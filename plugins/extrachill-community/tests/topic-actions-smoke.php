<?php
/** Standalone regression coverage for single-topic engagement actions. */

define( 'ABSPATH', __DIR__ );
define( 'EXTRACHILL_COMMUNITY_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['_test_actions']      = array();
$GLOBALS['_test_filters']      = array();
$GLOBALS['_test_subscription'] = '';
$GLOBALS['_test_favorite']     = '';
$GLOBALS['_test_link_args']    = array();

function add_action() {}
function add_filter( $hook, $callback ) {
	$GLOBALS['_test_filters'][ $hook ] = $callback;
}
function do_action() {}
function bbp_register_template_stack() {}
function get_current_blog_id() {
	return 2;
}
function bbp_get_topic_post_type() {
	return 'topic';
}
function get_post_type( $post_id ) {
	return 42 === (int) $post_id ? 'topic' : 'forum';
}
function bbp_get_topic_subscription_link( $args = array() ) {
	$GLOBALS['_test_link_args']['subscription'] = $args;
	return $GLOBALS['_test_subscription'];
}
function bbp_get_topic_favorite_link( $args = array() ) {
	$GLOBALS['_test_link_args']['favorite'] = $args;
	return $GLOBALS['_test_favorite'];
}
function bbp_get_topic_reply_count() {
	return 0;
}
function bbp_get_topic_id() {
	return 42;
}
function ec_the_post_views() {}
function post_password_required() {
	return true;
}
function bbp_get_template_part() {}
function esc_attr_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function wp_kses_post( $html ) {
	return $html;
}

require dirname( __DIR__ ) . '/inc/core/bbpress-templates.php';

$failures = 0;
function check( $label, $condition ) {
	global $failures;

	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

function render_topic_actions( $subscription, $favorite ) {
	$GLOBALS['_test_subscription'] = $subscription;
	$GLOBALS['_test_favorite']     = $favorite;

	ob_start();
	include dirname( __DIR__ ) . '/bbpress/content-single-topic.php';
	return ob_get_clean();
}

$subscribe = '<span id="subscription-toggle"><a class="subscription-toggle">Subscribe</a></span>';
$favorite  = '<span id="favorite-toggle"><a class="favorite-toggle">Favorite</a></span>';
$output    = render_topic_actions( $subscribe, $favorite );

check( 'actions render in a labelled navigation region', false !== strpos( $output, 'class="topic-actions" aria-label="Topic actions"' ) );
check( 'subscribe and favorite render as separate action items', 2 === substr_count( $output, 'class="topic-actions__item"' ) );
check( 'both actions have exactly one deliberate separator', 1 === substr_count( $output, 'class="topic-actions__separator"' ) );
check( 'separator is hidden from assistive technology', false !== strpos( $output, 'class="topic-actions__separator" aria-hidden="true"' ) );
$subscription_position = strpos( $output, 'Subscribe' );
$separator_position    = strpos( $output, 'topic-actions__separator' );
$favorite_position     = strpos( $output, 'Favorite' );
check( 'subscription precedes the separator and favorite', $subscription_position < $separator_position && $separator_position < $favorite_position );
check( 'template disables both bbPress default affixes', array( 'before' => '', 'after' => '' ) === $GLOBALS['_test_link_args']['subscription'] && array( 'before' => '', 'after' => '' ) === $GLOBALS['_test_link_args']['favorite'] );

$output = render_topic_actions( '<span>Unsubscribe</span>', '<span>Unfavorite</span>' );
check( 'active states remain readable and separated', false !== strpos( $output, 'Unsubscribe' ) && false !== strpos( $output, 'Unfavorite' ) && 1 === substr_count( $output, 'topic-actions__separator' ) );

$output = render_topic_actions( '', $favorite );
check( 'favorite-only output has no empty separator', false !== strpos( $output, 'Favorite' ) && 0 === substr_count( $output, 'topic-actions__separator' ) );

$output = render_topic_actions( $subscribe, '' );
check( 'subscription-only output has no empty separator', false !== strpos( $output, 'Subscribe' ) && 0 === substr_count( $output, 'topic-actions__separator' ) );

$output = render_topic_actions( '', '' );
check( 'no wrapper renders when no actions are available', false === strpos( $output, 'class="topic-actions"' ) );

$filter = $GLOBALS['_test_filters']['bbp_before_get_user_subscribe_link_parse_args'];
check( 'topic AJAX subscription output loses the bbPress pipe prefix', '' === $filter( array( 'object_id' => 42, 'before' => '&nbsp;|&nbsp;' ) )['before'] );
check( 'non-topic subscription output keeps its existing prefix', '&nbsp;|&nbsp;' === $filter( array( 'object_id' => 7, 'before' => '&nbsp;|&nbsp;' ) )['before'] );

$style_filter = $GLOBALS['_test_filters']['bbp_get_user_subscribe_link'];
$styled_link  = $style_filter( '<a class="subscription-toggle">Subscribe</a>', array(), 1, 42 );
check( 'topic action links use shared button classes', false !== strpos( $styled_link, 'class="subscription-toggle button-3 button-small"' ) );
$favorite_filter = $GLOBALS['_test_filters']['bbp_get_user_favorites_link'];
check( 'topic favorite links use shared button classes', false !== strpos( $favorite_filter( '<a class="favorite-toggle">Favorite</a>', array(), 1, 42 ), 'class="favorite-toggle button-3 button-small"' ) );
check( 'non-topic links are not restyled', '<a class="subscription-toggle">Subscribe</a>' === $style_filter( '<a class="subscription-toggle">Subscribe</a>', array(), 1, 7 ) );

if ( $failures ) {
	exit( 1 );
}

echo "All topic action tests passed.\n";
