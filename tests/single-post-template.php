<?php
/**
 * Regression coverage for the singular post article classes.
 *
 * @package ExtraChill
 */

$template = file_get_contents( dirname( __DIR__ ) . '/inc/single/single-post.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a trusted local fixture.

if ( false === $template || ! preg_match( '/^\s*<article id="post-<\?php the_ID\(\); \?>".*>$/m', $template, $matches ) ) {
	fwrite( STDERR, "Could not find the singular post article opening.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
	exit( 1 );
}

/**
 * Emit a stable post ID for the template fixture.
 */
function the_ID() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress template stub.
	echo '80';
}

/**
 * Emulate WordPress core classes and a class supplied through its filter.
 *
 * @param string[] $classes Classes supplied by the template.
 */
function post_class( $classes = array() ) {
	$classes = array_merge( $classes, array( 'post-80', 'post', 'type-post', 'status-publish', 'integration-target' ) );
	echo 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
}

/**
 * Escape an attribute in the same shape as WordPress for this fixture.
 *
 * @param string $value Attribute value.
 * @return string
 */
function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

ob_start();
eval( '?>' . $matches[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Executes one trusted template line to verify rendered markup.
$article = ob_get_clean();

if ( ! preg_match( '/\bclass="([^"]*)"/', $article, $class_matches ) ) {
	fwrite( STDERR, "The singular post article did not render a class attribute.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
	exit( 1 );
}

$article_classes = preg_split( '/\s+/', trim( $class_matches[1] ) );

$expected_classes = array(
	'single-post-card',
	'ec-mobile-full-width-panel',
	'post-80',
	'post',
	'type-post',
	'status-publish',
	'integration-target',
);

foreach ( $expected_classes as $expected_class ) {
	if ( ! in_array( $expected_class, $article_classes, true ) ) {
		fwrite( STDERR, sprintf( "Missing expected article class: %s\n", $expected_class ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test failure output.
		exit( 1 );
	}
}

echo "Singular post article preserves theme, core, and filtered classes.\n";
