<?php
/**
 * Regression coverage for mini-dropdown styles inside content lists.
 *
 * @package ExtraChill
 */

$md_style = file_get_contents( dirname( __DIR__ ) . '/style.css' );
$md_menu_selector = '.ec-mini-dropdown .ec-mini-dropdown-menu';
$md_item_selector = '.ec-mini-dropdown .ec-mini-dropdown-menu li';

if ( false === strpos( $md_style, $md_menu_selector ) || false === strpos( $md_style, $md_item_selector ) ) {
	fwrite( STDERR, "FAIL: Mini-dropdown menu and item selectors must include the component wrapper.\n" );
	exit( 1 );
}

if ( false !== strpos( $md_style, '.share-dropdown[aria-expanded="true"]' ) ) {
	fwrite( STDERR, "FAIL: Opening the share dropdown must not add a wrapper margin.\n" );
	exit( 1 );
}

echo "Mini-dropdown selectors protect content lists without open-state layout margin.\n";
