<?php
/**
 * Verify the Community homepage remains renderable without network helpers.
 *
 * Run: php tests/test-artist-platform-cta-dependency-absent.php
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}

require dirname( __DIR__ ) . '/inc/home/artist-platform-buttons.php';

ob_start();
ec_community_add_artist_platform_buttons();
$rendered = ob_get_clean();

if ( '' !== $rendered ) {
	echo "FAIL: dependency-absent render should be empty.\n";
	exit( 1 );
}

echo "PASS: dependency-absent render is empty and does not fail.\n";
exit( 0 );
