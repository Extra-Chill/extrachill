<?php
/**
 * Runtime guard against #268: extrachill_network_init() must bail cleanly
 * (not fatal) when the plugin is loaded outside multisite, while leaving
 * the real multisite bootstrap path completely unchanged.
 *
 * Runs tests/MultisiteGuardProbe.php in two isolated child processes — one
 * per is_multisite() outcome — because PHP can't un-define the functions
 * loaded by a "boot everything" run inside a single process.
 */

function multisite_guard_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function multisite_guard_run_probe( $mode ) {
	$command = escapeshellarg( PHP_BINARY ) . ' '
		. escapeshellarg( __DIR__ . '/MultisiteGuardProbe.php' ) . ' '
		. escapeshellarg( $mode );

	exec( $command, $output, $exit_code );

	multisite_guard_assert( 0 === $exit_code, "{$mode} probe exits cleanly (no fatal)" );

	$json = end( $output );
	$data = json_decode( (string) $json, true );

	multisite_guard_assert( is_array( $data ), "{$mode} probe prints a decodable JSON result: " . implode( "\n", $output ) );

	return $data;
}

// Non-multisite: bootstrap must be skipped, not fatal.
$single = multisite_guard_run_probe( 'single' );
multisite_guard_assert( false === $single['foundation_booted'], 'non-multisite: foundation (switch_to_blog()-dependent code) is not booted' );
multisite_guard_assert( true === $single['blog_ids_loaded'], 'non-multisite: pure blog-ID helpers (ec_get_blog_id(), ec_get_site_url()) still load for dependent plugins' );
multisite_guard_assert( 0 === $single['provider_count'], 'non-multisite: no feature providers are registered' );
multisite_guard_assert( true === $single['notice_registered'], 'non-multisite: the multisite-required admin notice is registered' );
multisite_guard_assert( '' === $single['notice_output_without_permission'], 'the multisite-required notice renders nothing for a user who cannot manage plugins' );
multisite_guard_assert( true === $single['notice_output_with_permission_has'], 'the multisite-required notice renders for a user who can manage plugins' );

// Multisite: bootstrap must run exactly as it does today.
$multisite = multisite_guard_run_probe( 'multisite' );
multisite_guard_assert( true === $multisite['foundation_booted'], 'multisite: foundation boots as before' );
multisite_guard_assert( true === $multisite['blog_ids_loaded'], 'multisite: blog-ID helpers load as before' );
multisite_guard_assert( 8 === $multisite['provider_count'], 'multisite: all 8 feature providers register as before' );
multisite_guard_assert(
	array(
		'migrations'                    => 'booted',
		'ads'                            => 'booted',
		'experiments'                    => 'booted',
		'taxonomy-classification'        => 'booted',
		'community-artist-integrations' => 'booted',
		'commerce'                       => 'booted',
		'presentation'                   => 'booted',
		'administration'                 => 'skipped',
	) === $multisite['provider_statuses'],
	'multisite: every provider reaches its normal-path status unchanged (administration skipped outside wp-admin, as designed)'
);
multisite_guard_assert( false === $multisite['notice_registered'], 'multisite: the multisite-required admin notice is never registered' );

fwrite( STDOUT, "Multisite guard checks passed.\n" );
