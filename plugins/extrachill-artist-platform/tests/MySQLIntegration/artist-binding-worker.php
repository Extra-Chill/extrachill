<?php
/** Execute one production binding operation in an independent process. */

require_once __DIR__ . '/bootstrap.php';

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standalone worker script; top-level locals that merely share names with WP admin globals.
$action     = $argv[1] ?? '';
$profile_id = (int) ( $argv[2] ?? 0 );
$term_id    = (int) ( $argv[3] ?? 0 );
$marker     = $argv[4] ?? '';
if ( '' !== $marker ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- readiness marker for the process-spawn proof; not WP media storage.
	file_put_contents( $marker, 'ready' );
}

if ( 'bind' !== $action ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- worker stderr diagnostics before WP is fully bootstrapped.
	fwrite( STDERR, 'Unsupported worker action.' );
	exit( 2 );
}

$result = ec_bind_artist_profile_to_term( $profile_id, $term_id );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standalone worker script; top-level local, not a WP global override.
$error = ec_get_artist_binding_failure();
echo wp_json_encode(
	array(
		'result' => $result,
		'error'  => $error instanceof WP_Error ? $error->get_error_code() : null,
	)
);
exit( $result ? 0 : 1 );
