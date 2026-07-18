<?php
/**
 * Source-level integration contract with Network's private experiment Abilities.
 */

$root     = dirname( __DIR__ );
$fixture  = json_decode( file_get_contents( __DIR__ . '/network-experiment-cli-contract.fixture.json' ), true );
$command  = file_get_contents( $root . '/inc/Commands/Experiments/ExperimentsCommand.php' );
$readme   = file_get_contents( $root . '/README.md' );
$failures = array();

$check = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$check( is_array( $fixture ), 'Network integration fixture must be valid JSON.' );
$check( true === $fixture['permission']['trusted_local_wp_cli'], 'Contract must require trusted local WP-CLI permission.' );
$check( false === $fixture['permission']['user_zero_outside_wp_cli'], 'Contract must deny user zero outside WP-CLI.' );
$check( 'manage_network_options' === $fixture['permission']['web_capability'], 'Web/REST permission must remain manage_network_options.' );
$check( false !== strpos( $command, "'extrachill/list-experiments'" ), 'Command must use Network list Ability exactly.' );
$check( false !== strpos( $command, "'extrachill/transition-experiment-state'" ), 'Command must use Network transition Ability exactly.' );
$check( false !== strpos( $command, "['registered']" ) && false !== strpos( $command, "['orphaned']" ), 'Command must consume both owner registry-status fields.' );
$check( false === strpos( $command, 'current_user_can' ), 'CLI must not duplicate Network permission logic.' );
$check( false === strpos( $command, 'manage_network_options' ), 'CLI must not duplicate Network capability checks.' );
$check( false !== strpos( $readme, $fixture['standard_invocation'] ), 'README must document the standard trusted local invocation.' );
$check( false !== strpos( $command, $fixture['standard_invocation'] ), 'Command help must document the standard trusted local invocation.' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "ExperimentsOwnerContract tests passed.\n" );
