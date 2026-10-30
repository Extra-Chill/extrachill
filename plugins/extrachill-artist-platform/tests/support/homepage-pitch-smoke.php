<?php
/** Standalone homepage contract test: php tests/support/homepage-pitch-smoke.php */
$root = dirname( __DIR__, 2 );
$home = file_get_contents( $root . '/inc/home/templates/homepage.php' );
$hero = file_get_contents( $root . '/inc/home/templates/hero.php' );
$hooks = file_get_contents( $root . '/inc/home/homepage-hooks.php' );
$css = file_get_contents( $root . '/assets/css/artist-platform-home.css' );
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
check( ! str_contains( $home, 'Coming Soon' ), 'Shop must be live' );
foreach ( array( 'Link Page', 'Artist Profile', 'Shows', 'Fan Subscribers', 'Artist Shop', 'Artist Dispatch', 'Team Access', 'Local Scene', 'Extra Chill Coverage', 'Analytics' ) as $title ) {
	check( str_contains( $home, "esc_html_e( '$title'," ), "Missing feature: $title" );
}
check( 10 === substr_count( $home, 'class="onboarding-card"' ), 'Expected ten feature cards' );
check( str_contains( $home, 'ec_artist_home_example()' ), 'Template must resolve the example through the helper' );
check( (bool) preg_match( "/apply_filters\(\s*'extrachill_artist_home_example'/", $hooks ), 'Missing example filter' );

// Theme layout utilities and buttons; all other template classes must be
// explicitly defined in the plugin homepage stylesheet (including dashicons).
$theme_classes = array( 'ec-edge-gutter', 'entry-content', 'card', 'button-1', 'button-2', 'button-3', 'button-small', 'button-medium', 'button-large', 'notice', 'notice-success', 'notice-error', 'notice-warning', 'notice-info' );
preg_match_all( '/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $css, $css_matches );
$allowed = array_merge( $theme_classes, $css_matches[1] );
foreach ( array( 'homepage.php' => $home, 'hero.php' => $hero ) as $file => $source ) {
	preg_match_all( '/\bclass="([^"]*)"/', $source, $matches );
	foreach ( $matches[1] as $classes ) {
		foreach ( preg_split( '/\s+/', trim( $classes ) ) as $class ) {
			check( in_array( $class, $allowed, true ), "$file: undefined class $class" );
		}
	}
}

// Exercise the resolver without a WordPress install.
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $hook, $value ) {
	if ( 'extrachill_artist_home_example' === $hook ) {
		check( array( 'artist_id' => 13731 ) === $value, 'Wrong default artist ID' );
		return array( 'artist_id' => $GLOBALS['example_id'] ?? 13731 );
	}
	return 55;
}
function get_post( $id ) {
	return 0 === $id ? null : (object) array( 'ID' => $id, 'post_status' => 2 === $id ? 'draft' : 'publish' );
}
function get_permalink( $id ) { return "https://artist.example.test/artists/$id/"; }
function get_the_title( $id ) { return "Performer $id"; }
function ec_get_link_page_public_url( $id ) { return $GLOBALS['link_url'] ?? "https://extrachill.link/$id/"; }
require $root . '/inc/home/homepage-hooks.php';
$example = ec_artist_home_example();
check( 'Performer 13731' === $example['name'], 'Name must come from the post' );
check( get_permalink( 13731 ) === $example['profile_url'], 'Profile must use its permalink' );
check( 'https://extrachill.link/55/' === $example['link_url'], 'Link page must use the runtime helper' );
$GLOBALS['example_id'] = 99;
check( 'Performer 99' === ec_artist_home_example()['name'], 'Filter must select the example' );
foreach ( array( 0, 2 ) as $id ) {
	$GLOBALS['example_id'] = $id;
	check( null === ec_artist_home_example(), 'Missing or unpublished examples must be hidden' );
}
$GLOBALS['example_id'] = 99;
$GLOBALS['link_url'] = '';
check( null === ec_artist_home_example(), 'Do not render broken example buttons' );
echo "Homepage pitch smoke tests passed\n";
