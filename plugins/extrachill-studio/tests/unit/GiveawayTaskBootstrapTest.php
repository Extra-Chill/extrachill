<?php
/**
 * Giveaway task bootstrap contract tests.
 *
 * Ports the standalone tests/giveaway-task-bootstrap.php harness to real
 * WordPress. Studio boots without Data Machine; its giveaway task must only
 * register once the SystemTask parent class becomes available late.
 *
 * @package ExtraChillStudio
 */

/**
 * Minimal stand-in for the Data Machine task parent, aliased into place by
 * the test's autoloader to model Data Machine registering its autoloader
 * after Studio has bootstrapped.
 */
abstract class Test_System_Task {
	abstract public function executeTask( int $jobId, array $params ): void;

	abstract public function getTaskType(): string;
}

/**
 * Verify the Data Machine giveaway task registration contract.
 */
class Test_Giveaway_Task_Bootstrap extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	public function test_giveaway_task_registers_only_once_its_parent_becomes_available(): void {
		$existing = array( 'existing' => 'ExistingTask' );

		$this->assertFalse(
			class_exists( 'DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask', false ),
			'Precondition: this test runtime must not preload Data Machine.'
		);
		$this->assertSame(
			$existing,
			apply_filters( 'datamachine_tasks', $existing ),
			'An unavailable giveaway task must not be registered.'
		);
		$this->assertFalse(
			class_exists( 'ExtraChillStudio\\Tasks\\GiveawayTask', false ),
			'The task must not load without its parent.'
		);

		$aliaser = static function ( $class ) {
			if ( 'DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask' === $class ) {
				class_alias( Test_System_Task::class, $class );
			}
		};
		spl_autoload_register( $aliaser );

		try {
			// Other network plugins (e.g. extrachill-network) may contribute
			// their own tasks through the same filter once the parent class
			// exists; only Studio's own registration is asserted here.
			$this->assertSame(
				'ExtraChillStudio\\Tasks\\GiveawayTask',
				apply_filters( 'datamachine_tasks', $existing )['giveaway'] ?? null,
				'Giveaway must register when its parent is available.'
			);
			$this->assertSame(
				'ExtraChillStudio\\Tasks\\GiveawayTask',
				apply_filters( 'datamachine_tasks', $existing )['giveaway'] ?? null,
				'Repeat registry reads stay stable.'
			);
		} finally {
			spl_autoload_unregister( $aliaser );
		}

		$task = new ExtraChillStudio\Tasks\GiveawayTask();
		$this->assertInstanceOf( Test_System_Task::class, $task, 'Registered task must be a usable SystemTask subclass.' );
		$this->assertSame( 'giveaway', $task->getTaskType() );
	}
}
