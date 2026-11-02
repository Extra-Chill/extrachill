<?php
/**
 * Every ability file in inc/abilities/ must be loaded by the main plugin file,
 * and the category file must load before any ability that uses the category.
 *
 * @package ExtraChillDocs
 */

$main = (string) file_get_contents( dirname( __DIR__ ) . '/extrachill-docs.php' );
preg_match_all( "#require_once EXTRACHILL_DOCS_PLUGIN_DIR \. '(inc/abilities/[^']+)'#", $main, $m );
$required = $m[1];
$failures = array();

$category_at = array_search( 'inc/abilities/category.php', $required, true );
if ( false === $category_at ) {
	$failures[] = 'inc/abilities/category.php is not required';
}

foreach ( glob( dirname( __DIR__ ) . '/inc/abilities/*.php' ) as $file ) {
	$rel = 'inc/abilities/' . basename( $file );
	$at  = array_search( $rel, $required, true );
	if ( false === $at ) {
		$failures[] = "$rel is not required";
	} elseif ( false !== $category_at && $at < $category_at && str_contains( (string) file_get_contents( $file ), "'category'" ) ) {
		$failures[] = "$rel loads before inc/abilities/category.php";
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Bootstrap requires smoke passed.\n";
