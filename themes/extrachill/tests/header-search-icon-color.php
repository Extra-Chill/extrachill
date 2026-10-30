<?php
/**
 * Source-level regression coverage for header and submit search icon colors.
 *
 * Run with: php tests/header-search-icon-color.php
 *
 * @package ExtraChill
 */

$css = file_get_contents( dirname( __DIR__ ) . '/style.css' );
if ( false === $css ) {
	fwrite( STDERR, "FAIL: Cannot read style.css.\n" );
	exit( 1 );
}

$css = preg_replace( '~/\*.*?\*/~s', '', $css );

/** Return declarations for an exact selector, including grouped selectors. */
function search_icon_declarations( $css, $selector ) {
	$declarations = '';
	preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );
	foreach ( $rules as $rule ) {
		$selectors = array_map( 'trim', explode( ',', $rule[1] ) );
		if ( in_array( $selector, $selectors, true ) ) {
			$declarations .= $rule[2];
		}
	}
	return $declarations;
}

/** Fail independently of PHP's assertion configuration. */
function require_search_icon_declaration( $css, $selector, $property, $value ) {
	$declarations = search_icon_declarations( $css, $selector );
	$pattern      = '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*(?:;|$)/i';
	if ( ! preg_match( $pattern, $declarations ) ) {
		fwrite( STDERR, "FAIL: $selector must declare $property: $value.\n" );
		exit( 1 );
	}
}

require_search_icon_declaration( $css, '.search-icon', 'color', 'var(--header-text-color)' );
require_search_icon_declaration( $css, '.search-icon svg', 'fill', 'currentColor' );
require_search_icon_declaration( $css, '.search-icon:hover svg', 'fill', 'var(--accent)' );
require_search_icon_declaration( $css, '.search-icon:focus svg', 'fill', 'var(--accent)' );
require_search_icon_declaration( $css, '.search-icon.menu-open svg', 'transform', 'rotate(45deg)' );

/*
 * The submit button has no .search-icon class: its SVG keeps the shared icon
 * fill and button text color rather than receiving the header-specific color.
 */
require_search_icon_declaration( $css, '.ec-icon', 'fill', 'currentColor' );
require_search_icon_declaration( $css, 'button.button-1', 'color', 'var(--button-text-color)' );

$templates = array(
	'inc/header/header-search.php'       => 'search-icon header-right-icon',
	'inc/core/templates/searchform.php' => 'button-1 button-medium',
);
foreach ( $templates as $path => $classes ) {
	$template = file_get_contents( dirname( __DIR__ ) . '/' . $path );
	if ( false === $template || false === strpos( $template, 'class="' . $classes . '"' ) ) {
		fwrite( STDERR, "FAIL: Expected distinct header/submit classes in $path.\n" );
		exit( 1 );
	}
}

echo "Header search icon follows header text, preserves interactions, and leaves submit button colors intact.\n";
