<?php
/**
 * Focused tests for contextual post-content newsletter copy.
 *
 * Run with: php tests/newsletter-context.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {}

class WP_Term {
	public $name;

	public function __construct( $name ) {
		$this->name = $name;
	}
}

$test_is_post    = true;
$test_taxonomies = array( 'artist', 'festival', 'location' );
$test_terms      = array();
$test_term_args  = array();

function add_filter() {}
function is_singular( $post_type ) {
	global $test_is_post;
	return 'post' === $post_type && $test_is_post;
}
function taxonomy_exists( $taxonomy ) {
	global $test_taxonomies;
	return in_array( $taxonomy, $test_taxonomies, true );
}
function wp_get_post_terms( $post_id, $taxonomy, $args ) {
	global $test_term_args, $test_terms;
	$test_term_args[ $taxonomy ] = $args;
	return $test_terms[ $taxonomy ] ?? array();
}
function get_the_ID() {
	return 65;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__ ) . '/inc/single/newsletter-context.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

function newsletter_args() {
	return array(
		'heading'           => 'Generic heading',
		'description'       => 'Generic description',
		'wrapper_class'     => 'newsletter-content-section',
		'heading_level'     => 'h3',
		'layout'            => 'section',
		'placeholder'       => 'Enter your email address',
		'button_text'       => 'Subscribe',
		'show_archive_link' => true,
		'archive_link_text' => 'Browse past newsletters',
	);
}

function contextualize( $terms, $context = 'content' ) {
	global $test_terms;
	$test_terms = $terms;
	return extrachill_blog_contextualize_newsletter_form( newsletter_args(), $context );
}

$artist = contextualize( array( 'artist' => array( new WP_Term( 'Kid Lake' ) ) ) );
assert_same( 'Into Kid Lake?', $artist['heading'], 'Artist terms should set artist copy.' );
assert_same( 'Get independent music stories, news, and events from Extra Chill.', $artist['description'], 'Artist context should not promise artist-specific delivery.' );

$festival = contextualize( array( 'festival' => array( new WP_Term( 'High Water' ) ) ) );
assert_same( 'Following High Water?', $festival['heading'], 'Festival terms should set festival copy.' );
assert_same( 'Get independent music stories, news, and events from Extra Chill.', $festival['description'], 'Festival context should not promise festival-specific delivery.' );

$location = contextualize( array( 'location' => array( new WP_Term( 'Charleston' ) ) ) );
assert_same( 'Into the Charleston music scene?', $location['heading'], 'Location terms should set location copy.' );
assert_same( 'Get independent music stories, news, and events from Extra Chill.', $location['description'], 'Location context should not promise location-specific delivery.' );

$mixed = contextualize(
	array(
		'location' => array( new WP_Term( 'Charleston' ) ),
		'festival' => array( new WP_Term( 'High Water' ) ),
		'artist'   => array( new WP_Term( 'Kid Lake' ) ),
	)
);
assert_same( 'Into Kid Lake?', $mixed['heading'], 'Artist copy should win the mixed-term priority.' );
assert_same( array( 'orderby' => 'name', 'order' => 'ASC' ), $test_term_args['artist'], 'Entity terms should use deterministic name ordering.' );

$original           = newsletter_args();
$test_taxonomies    = array();
$missing_dependency = contextualize( array( 'artist' => array( new WP_Term( 'Kid Lake' ) ) ) );
assert_same( $original, $missing_dependency, 'Missing taxonomy dependencies should preserve generic form arguments.' );
$test_taxonomies = array( 'artist', 'festival', 'location' );

assert_same( $original, contextualize( array() ), 'Posts without entity terms should retain generic copy.' );
assert_same( $original, contextualize( array( 'artist' => new WP_Error() ) ), 'Term lookup errors should retain generic copy.' );
assert_same( $original, contextualize( array( 'artist' => array( new WP_Term( 'Kid Lake' ) ) ), 'archive' ), 'Non-content forms should remain unchanged.' );

$test_is_post = false;
assert_same( $original, contextualize( array( 'artist' => array( new WP_Term( 'Kid Lake' ) ) ) ), 'Non-post views should remain unchanged.' );
$test_is_post = true;

$escaped = contextualize( array( 'artist' => array( new WP_Term( '<script>alert("x")</script> & Friends' ) ) ) );
$rendered = '<h3>' . esc_html( $escaped['heading'] ) . '</h3><p>' . esc_html( $escaped['description'] ) . '</p>';
assert_same( false, false !== strpos( $rendered, '<script>' ), 'Rendered contextual copy must escape term markup.' );
assert_same( true, false !== strpos( $rendered, '&lt;script&gt;' ), 'Rendered contextual copy should retain escaped term text.' );

$preserved = $mixed;
unset( $preserved['heading'], $preserved['description'] );
$expected = $original;
unset( $expected['heading'], $expected['description'] );
assert_same( $expected, $preserved, 'Contextual copy must preserve every non-copy form argument.' );

fwrite( STDOUT, "Newsletter context tests passed.\n" );
