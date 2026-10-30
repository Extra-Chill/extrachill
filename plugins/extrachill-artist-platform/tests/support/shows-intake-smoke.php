<?php
/** Standalone seam test: php tests/support/shows-intake-smoke.php [--helper]. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['viewer'] = 0;
$GLOBALS['blog'] = 4;
function add_filter( ...$args ) {}
function remove_filter( ...$args ) {}
function get_current_user_id() { return $GLOBALS['viewer']; }
function ec_can_manage_artist( $user, $artist ) { return 7 === $user && 42 === $artist; }
function ec_get_blog_id( $site ) { return 3; }
function get_home_url( $blog ) { return 'https://events.example.test'; }
function get_site_url( $blog ) { return get_home_url( $blog ); }
function trailingslashit( $url ) { return rtrim( $url, '/' ) . '/'; }
function switch_to_blog( $blog ) { $GLOBALS['blog'] = $blog; }
function restore_current_blog() { $GLOBALS['blog'] = 4; }
function is_wp_error( $result ) { return false; }
function __( $text, $domain ) { return $text; }
function esc_html__( $text, $domain ) { return htmlspecialchars( $text ); }
function esc_html( $text ) { return htmlspecialchars( $text ); }
function esc_url( $text ) { return htmlspecialchars( $text ); }
function ec_cross_site_rest_request( ...$args ) {
	return array(
		'taxonomy' => 'artist', 'found' => true, 'term_id' => 8, 'term_slug' => 'band',
		'upcoming' => 12 === $args[3]['query']['input']['artist_term_id'] ? array( array( 'title' => 'Next show', 'permalink' => 'https://events.example.test/show' ) ) : array(),
		'past' => 11 === $args[3]['query']['input']['artist_term_id'] ? array( array( 'title' => 'Old show', 'permalink' => 'https://events.example.test/old' ) ) : array(),
	);
}
if ( in_array( '--helper', $argv, true ) ) {
	function extrachill_events_submit_url( $venue, $city ) {
		check( 3 === $GLOBALS['blog'], 'Helper must run on Events blog' );
		check( '' === $venue && '' === $city, 'Do not guess event context' );
		return 'https://events.example.test/submit/';
	}
}
function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__, 2 ) . '/inc/artist-profiles/frontend/shows-section.php';
check( 'https://events.example.test/submit/' === ec_artist_shows_submit_url(), 'Submit URL must point to Events' );
check( 4 === $GLOBALS['blog'], 'Blog context must be restored' );
foreach ( array( 0, 7, 9 ) as $viewer ) {
	$GLOBALS['viewer'] = $viewer;
	foreach ( array( 0, 10, 11, 12 ) as $term ) {
		$owner = 7 === $viewer;
		check( ( $owner || 11 === $term || 12 === $term ) === ec_artist_profile_has_shows( 42, $term ), 'Visibility mismatch' );
		ob_start();
		ec_render_artist_profile_shows_section( 42, $term );
		$html = ob_get_clean();
		check( ( $owner && 12 !== $term ) === str_contains( $html, 'Add a show' ), 'CTA must be manager-only and only without upcoming shows' );
		if ( $owner && 12 !== $term ) {
			check( str_contains( $html, 'https://events.example.test/submit/' ), 'CTA destination mismatch' );
		}
	}
	check( ! ec_artist_profile_has_shows( 99, 0 ), 'Managing another artist must not grant access' );
}
echo "Shows intake smoke tests passed\n";
