<?php
/**
 * Focused tests for edit-only network taxonomy correction.
 *
 * Run: php tests/test-topic-term-correction.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_actions']       = array();
$GLOBALS['_test_is_topic_edit'] = false;

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['_test_actions'][ $hook ][ $priority ][] = $callback;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text ) {
	return $text;
}
function esc_html__( $text ) {
	return $text;
}
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function absint( $value ) {
	return abs( (int) $value );
}
function rest_url() {
	return 'https://community.example/wp-json/';
}
function esc_url_raw( $url ) {
	return $url;
}
function wp_create_nonce() {
	return 'nonce';
}
function get_taxonomy( $taxonomy ) {
	return in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true )
		? (object) array( 'show_in_rest' => true )
		: false;
}
function is_object_in_taxonomy( $post_type, $taxonomy ) {
	return 'topic' === $post_type && in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true );
}
function get_the_terms( $topic_id, $taxonomy ) {
	if ( 91 !== $topic_id || 'artist' !== $taxonomy ) {
		return array();
	}

	return array(
		(object) array(
			'term_id' => 17,
			'name'    => 'Kid Lake',
			'slug'    => 'kid-lake',
		),
	);
}
function is_wp_error() {
	return false;
}
function bbp_is_topic_edit() {
	return $GLOBALS['_test_is_topic_edit'];
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

$create_config = extrachill_community_term_picker_config( 0 );
check( 'create composer has no taxonomy controls', array() === $create_config['taxonomies'] );

ob_start();
extrachill_community_render_term_picker_mounts();
$create_markup = ob_get_clean();
check( 'create composer renders no correction markup', '' === $create_markup );

$GLOBALS['_test_is_topic_edit'] = true;
$edit_config                    = extrachill_community_term_picker_config( 91 );
$artist                         = array_values( array_filter( $edit_config['taxonomies'], static fn( $entry ) => 'artist' === $entry['taxonomy'] ) )[0];
check( 'edit config carries the existing topic ID', 91 === $edit_config['topicId'] );
check( 'edit config preserves local IDs for assignment', 17 === $artist['selected'][0]['id'] );
check( 'edit config preserves the network identity slug', 'kid-lake' === $artist['selected'][0]['slug'] );
check( 'edit config does not expose a local taxonomy REST base', ! isset( $artist['restBase'] ) );

ob_start();
extrachill_community_render_term_picker_mounts();
$edit_markup = ob_get_clean();
check( 'edit correction is collapsed by default', false !== strpos( $edit_markup, '<details class="ec-topic-term-corrections">' ) );
check( 'closed-topic edit uses the same edit-only correction path', false !== strpos( $edit_markup, 'Correct classifications (optional)' ) );
check( 'all three correction mounts render only on edit', 3 === substr_count( $edit_markup, 'ec-term-picker-mount' ) );

check(
	'correction assets remain registered for conditional enqueue',
	in_array( 'extrachill_community_enqueue_term_picker', $GLOBALS['_test_actions']['wp_enqueue_scripts'][20] ?? array(), true )
);
check(
	'correction mounts retain the bbPress edit form hook',
	in_array( 'extrachill_community_render_term_picker_mounts', $GLOBALS['_test_actions']['bbp_theme_before_topic_form_location'][10] ?? array(), true )
);

$source = file_get_contents( dirname( __DIR__ ) . '/src/term-picker/TermPicker.tsx' );
check( 'client has no local taxonomy REST search path', false === strpos( $source, '/wp/v2/' ) );
check( 'obsolete local-only empty result message is gone', false === strpos( $source, 'No matching terms' ) );

$community_source = file_get_contents( dirname( __DIR__ ) . '/inc/content/editor/composer-term-picker.php' );
check( 'Community does not schedule classification directly', false === strpos( $community_source, 'classify-post-terms' ) );
foreach ( array( 'artist', 'festival', 'location' ) as $taxonomy ) {
	$taxonomy_source = file_get_contents( dirname( __DIR__ ) . '/inc/core/taxonomy-' . $taxonomy . '.php' );
	check( "{$taxonomy} correction does not run during topic creation", false === strpos( $taxonomy_source, "add_action( 'bbp_new_topic'" ) );
	check( "{$taxonomy} correction remains available during topic edit", false !== strpos( $taxonomy_source, "add_action( 'bbp_edit_topic'" ) );
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All topic term correction tests passed.\n";
exit( 0 );
