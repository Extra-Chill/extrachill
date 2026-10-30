<?php
/**
 * Enumerate the abilities a given user can actually execute.
 *
 * MUST be run with plain PHP, never through WP-CLI:
 *
 *     php tools/audit-ability-permissions.php <host> <user-id>
 *     php tools/audit-ability-permissions.php events.extrachill.com 38
 *
 * Several plugins grant unconditional permission when the WP_CLI constant is
 * defined (see issue #207), two of them with no filter to disable it. Running
 * this through `wp eval` therefore reports every gated ability as permitted,
 * for every user, including anonymous — inflating the executable count by
 * roughly 40% on this network. Bootstrapping wp-load.php directly avoids the
 * constant entirely, and the guard below refuses to run if it is somehow set.
 *
 * KNOWN LIMITATION: permissions are probed with empty input, so
 * input-dependent gates are undercounted. Venue booking abilities deny for
 * everyone including administrators without a `booking_id`, and artist-scoped
 * abilities resolve per-resource. Treat the output as a floor, not a total,
 * and check resource-scoped surfaces by hand.
 *
 * @package ExtraChillNetwork
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$host    = $argv[1] ?? '';
$user_id = isset( $argv[2] ) ? (int) $argv[2] : -1;

if ( '' === $host || $user_id < 0 ) {
	fwrite( STDERR, "usage: php tools/audit-ability-permissions.php <host> <user-id>\n" );
	fwrite( STDERR, "       user-id 0 audits the anonymous surface\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = $host;
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

define( 'WP_USE_THEMES', false );

/*
 * Resolve wp-load.php. The plugin normally sits at
 * wp-content/plugins/extrachill-network/tools/, four levels below the
 * webroot, but the file is also useful from a detached worktree — so allow
 * an explicit override and fall back to walking upward.
 */
$wp_load = getenv( 'WP_LOAD_PATH' ) ?: '';

if ( '' === $wp_load ) {
	$candidate = dirname( __DIR__, 4 ) . '/wp-load.php';
	$wp_load   = file_exists( $candidate ) ? $candidate : '';
}

if ( '' === $wp_load ) {
	$dir = __DIR__;
	while ( '/' !== $dir && '' !== $dir ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			$wp_load = $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}

if ( '' === $wp_load || ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "could not locate wp-load.php; set WP_LOAD_PATH to point at it\n" );
	exit( 2 );
}

require $wp_load;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "WP_CLI is defined — permission bypasses would fire and the result would be wrong. Aborting.\n" );
	exit( 3 );
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	fwrite( STDERR, "Abilities API unavailable on this install.\n" );
	exit( 3 );
}

wp_set_current_user( $user_id );
$user = $user_id > 0 ? get_userdata( $user_id ) : null;

$readonly = array();
$writes   = array();

foreach ( wp_get_abilities() as $name => $ability ) {
	try {
		if ( true !== $ability->check_permissions( array() ) ) {
			continue;
		}
	} catch ( \Throwable $e ) {
		continue;
	}

	$annotations = $ability->get_meta_item( 'annotations' );
	$is_readonly = is_array( $annotations ) ? ( $annotations['readonly'] ?? null ) : null;

	if ( true === $is_readonly ) {
		$readonly[] = $name;
		continue;
	}

	$writes[] = ( null === $is_readonly ? '?' : 'W' ) . ' ' . $name;
}

sort( $readonly );
sort( $writes );

printf(
	"host=%s user=%s roles=%s\n  executable=%d  readonly=%d  non-readonly=%d\n\n",
	$host,
	$user ? $user->user_login : 'anonymous',
	$user && $user->roles ? implode( ',', $user->roles ) : '-',
	count( $readonly ) + count( $writes ),
	count( $readonly ),
	count( $writes )
);

echo "non-readonly (W = declared write, ? = no readonly annotation):\n";
echo implode( "\n", $writes ) . "\n";
