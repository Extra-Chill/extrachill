<?php
/**
 * Source guard for native sharing, X, and the share template class vocabulary.
 *
 * @package ExtraChill
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI source inspection and failure reporting.

$share_template = (string) file_get_contents( dirname( __DIR__ ) . '/inc/core/templates/share.php' );
$share_script   = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/share.js' );

if ( ! preg_match( '/<a\b[^\n]*data-share-destination="twitter"[^>]*>X<\/a>/', $share_template ) ) {
	fwrite( STDERR, "FAIL: X must stay in the share menu.\n" );
	exit( 1 );
}
if ( false === strpos( $share_template, 'data-native-share-url=' ) || false === strpos( $share_script, 'navigator.share(' ) ) {
	fwrite( STDERR, "FAIL: Share must wire up native sharing.\n" );
	exit( 1 );
}

// Pinned design-system classes, plus the wrapper hook used by event and blog layouts.
$share_allowed = array( 'ec-mini-dropdown', 'ec-mini-dropdown-toggle', 'ec-mini-dropdown-menu', 'button-2', 'share-dropdown' );
$share_markup  = preg_replace( '/<\?php.*?\?>/s', '', $share_template );
preg_match_all( '/\bclass=["\']([^"\']+)["\']/', $share_markup, $share_matches );
$share_classes = preg_split( '/\s+/', trim( implode( ' ', $share_matches[1] ) ), -1, PREG_SPLIT_NO_EMPTY );
$share_unknown = array_diff( $share_classes, $share_allowed );

if ( empty( $share_classes ) || $share_unknown ) {
	fwrite( STDERR, 'FAIL: Missing or non-design-system share template classes: ' . implode( ', ', $share_unknown ) . "\n" );
	exit( 1 );
}

echo "Share template retains X and uses native sharing and design-system classes.\n";
