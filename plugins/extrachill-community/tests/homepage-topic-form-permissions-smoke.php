<?php
/**
 * Focused tests for homepage topic-form permissions.
 *
 * Run: php tests/homepage-topic-form-permissions-smoke.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_filters']         = array();
$GLOBALS['_test_logged_in']       = true;
$GLOBALS['_test_user_active']     = true;
$GLOBALS['_test_can_publish']     = true;
$GLOBALS['_test_template_throws'] = false;

function add_filter( $hook, $callback ) {
	$GLOBALS['_test_filters'][ $hook ][] = $callback;
}
function remove_filter( $hook, $callback ) {
	foreach ( $GLOBALS['_test_filters'][ $hook ] ?? array() as $index => $registered_callback ) {
		if ( $registered_callback === $callback ) {
			unset( $GLOBALS['_test_filters'][ $hook ][ $index ] );
		}
	}
}
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value );
	}

	return $value;
}
function is_user_logged_in() {
	return $GLOBALS['_test_logged_in'];
}
function bbp_is_user_active() {
	return $GLOBALS['_test_user_active'];
}
function bbp_current_user_can_publish_topics() {
	return $GLOBALS['_test_can_publish'];
}
function extrachill_community_can_continue_discussion_composer() {
	return false;
}
function extrachill_community_get_discussion_composer_state() {
	return null;
}
function esc_attr_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function bbp_get_template_part() {
	if ( $GLOBALS['_test_template_throws'] ) {
		throw new RuntimeException( 'Template rendering failed.' );
	}

	if ( apply_filters( 'bbp_current_user_can_access_create_topic_form', false ) ) {
		echo '<form id="new-post"></form>';
		return;
	}

	echo is_user_logged_in() ? 'You cannot create new topics.' : 'login/register';
}

$failures = 0;
function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}

	echo "FAIL: $label\n";
	++$failures;
}

function render_homepage_topic_modal() {
	ob_start();
	include dirname( __DIR__ ) . '/inc/home/new-topic-modal.php';
	return ob_get_clean();
}

$output = render_homepage_topic_modal();
check( 'active logged-in participant can access the homepage topic form', false !== strpos( $output, '<form id="new-post">' ) );
check( 'modal identifies the destination as a Community post', false !== strpos( $output, 'New Community Post' ) );
check( 'homepage permission override is removed after rendering', empty( $GLOBALS['_test_filters']['bbp_current_user_can_access_create_topic_form'] ) );

$button_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/home/new-topic-button.php' );
check( 'homepage trigger identifies the destination as the Community', false !== strpos( $button_source, "'New Community Post'" ) );
check( 'native false access remains false after successful rendering', false === apply_filters( 'bbp_current_user_can_access_create_topic_form', false ) );

$GLOBALS['_test_template_throws'] = true;
$template_exception_caught        = false;
ob_start();
try {
	include dirname( __DIR__ ) . '/inc/home/new-topic-modal.php';
} catch ( RuntimeException $exception ) {
	$template_exception_caught = true;
} finally {
	ob_end_clean();
}
$GLOBALS['_test_template_throws'] = false;

check( 'template rendering exception propagates', $template_exception_caught );
check( 'homepage permission override is removed after rendering throws', empty( $GLOBALS['_test_filters']['bbp_current_user_can_access_create_topic_form'] ) );
check( 'native false access remains false after rendering throws', false === apply_filters( 'bbp_current_user_can_access_create_topic_form', false ) );

$GLOBALS['_test_can_publish'] = false;
$output                       = render_homepage_topic_modal();
check( 'user without publish_topics remains blocked', false !== strpos( $output, 'You cannot create new topics.' ) );

$GLOBALS['_test_can_publish'] = true;
$GLOBALS['_test_user_active'] = false;
$output                       = render_homepage_topic_modal();
check( 'inactive or spam user remains blocked', false !== strpos( $output, 'You cannot create new topics.' ) );

$GLOBALS['_test_user_active'] = true;
$GLOBALS['_test_logged_in']   = false;
$output                       = render_homepage_topic_modal();
check( 'logged-out user remains on the login/register path', false !== strpos( $output, 'login/register' ) );
check( 'permission override remains scoped after every render', empty( $GLOBALS['_test_filters']['bbp_current_user_can_access_create_topic_form'] ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All homepage topic form permission tests passed.\n";
exit( 0 );
