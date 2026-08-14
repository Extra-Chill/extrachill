<?php
/**
 * Focused smoke coverage for Festival Wire card media output.
 *
 * Run with: php tests/smoke-content-card.php
 */

define( 'HOUR_IN_SECONDS', 3600 );

$has_featured_image = false;

function the_ID() {
	echo '42';
}

function post_class( $class ) {
	echo 'class="' . $class . '"';
}

function has_post_thumbnail() {
	global $has_featured_image;
	return $has_featured_image;
}

function the_permalink() {
	echo 'https://wire.example.test/story';
}

function get_permalink() {
	return 'https://wire.example.test/story';
}

function the_title_attribute() {
	echo 'Test Festival Story';
}

function the_post_thumbnail( $size ) {
	echo '<img src="https://wire.example.test/image.jpg" alt="Original image alt" data-size="' . $size . '">';
}

function extrachill_display_taxonomy_badges() {
	echo '<div class="taxonomy-badges"><a href="https://wire.example.test/festival/test">Test Festival</a></div>';
}

function get_the_ID() {
	return 42;
}

function the_title( $before, $after ) {
	echo $before . 'Test Festival Story' . $after;
}

function esc_url( $value ) {
	return $value;
}

function get_the_time() {
	return 1000;
}

function current_time() {
	return 2000;
}

function get_the_date( $format = '' ) {
	return DATE_W3C === $format ? '2026-08-14T12:00:00+00:00' : 'August 14, 2026 at 12:00pm';
}

function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function human_time_diff() {
	return '17 mins';
}

function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function get_the_excerpt() {
	return 'A focused card excerpt.';
}

function wp_trim_words( $text ) {
	return $text;
}

function expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function render_card( $with_image ) {
	global $has_featured_image;
	$has_featured_image = $with_image;

	ob_start();
	require dirname( __DIR__ ) . '/templates/content-card.php';
	return ob_get_clean();
}

$image_card = render_card( true );

expect( false !== strpos( $image_card, 'class="festival-wire-card-image"' ), 'Image cards must retain the media wrapper.' );
expect( false === strpos( $image_card, 'festival-wire-card-image--fallback' ), 'Image cards must not use fallback styling.' );
expect( false !== strpos( $image_card, 'src="https://wire.example.test/image.jpg"' ), 'Image cards must render the featured image.' );
expect( false !== strpos( $image_card, 'alt="Original image alt"' ), 'Image cards must preserve attachment alt output.' );
expect( false !== strpos( $image_card, 'class="card-link-target"' ), 'Image cards must retain the whole-card title link.' );
expect( false !== strpos( $image_card, 'class="taxonomy-badges"><a href=' ), 'Image cards must retain clickable taxonomy badges.' );

$fallback_card = render_card( false );

expect( false !== strpos( $fallback_card, 'festival-wire-card-image festival-wire-card-image--fallback' ), 'Image-less cards must render the fallback media region.' );
expect( false !== strpos( $fallback_card, '<span>Festival Wire</span>' ), 'The fallback must identify the Festival Wire.' );
expect( false !== strpos( $fallback_card, 'aria-label="Test Festival Story"' ), 'The fallback media link must have the post title as its accessible name.' );
expect( false === strpos( $fallback_card, '<img' ), 'The fallback must not render a broken image element.' );
expect( false === strpos( $fallback_card, 'src=' ), 'The fallback must not request a missing media asset.' );
expect( false !== strpos( $fallback_card, 'class="card-link-target"' ), 'Image-less cards must retain the whole-card title link.' );
expect( false !== strpos( $fallback_card, 'class="taxonomy-badges"><a href=' ), 'Image-less cards must retain clickable taxonomy badges.' );

print "Festival Wire content card smoke test passed.\n";
