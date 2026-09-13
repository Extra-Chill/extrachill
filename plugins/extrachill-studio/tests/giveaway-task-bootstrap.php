<?php
/**
 * Run with: php tests/giveaway-task-bootstrap.php
 * Verifies Studio bootstrap without DM, then late dependency availability.
 */

define( 'ABSPATH', __DIR__ . '/' );

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://studio.example.test/'; }
function plugin_basename( $file ) { return basename( $file ); }
function register_activation_hook() {}
function register_deactivation_hook() {}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['test_hooks'][ $hook ][ $priority ][] = $callback;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	$priorities = $GLOBALS['test_hooks'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value );
		}
	}
	return $value;
}

require dirname( __DIR__ ) . '/extrachill-studio.php';
apply_filters( 'plugins_loaded', null );

$existing = array( 'existing' => 'ExistingTask' );
if ( $existing !== apply_filters( 'datamachine_tasks', $existing ) ) {
	throw new RuntimeException( 'An unavailable giveaway task must not be registered.' );
}
if ( class_exists( 'ExtraChillStudio\\Tasks\\GiveawayTask', false ) ) {
	throw new RuntimeException( 'The task must not load without its parent.' );
}

// Model DM registering its autoloader after Studio has bootstrapped.
abstract class Test_System_Task {
	abstract public function executeTask( int $jobId, array $params ): void;
	abstract public function getTaskType(): string;
}
spl_autoload_register( static function ( $class ) {
	if ( 'DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask' === $class ) {
		class_alias( Test_System_Task::class, $class );
	}
} );

$expected = $existing + array( 'giveaway' => 'ExtraChillStudio\\Tasks\\GiveawayTask' );
foreach ( array( 1, 2 ) as $attempt ) {
	if ( $expected !== apply_filters( 'datamachine_tasks', $existing ) ) {
		throw new RuntimeException( 'Giveaway must register when its parent is available.' );
	}
}
$task = new ExtraChillStudio\Tasks\GiveawayTask();
if ( ! $task instanceof Test_System_Task || 'giveaway' !== $task->getTaskType() ) {
	throw new RuntimeException( 'Registered task must be a usable SystemTask subclass.' );
}

echo "Giveaway task bootstrap contract passed.\n";
