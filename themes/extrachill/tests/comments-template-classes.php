<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI test script, not plugin runtime.
/**
 * Source-level guard for issue #1632: the comments template must only use
 * classes the theme's stylesheets define, plus the fixed set of WordPress
 * semantic hook classes it has always emitted without theme rules. A new,
 * invented class fails this test.
 *
 * @package ExtraChill
 */

$theme_dir = dirname( __DIR__ );
$template  = (string) file_get_contents( $theme_dir . '/inc/single/comments.php' );

if ( '' === $template ) {
	fwrite( STDERR, "Could not read inc/single/comments.php.\n" );
	exit( 1 );
}

$css = (string) file_get_contents( $theme_dir . '/style.css' );
foreach ( glob( $theme_dir . '/assets/css/*.css' ) as $stylesheet ) {
	$css .= "\n" . (string) file_get_contents( $stylesheet );
}

// WordPress/microformat hook classes the template emits without theme rules.
$semantic_classes = array(
	'vcard',
	'comment-meta',
	'comment-author',
	'comment-date-time',
	'comment-awaiting-moderation',
	'comments-login-note',
	'no-comments',
	'screen-reader-text',
);

$classes = array();
preg_match_all( '/class="([^"<]*)"/', $template, $attribute_matches );
preg_match_all( "/'class[a-z_]*'\s*=>\s*'([^']*)'/", $template, $argument_matches );
foreach ( array_merge( $attribute_matches[1], $argument_matches[1] ) as $class_list ) {
	foreach ( preg_split( '/\s+/', trim( $class_list ) ) as $class_name ) {
		if ( '' !== $class_name ) {
			$classes[ $class_name ] = true;
		}
	}
}

if ( empty( $classes ) ) {
	fwrite( STDERR, "Found no classes in inc/single/comments.php.\n" );
	exit( 1 );
}

$failures = 0;
foreach ( array_keys( $classes ) as $class_name ) {
	if ( in_array( $class_name, $semantic_classes, true ) ) {
		continue;
	}
	if ( ! preg_match( '/\.' . preg_quote( $class_name, '/' ) . '(?![\w-])/', $css ) ) {
		fwrite( STDERR, "FAIL: comments template class '{$class_name}' is not defined in the theme stylesheets.\n" );
		++$failures;
	}
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo "comments template classes ok\n";
