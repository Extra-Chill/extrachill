<?php
/** Preserve the standalone PHPUnit/package proof in Homeboy's full test scope. */

$root = dirname( __DIR__ );
$commands = array(
	'composer install --working-dir=tools --no-interaction --no-progress --prefer-dist',
	'composer validate --working-dir=tools --strict',
	'composer --working-dir=tools test',
	'npm ci',
	'npm test',
);
chdir( $root );
foreach ( $commands as $command ) {
	passthru( $command, $status );
	if ( 0 !== $status ) {
		fwrite( STDERR, "Standalone proof failed: {$command}\n" );
		exit( $status );
	}
}
