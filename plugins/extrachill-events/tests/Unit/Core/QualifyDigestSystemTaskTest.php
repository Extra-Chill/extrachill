<?php
/**
 * Tests for QualifyDigestSystemTask scaffolding.
 *
 * The task body delegates to the qualify-digest ability; behaviour there
 * is covered in QualifyDigestAbilityTest. This file asserts the task
 * declares the contract pieces RecurringScheduleRegistry + TaskRegistry
 * rely on:
 *
 *  - getTaskType() returns the registered slug
 *  - getTaskMeta() exposes setting_key + label
 *
 * We stub the DataMachine SystemTask base class so the test runs without
 * the WP test framework or DM core booted.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

// Provide a stub for DataMachine\Engine\AI\System\Tasks\SystemTask before
// the task class loads.
require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once __DIR__ . '/Stubs/system-task-base-stub.php';
require_once dirname( __DIR__, 3 ) . '/inc/Steps/QualifyDigest/QualifyDigestSystemTask.php';

class QualifyDigestSystemTaskTest extends TestCase {

	public function test_task_type_slug(): void {
		$task = new \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask();
		$this->assertSame( 'extrachill_qualify_digest', $task->getTaskType() );
		$this->assertSame( 'extrachill_qualify_digest', \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::TASK_TYPE );
	}

	public function test_task_meta_exposes_setting_key_and_default_enabled(): void {
		$meta = \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::getTaskMeta();

		$this->assertArrayHasKey( 'label', $meta );
		$this->assertArrayHasKey( 'description', $meta );
		$this->assertSame( 'dme_qualify_digest_enabled', $meta['setting_key'] );
		$this->assertTrue( $meta['default_enabled'] );
		$this->assertTrue( $meta['supports_run'] );
	}

	/**
	 * The weekly schedule is site-scoped, so Data Machine fires it with an
	 * empty context; without this opt-out TaskScheduler rejects every tick
	 * with task_scheduler_agent_context_required (#904).
	 */
	public function test_site_scoped_schedule_does_not_require_agent_context(): void {
		$task = new \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask();
		$this->assertFalse( $task->requiresAgentContext() );
	}

	/** The principal-less digest must send through the queued (system-issuer) path (#904). */
	public function test_digest_sends_through_queued_email_ability(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/QualifyDigestAbilities.php' );
		$this->assertStringContainsString( "wp_get_ability( 'datamachine/send-email-queued' )", $source );
		$this->assertStringNotContainsString( "wp_get_ability( 'datamachine/send-email' )", $source );
	}
}
