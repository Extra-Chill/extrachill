<?php
/**
 * Regression coverage: the comments section must load through
 * comments_template(), or the comment query never runs and published
 * comments never render.
 *
 * @package ExtraChill
 */

$actions = file_get_contents( dirname( __DIR__ ) . '/inc/core/actions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a trusted local fixture.

if ( false === $actions || ! preg_match( '/function extrachill_hook_comments_section\(\) \{(.*?)\n\}/s', $actions, $matches ) ) {
	fwrite( STDERR, "Could not find extrachill_hook_comments_section().\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
	exit( 1 );
}

if ( ! preg_match( "/comments_template\(\s*'\/inc\/single\/comments\.php'\s*\)/", $matches[1] ) ) {
	fwrite( STDERR, "The comments section must render via comments_template( '/inc/single/comments.php' ).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
	exit( 1 );
}

if ( preg_match( '/\b(require|include)(_once)?\b/', $matches[1] ) ) {
	fwrite( STDERR, "The comments section must not require the template directly; that skips the comment query.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
	exit( 1 );
}

echo "comments section hook ok\n";
