<?php
/**
 * Regression coverage: the comments heading and the logged-out note are
 * filterable so plugins can rename comments for their post types.
 *
 * @package ExtraChill
 */

$template = file_get_contents( dirname( __DIR__ ) . '/inc/single/comments.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a trusted local fixture.

foreach ( array( 'extrachill_comments_title', 'extrachill_comments_login_note' ) as $hook ) {
	if ( false === $template || ! str_contains( $template, "apply_filters( '{$hook}'" ) ) {
		fwrite( STDERR, "inc/single/comments.php must apply the {$hook} filter.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
		exit( 1 );
	}
}

echo "comments template filters ok\n";
