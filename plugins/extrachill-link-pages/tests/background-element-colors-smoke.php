<?php
/**
 * Standalone regression test for link-page background element colors.
 *
 * Run: php plugins/extrachill-link-pages/tests/background-element-colors-smoke.php
 */

$stylesheets = array(
	dirname( __DIR__ ) . '/assets/css/extrch-links.css',
	dirname( __DIR__, 2 ) . '/extrachill-artist-platform/assets/css/extrch-links.css',
);
$selectors   = array(
	'.extrch-social-icon'         => 1,
	'.extrch-link-page-powered a' => 1,
	'.extrch-link-page-edit-btn'  => 1,
);
$failures    = array();

foreach ( $stylesheets as $path ) {
	$css = file_get_contents( $path );
	if ( false === $css ) {
		$failures[] = "Cannot read $path";
		continue;
	}

	// Strip comments and extract declaration blocks, including repeated rules.
	$css = preg_replace( '~/\*.*?\*/~s', '', $css );
	preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER );
	foreach ( $selectors as $selector => $minimum_count ) {
		$count = 0;
		foreach ( $blocks as $block ) {
			$rule_selectors = array_map( 'trim', explode( ',', $block[1] ) );
			if ( ! in_array( $selector, $rule_selectors, true ) ) {
				continue;
			}
			++$count;
			if ( false !== strpos( $block[2], '--link-page-link-text-color' ) ) {
				$failures[] = "$path: $selector uses button text color (rule $count)";
			}
			// The page text color may be reached directly or as the icon color fallback.
			if ( ! preg_match( '/color\s*:\s*var\(\s*(--link-page-icon-color\s*,\s*var\(\s*)?--link-page-text-color\s*\)/', $block[2] ) ) {
				$failures[] = "$path: $selector must use page text color (rule $count)";
			}
		}
		if ( $count < $minimum_count ) {
			$failures[] = "$path: missing expected $selector rules";
		}
	}

	// Button surfaces must retain their independently customizable text color.
	if ( ! preg_match( '/\.extrch-link-button-wrapper\s*\{[^}]*color\s*:\s*var\(--link-page-link-text-color\)/s', $css ) ) {
		$failures[] = "$path: button wrapper must retain button text color";
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Background element colors smoke test passed.\n";
