<?php
/**
 * Source-level contract: giveaway commands forward --entry-mode to
 * extrachill/run-giveaway only when set, so the ability owns the default.
 */

$command  = file_get_contents( dirname( __DIR__ ) . '/inc/Commands/Giveaway/GiveawayCommand.php' );
$failures = array();

$check = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$check( 2 === substr_count( $command, '[--entry-mode=<mode>]' ), 'Both run and schedule must document --entry-mode.' );
$check( 2 === substr_count( $command, "*   - per_comment\n\t *   - per_user" ), '--entry-mode must offer exactly the ability enum.' );
$check( false !== strpos( $command, "if ( isset( \$assoc_args['entry-mode'] ) ) {\n\t\t\t\$input['entry_mode'] = \$assoc_args['entry-mode'];" ), 'entry_mode must only be forwarded when --entry-mode is set.' );
$check( false !== strpos( $command, '$ability->execute( self::ability_input( $args[0], $require_tag, $assoc_args ) )' ), 'run must build ability input through the shared mapper.' );
$check( false !== strpos( $command, '$params = self::ability_input( $args[0], $require_tag, $assoc_args );' ), 'schedule must build task params through the shared mapper.' );
$check( false !== strpos( $command, "\$stats['unique_users']" ), 'run output must show how many people are in the pool.' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "GiveawayEntryModeContract tests passed.\n" );
