<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI test script, not plugin runtime.
/**
 * Source-level regression coverage for single-post byline and mobile image styles.
 *
 * @package ExtraChill
 */

$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/single-post.css' );

if ( false === $css ) {
	fwrite( STDERR, "Could not read assets/css/single-post.css.\n" );
	exit( 1 );
}

$failures = array();

// Strip comments so assertions only see live rules.
$css = preg_replace( '#/\*.*?\*/#s', '', $css );

// 1. Byline author links (core rel="author" and Co-Authors Plus links) are bold and link-colored.
if ( ! preg_match( '/\.single-post-card\s*>\s*\.below-entry-meta\s+\.meta-top-row\s+a\[rel="author"\]\s*\{([^}]*)\}/', $css, $byline ) ) {
	$failures[] = 'Missing scoped byline rule for a[rel="author"] in the single-post meta row.';
} else {
	if ( ! preg_match( '/font-weight\s*:\s*(600|700|bold)\s*;/', $byline[1] ) ) {
		$failures[] = 'Byline author link is not bold.';
	}
	if ( false === strpos( $byline[1], 'var(--link-color)' ) ) {
		$failures[] = 'Byline author link does not use the --link-color token.';
	}
}

// 2. In-article images and embeds must not break out of the card padding on phones.
if ( preg_match( '/@media\s*\(max-width:\s*480px\)\s*\{(.*?)\n\}/s', $css, $mobile ) ) {
	if ( preg_match( '/margin-(left|right)\s*:\s*calc\(\s*-1\s*\*/', $mobile[1] ) ) {
		$failures[] = 'Mobile single-post rules still apply negative side margins (image breakout).';
	}
	if ( preg_match( '/\.wp-block-(image|embed)[^{]*\{[^}]*border-radius\s*:\s*0\s*;/', $mobile[1] ) ) {
		$failures[] = 'Mobile single-post rules still strip image/embed border-radius.';
	}
} else {
	$failures[] = 'Could not find the 480px single-post media query.';
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Single-post byline is bold/linked and mobile images stay inside the card.\n";
