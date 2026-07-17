<?php
/**
 * Focused tests for validated entity continuation into the topic composer.
 *
 * Run: php tests/test-discussion-composer-continuation.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_query']       = array();
$GLOBALS['_test_logged_in']   = true;
$GLOBALS['_test_can_publish'] = true;
$GLOBALS['_test_terms']       = array(
	'artist' => array(
		'phish' => (object) array(
			'term_id' => 42,
			'name'    => 'Phish',
			'slug'    => 'phish',
			'parent'  => 0,
		),
	),
);

function add_action() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text ) {
	return $text;
}
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) );
}
function sanitize_title( $value ) {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) );
}
function wp_unslash( $value ) {
	return $value;
}
function get_taxonomy( $taxonomy ) {
	if ( ! in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true ) ) {
		return false;
	}

	return (object) array(
		'show_in_rest' => true,
		'rest_base'    => $taxonomy,
		'hierarchical' => 'location' === $taxonomy,
	);
}
function is_object_in_taxonomy( $post_type, $taxonomy ) {
	return 'topic' === $post_type && in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true );
}
function get_term_by( $field, $slug, $taxonomy ) {
	return $GLOBALS['_test_terms'][ $taxonomy ][ $slug ] ?? false;
}
function is_wp_error() {
	return false;
}
function is_user_logged_in() {
	return $GLOBALS['_test_logged_in'];
}
function bbp_current_user_can_publish_topics() {
	return $GLOBALS['_test_can_publish'];
}
function ec_get_site_url() {
	return 'https://community.extrachill.com';
}
function home_url( $path = '' ) {
	return 'https://community.extrachill.com' . $path;
}
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}
function add_query_arg( $args, $url = '' ) {
	// Match WordPress: newly assigned values must already be URL-encoded.
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url );
		$url  = func_get_arg( 2 );
	}

	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}

	return $url . '?' . implode( '&', $pairs );
}
function rest_url() {
	return 'https://community.extrachill.com/wp-json/';
}
function esc_url_raw( $url ) {
	return $url;
}
function wp_create_nonce() {
	return 'nonce';
}
function get_the_terms() {
	return array();
}
function esc_attr_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function bbp_get_template_part() {
	echo '<form id="new-post"></form>';
}

require dirname( __DIR__ ) . '/inc/content/editor/composer-term-picker.php';

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

$valid_query = array(
	'compose'         => 'discussion',
	'entity_taxonomy' => 'artist',
	'entity_slug'     => 'phish',
);

$state = extrachill_community_get_discussion_composer_state( $valid_query );
check( 'valid supported existing term resolves', $state && 42 === (int) $state['term']->term_id );
check(
	'canonical URL uses taxonomy and slug state',
	'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=phish' === extrachill_community_get_discussion_composer_url( 'artist', 'phish' )
);
$login_url = extrachill_community_get_discussion_composer_login_url( 'artist', 'phish' );
parse_str( (string) parse_url( $login_url, PHP_URL_QUERY ), $login_query );
check( 'logged-out continuation uses the canonical login path', 'https://community.extrachill.com/login/' === strtok( $login_url, '?' ) );
check(
	'logged-out continuation preserves the validated composer URL',
	'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=phish' === ( $login_query['redirect_to'] ?? '' )
);
check( 'nested composer state does not leak into outer login arguments', ! isset( $login_query['entity_taxonomy'], $login_query['entity_slug'] ) );
check( 'login query contains only the complete redirect destination', array( 'redirect_to' ) === array_keys( $login_query ) );
check(
	'unsupported taxonomy is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_taxonomy' => 'post_tag' ) ) )
);
check(
	'missing existing term is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_slug' => 'missing' ) ) )
);
check(
	'missing discussion action degrades to normal composer',
	null === extrachill_community_get_discussion_composer_state( array_diff_key( $valid_query, array( 'compose' => true ) ) )
);
check(
	'array taxonomy state is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_taxonomy' => array( 'artist' ) ) ) )
);
check(
	'array term state is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_slug' => array( 'phish' ) ) ) )
);
check(
	'non-canonical taxonomy state is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_taxonomy' => 'Artist!' ) ) )
);
check(
	'non-canonical term state is rejected',
	null === extrachill_community_get_discussion_composer_state( array_merge( $valid_query, array( 'entity_slug' => '../phish' ) ) )
);

$_GET = $valid_query;
$config = extrachill_community_term_picker_config();
$artist = array_values( array_filter( $config['taxonomies'], fn( $entry ) => 'artist' === $entry['taxonomy'] ) )[0];
check( 'authorized continuation preselects the existing picker term', 42 === $artist['selected'][0]['id'] );

$GLOBALS['_test_can_publish'] = false;
$config                       = extrachill_community_term_picker_config();
$artist                       = array_values( array_filter( $config['taxonomies'], fn( $entry ) => 'artist' === $entry['taxonomy'] ) )[0];
check( 'topic permissions gate preselection', array() === $artist['selected'] );

function render_topic_modal() {
	ob_start();
	include dirname( __DIR__ ) . '/inc/home/new-topic-modal.php';
	return ob_get_clean();
}

$GLOBALS['_test_can_publish'] = true;
$_GET                         = $valid_query;
check( 'valid authorized continuation marks modal for auto-open', false !== strpos( render_topic_modal(), 'data-auto-open="true"' ) );

$_GET = array_merge( $valid_query, array( 'entity_slug' => '../phish' ) );
check( 'malformed continuation leaves modal closed', false !== strpos( render_topic_modal(), 'data-auto-open="false"' ) );

$GLOBALS['_test_can_publish'] = false;
$_GET                         = $valid_query;
check( 'unauthorized continuation leaves modal closed', false !== strpos( render_topic_modal(), 'data-auto-open="false"' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All discussion composer continuation tests passed.\n";
exit( 0 );
