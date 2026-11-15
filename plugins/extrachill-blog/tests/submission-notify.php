<?php
/**
 * Focused main-blog submission notification tests.
 *
 * Run: php tests/submission-notify.php
 *      php tests/submission-notify.php --without-users
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['hooks']      = array();
$GLOBALS['post_meta']  = array();
$GLOBALS['sources']    = array();
$GLOBALS['main_site']  = true;
$GLOBALS['publishers'] = array( 9 );
$GLOBALS['cap_checks'] = array();

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}
function do_action($hook, ...$args) {
	$priorities = $GLOBALS['hooks'][ $hook ] ?? array();
	ksort($priorities);
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as list($callback, $accepted_args) ) {
			$callback(...array_slice($args, 0, $accepted_args));
		}
	}
}
function __($text, $domain) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP signature stub.
	return $text;
}
function is_main_site() { return $GLOBALS['main_site']; }
function user_can($user_id, $capability) {
	$GLOBALS['cap_checks'][] = array( $user_id, $capability );
	return 'publish_posts' === $capability && in_array($user_id, $GLOBALS['publishers'], true);
}
function metadata_exists($type, $post_id, $key) {
	return array_key_exists($key, $GLOBALS['post_meta'][ $post_id ] ?? array());
}
function add_post_meta($post_id, $key, $value, $unique = false) {
	if ( $unique && metadata_exists('post', $post_id, $key) ) {
		return false;
	}
	$GLOBALS['post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

$without_users = in_array('--without-users', $argv, true);
if ( ! $without_users ) {
	function ec_users_register_publish_notify_source($context, $descriptor) {
		$GLOBALS['sources'][ $context ] = $descriptor;
	}
	function ec_users_get_publish_notify_sources() { return $GLOBALS['sources']; }
}

require dirname( __DIR__ ) . '/inc/submit/submission-notify.php';

$failures = 0;
function check($label, $condition) {
	global $failures;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
	if ( ! $condition ) {
		++$failures;
	}
}
function transition($id, $new_status = 'pending', $old = 'draft', $author = '7', $type = 'post') {
	do_action('transition_post_status', $new_status, $old, (object) array(
		'ID'          => $id,
		'post_author' => $author,
		'post_type'   => $type,
	));
}
function marker($id) { return $GLOBALS['post_meta'][ $id ]['_ec_blog_submission'] ?? null; }

do_action('init');
if ( $without_users ) {
	transition(1);
	check('missing Users substrate is safe and writes no marker', null === marker(1) && array() === $GLOBALS['sources']);
	exit($failures ? 1 : 0);
}

check('registers descriptor on init at priority 20',
	isset($GLOBALS['hooks']['init'][20]) &&
	array(
		'meta_key'       => '_ec_blog_submission',
		'user_id_field'  => 'user_id',
		'type'           => 'blog_submission_published',
		'title_template' => 'Your post "%s" is live on Extra Chill',
	) === $GLOBALS['sources']['blog_submission']
);

$before = time();
transition(1);
$first = marker(1);
check('draft to pending records integer author and UTC submission timestamp',
	is_array($first) && 7 === $first['user_id'] &&
	strtotime($first['submitted_at']) >= $before &&
	strtotime($first['submitted_at']) <= time() &&
	gmdate('c', strtotime($first['submitted_at'])) === $first['submitted_at']
);
check('checks author capability rather than current user', array( 7, 'publish_posts' ) === $GLOBALS['cap_checks'][0]);

transition(2, 'pending', 'draft', 9);
check('author with publish_posts receives no marker', null === marker(2));

// Arbitrary source names ensure no Studio or dispatch keys are hard-coded.
$GLOBALS['sources']['future_source']           = array( 'meta_key' => '_future_submission' );
$GLOBALS['post_meta'][3]['_future_submission'] = array( 'user_id' => 7 );
transition(3);
check('another registered source owns its submission', null === marker(3));
$GLOBALS['post_meta'][4]['_future_submission'] = '';
transition(4);
check('even an empty existing source marker prevents double ownership', null === marker(4));

$GLOBALS['main_site'] = false;
transition(5);
$GLOBALS['main_site'] = true;
check('non-main site receives no marker', null === marker(5));
transition(6, 'pending', 'draft', 7, 'page');
check('non-post type receives no marker', null === marker(6));

transition(7, 'draft', 'auto-draft');
transition(8, 'pending', 'pending');
check('only transitions into pending are marked', null === marker(7) && null === marker(8));
transition(9, 'pending', 'private');
check('entering pending from another status is supported', 7 === marker(9)['user_id']);

transition(1, 'draft', 'pending');
transition(1, 'pending', 'draft', 8);
check('re-entering pending preserves original author and timestamp', marker(1) === $first);
$GLOBALS['post_meta'][10]['_ec_blog_submission'] = '';
transition(10);
check('an existing empty blog marker is not overwritten', '' === marker(10));

exit($failures ? 1 : 0);
