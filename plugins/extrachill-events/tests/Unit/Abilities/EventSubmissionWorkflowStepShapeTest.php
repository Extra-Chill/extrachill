<?php
/**
 * Tests for the workflow step shape fix (issue #912).
 *
 * Root cause: `EventSubmissionAbilities::buildWorkflow()` built the legacy
 * Data Machine workflow step shape — `'type'` instead of `'step_type'`,
 * singular `'handler_slug'`/`'handler_config'` instead of plural
 * `'handler_slugs'`/`'handler_configs'` keyed by handler slug, and the
 * retired `'update'` step type instead of `'upsert'`. Production's
 * `WorkflowSpecValidator::validate()` rejects that shape outright — every
 * anonymous event submission failed with
 * `400 invalid_workflow: Step 0 missing step_type`, caught by the
 * extrachill-network `anonymous-event-submission` journey.
 *
 * This suite mirrors `WorkflowSpecValidator`'s rules directly (this repo
 * cannot load Data Machine's real class — see
 * EventSubmissionExecuteDirectAuthContextTest.php's docblock for why this
 * plain-PHP harness convention exists) and drives the workflow through
 * `executeDirect()` exactly as production does, capturing what actually
 * reaches the `datamachine/execute-workflow` ability — both with and
 * without a stored flyer.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Isolated unit fixture intentionally mixes namespaced test doubles.

namespace DataMachine\Abilities {

	/**
	 * Minimal stand-in for Data Machine's real PermissionHelper — see
	 * EventSubmissionExecuteDirectAuthContextTest.php for the full
	 * rationale. This suite only needs the elevation to succeed so the
	 * stub `datamachine/execute-workflow` ability's execute() runs and
	 * captures the workflow it was handed.
	 */
	if ( ! class_exists( __NAMESPACE__ . '\\PermissionHelper' ) ) {
		class PermissionHelper {
			public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ) {
				unset( $acting_user_id );
				return $callback();
			}
		}
	}
}

namespace DataMachine\Core\FilesRepository {

	/**
	 * Minimal stand-in for Data Machine's file storage primitive.
	 *
	 * `storeFlyer()` calls `store_file()` with the already-uploaded file;
	 * this stub returns a deterministic fake stored path without touching
	 * the filesystem.
	 */
	if ( ! class_exists( __NAMESPACE__ . '\\FileStorage' ) ) {
		class FileStorage {
			public function store_file( string $file, string $original_name, array $context = array() ) {
				unset( $file, $original_name, $context );
				return '/dm-files/direct/stored-flyer.jpg';
			}
		}
	}
}

namespace {

	// storeFlyer() needs these two core helpers after wp_handle_upload();
	// tests/fixtures/wp-admin/includes/file.php supplies the
	// wp_handle_upload() stub itself (required via ABSPATH by the source
	// under test), following the same fixture pattern as
	// tests/fixtures/wp-admin/includes/upgrade.php's dbDelta() stub.
	if ( ! function_exists( 'wp_check_filetype' ) ) {
		function wp_check_filetype( $filename, $mimes = null ) {
			unset( $mimes );
			$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
			$map = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
			);
			return array(
				'ext'             => $ext,
				'type'            => $map[ $ext ] ?? false,
				'proper_filename' => false,
			);
		}
	}

	if ( ! function_exists( 'sanitize_file_name' ) ) {
		function sanitize_file_name( $filename ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $filename );
		}
	}

	// executeDirect() -> notifyAdmin() calls the real get_option(); this
	// bootstrap does not define it. Returning '' makes notifyAdmin() take
	// its existing early-return branch, so this suite doesn't need to also
	// stub get_bloginfo()/admin_url()/esc_html() to reach the workflow
	// shape under test.
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $option, $default = false ) {
			unset( $option, $default );
			return '';
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {

	use ExtraChillEvents\Abilities\EventSubmissionAbilities;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php';

	/**
	 * Stub for `datamachine/execute-workflow` that just records the
	 * workflow it was handed and returns a fake job id — this suite is
	 * about the shape of `args['workflow']`, not the permission gate
	 * (covered separately by EventSubmissionExecuteDirectAuthContextTest).
	 */
	final class StubExecuteWorkflowAbilityForShapeTest {
		/** @var array[] Every execute() call's args, in order. */
		public array $calls = array();

		public function execute( array $args ) {
			$this->calls[] = $args;
			return array( 'job_id' => 7331 );
		}
	}

	final class EventSubmissionWorkflowStepShapeTest extends TestCase {

		/**
		 * Step types Data Machine's WorkflowSpecValidator recognizes.
		 * Mirrors the validator's allow-list; kept in sync manually since
		 * this repo cannot load the real class in a plain-PHP unit test.
		 */
		private const VALID_STEP_TYPES = array(
			'fetch',
			'publish',
			'upsert',
			'ai',
			'webhook_gate',
			'system_task',
			'event_import',
		);

		private StubExecuteWorkflowAbilityForShapeTest $stub_ability;

		protected function setUp(): void {
			parent::setUp();

			$this->stub_ability = new StubExecuteWorkflowAbilityForShapeTest();

			$GLOBALS['ec_test_ability_resolver'] = function ( $name ) {
				return 'datamachine/execute-workflow' === $name ? $this->stub_ability : null;
			};
			$GLOBALS['ec_test_filters']                    = array();
			$GLOBALS['ec_test_wp_handle_upload_error']     = null;
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['ec_test_ability_resolver'],
				$GLOBALS['ec_test_filters'],
				$GLOBALS['ec_test_wp_handle_upload_error']
			);
			parent::tearDown();
		}

		private function submission( string $event_title = 'DIY Basement Show' ): array {
			return array(
				'user_id'       => 0,
				'contact_name'  => 'Anonymous Submitter',
				'contact_email' => '',
				'event_title'   => $event_title,
				'event_date'    => '2026-08-01',
				'event_time'    => '20:00',
				'venue_name'    => 'The Venue',
				'event_city'    => 'Oakland',
				'event_lineup'  => 'Band A, Band B',
				'event_link'    => 'https://example.test/tickets',
				'notes'         => 'BYOB',
			);
		}

		/** Invoke the private executeDirect() method via reflection. */
		private function execute_direct( array $submission, ?array $flyer ) {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = $reflection->getMethod( 'executeDirect' );
			$method->setAccessible( true );

			return $method->invoke( $instance, $submission, $flyer, '' );
		}

		/**
		 * Faithful re-implementation of WorkflowSpecValidator's per-step
		 * rules, per the shape production validated `true` against:
		 * every step needs a non-empty `step_type` drawn from the known
		 * set, and none of the legacy singular fields may be present.
		 */
		private function assertStepIsValidatorCompliant( array $step, string $context ): void {
			$this->assertArrayHasKey( 'step_type', $step, "$context: step must have a step_type key." );
			$this->assertNotEmpty( $step['step_type'], "$context: step_type must not be empty (this is exactly Data Machine's \"Step N missing step_type\" rejection)." );
			$this->assertContains(
				$step['step_type'],
				self::VALID_STEP_TYPES,
				"$context: step_type '{$step['step_type']}' is not one of Data Machine's recognized step types."
			);

			foreach ( array( 'type', 'handler', 'handler_slug', 'handler_config' ) as $legacy_key ) {
				$this->assertArrayNotHasKey(
					$legacy_key,
					$step,
					"$context: '$legacy_key' is a legacy field the validator rejects."
				);
			}

			if ( array_key_exists( 'handler_slugs', $step ) ) {
				$this->assertIsArray( $step['handler_slugs'], "$context: handler_slugs must be an array." );
				$this->assertArrayHasKey( 'handler_configs', $step, "$context: handler_slugs requires a matching handler_configs map." );
				$this->assertIsArray( $step['handler_configs'], "$context: handler_configs must be an array." );

				foreach ( $step['handler_slugs'] as $slug ) {
					$this->assertArrayHasKey(
						$slug,
						$step['handler_configs'],
						"$context: handler_configs must be keyed by each entry in handler_slugs (here: '$slug')."
					);
				}
			}
		}

		/**
		 * Core regression proof for #912: an anonymous submission with no
		 * flyer produces a two-step workflow (ai, upsert) where every step
		 * validates against the target shape production confirmed passes
		 * WorkflowSpecValidator::validate().
		 */
		public function test_workflow_without_flyer_is_validator_compliant(): void {
			$result = $this->execute_direct( $this->submission(), null );

			$this->assertIsArray( $result );
			$this->assertSame( 7331, $result['job_id'] );
			$this->assertCount( 1, $this->stub_ability->calls );

			$workflow = $this->stub_ability->calls[0]['workflow'];
			$this->assertArrayHasKey( 'steps', $workflow );
			$steps = $workflow['steps'];

			$this->assertCount( 2, $steps, 'No flyer: expect exactly [ai, upsert].' );

			foreach ( $steps as $index => $step ) {
				$this->assertStepIsValidatorCompliant( $step, "Step $index" );
			}

			$this->assertSame( 'ai', $steps[0]['step_type'] );
			$this->assertArrayHasKey( 'system_prompt', $steps[0] );
			$this->assertArrayHasKey( 'user_message', $steps[0] );
			$this->assertSame( array( 'upsert_event' ), $steps[0]['enabled_tools'] );
			// The ephemeral workflow factory doesn't consume provider/model.
			$this->assertArrayNotHasKey( 'provider', $steps[0] );
			$this->assertArrayNotHasKey( 'model', $steps[0] );

			$this->assertSame( 'upsert', $steps[1]['step_type'] );
			$this->assertSame( array( 'upsert_event' ), $steps[1]['handler_slugs'] );
			$this->assertSame( 'pending', $steps[1]['handler_configs']['upsert_event']['post_status'] );
			$this->assertFalse( $steps[1]['handler_configs']['upsert_event']['include_images'] );
		}

		/**
		 * With a stored flyer, a leading `event_import` step is prepended
		 * ahead of [ai, upsert] and must be just as validator-compliant —
		 * this is the step the original bug report's shape (`'type' =>
		 * 'event_import', 'handler_slug' => 'event_flyer', ...`) most
		 * directly affected.
		 */
		public function test_workflow_with_flyer_is_validator_compliant_and_includes_event_import(): void {
			$flyer = array(
				'tmp_name' => '/tmp/ec-test-flyer-does-not-exist.jpg',
				'name'     => 'flyer.jpg',
				'type'     => 'image/jpeg',
				'size'     => 12345,
				'error'    => 0,
			);

			$result = $this->execute_direct( $this->submission(), $flyer );

			$this->assertIsArray( $result );
			$this->assertSame( 7331, $result['job_id'] );
			$this->assertCount( 1, $this->stub_ability->calls );

			$workflow = $this->stub_ability->calls[0]['workflow'];
			$steps    = $workflow['steps'];

			$this->assertCount( 3, $steps, 'With a flyer: expect exactly [event_import, ai, upsert].' );

			foreach ( $steps as $index => $step ) {
				$this->assertStepIsValidatorCompliant( $step, "Step $index" );
			}

			$this->assertSame( 'event_import', $steps[0]['step_type'] );
			$this->assertSame( array( 'event_flyer' ), $steps[0]['handler_slugs'] );
			$this->assertArrayHasKey( 'event_flyer', $steps[0]['handler_configs'] );
			$this->assertSame( 'DIY Basement Show', $steps[0]['handler_configs']['event_flyer']['title'] );

			$this->assertSame( 'ai', $steps[1]['step_type'] );
			$this->assertSame( 'upsert', $steps[2]['step_type'] );
			$this->assertSame( array( 'upsert_event' ), $steps[2]['handler_slugs'] );
			$this->assertSame( 'pending', $steps[2]['handler_configs']['upsert_event']['post_status'] );
			// A stored flyer flips include_images to true.
			$this->assertTrue( $steps[2]['handler_configs']['upsert_event']['include_images'] );

			$this->assertArrayHasKey( 'initial_data', $this->stub_ability->calls[0] );
			$this->assertSame(
				'/dm-files/direct/stored-flyer.jpg',
				$this->stub_ability->calls[0]['initial_data']['image_file_path']
			);
		}
	}
}
