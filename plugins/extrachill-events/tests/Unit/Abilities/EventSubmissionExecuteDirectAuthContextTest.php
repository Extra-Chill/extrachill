<?php
/**
 * Tests for the executeDirect() permission-elevation fix (issue #910).
 *
 * Root cause: `datamachine/execute-workflow`'s permission_callback requires
 * `manage_options`/`datamachine_manage_*`, but the public event-submission
 * form must be reachable by anonymous visitors. `executeDirect()` now runs
 * the ability's `execute()` call inside
 * `\DataMachine\Abilities\PermissionHelper::run_as_authenticated()` — Data
 * Machine's canonical seam for callers that authorized the action at their
 * own layer (Turnstile at the REST boundary, `show_in_rest => false` on this
 * ability, a fully server-built workflow).
 *
 * This suite cannot load the real Data Machine plugin (see
 * EventSubmissionAbilitiesTest.php's docblock — that WP_UnitTestCase suite
 * is blocked by the upstream DM-core bootstrap issue). Instead it follows
 * the plain-PHP harness convention already used by
 * ArtistUrlImportNotificationTest.php: declare minimal, behaviorally
 * faithful stand-ins for the two Data Machine classes this method touches
 * (`DataMachine\Abilities\PermissionHelper`, `DataMachine\Core\PluginSettings`)
 * and load the real `EventSubmissionAbilities.php` source unmodified.
 *
 * The stub `datamachine/execute-workflow` ability's `execute()` reproduces
 * the real ability's permission gate: it returns the same
 * `ability_invalid_permissions` WP_Error unless
 * `PermissionHelper::is_authenticated_context()` is true. This makes the
 * primary test a genuine behavioral proof — not just an assertion that a
 * method got called — that an anonymous submission (no elevated
 * capabilities, no logged-in user) now succeeds specifically because
 * `executeDirect()` performs the elevation, and that the elevation is
 * scoped (reset via the `finally` block) rather than leaking.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Isolated unit fixture intentionally mixes namespaced test doubles.

namespace DataMachine\Abilities {

	/**
	 * Minimal stand-in for Data Machine's real PermissionHelper.
	 *
	 * Mirrors the one seam this fix depends on: run_as_authenticated()
	 * marks an "authenticated system context" active for the duration of
	 * the callback and always clears it afterward, even on exception —
	 * exactly like the real class's finally-block guarantee described in
	 * issue #910.
	 */
	if ( ! class_exists( __NAMESPACE__ . '\\PermissionHelper' ) ) {
		class PermissionHelper {
			private static bool $authenticated_context = false;

			/** @var int[] Acting user ids passed to run_as_authenticated(), in call order. */
			public static array $acting_user_ids = array();

			public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ) {
				self::$acting_user_ids[] = $acting_user_id;
				self::$authenticated_context = true;
				try {
					return $callback();
				} finally {
					self::$authenticated_context = false;
				}
			}

			public static function is_authenticated_context(): bool {
				return self::$authenticated_context;
			}

			/** Reset static state between tests. */
			public static function reset_for_tests(): void {
				self::$authenticated_context = false;
				self::$acting_user_ids       = array();
			}
		}
	}
}

namespace DataMachine\Core {

	/** Minimal stand-in for Data Machine's PluginSettings::get(). */
	if ( ! class_exists( __NAMESPACE__ . '\\PluginSettings' ) ) {
		class PluginSettings {
			public static function get( string $key, $default = null ) {
				return $default;
			}
		}
	}
}

namespace {

	// executeDirect() -> notifyAdmin() calls the real get_option(); this
	// bootstrap does not define it. Returning '' makes notifyAdmin() take
	// its existing early-return branch (no admin_email configured), so this
	// test doesn't need to also stub get_bloginfo()/admin_url()/esc_html()
	// to exercise the permission-elevation path under test.
	// Data Machine's system agent resolver (issue #914). Tests control the
	// resolved owner through $GLOBALS['ec_test_system_agent'].
	if ( ! function_exists( 'datamachine_resolve_system_agent_context' ) ) {
		function datamachine_resolve_system_agent_context() {
			return $GLOBALS['ec_test_system_agent'] ?? array(
				'agent_id' => 0,
				'user_id'  => 0,
			);
		}
	}

	// A mail layer that fatals the way ec_send_email() did when its queued
	// fallback returned a WP_Error (extrachill-network#316).
	if ( ! function_exists( 'extrachill_send_registration_email' ) ) {
		function extrachill_send_registration_email( array $args ) {
			if ( ! empty( $GLOBALS['ec_test_mail_throws'] ) ) {
				throw new \Error( 'Cannot use object of type WP_Error as array' );
			}
			return array( 'success' => true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $option, $default = false ) {
			return '' === $default ? '' : '';
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {

	use DataMachine\Abilities\PermissionHelper;
	use ExtraChillEvents\Abilities\EventSubmissionAbilities;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use WP_Error;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php';

	/**
	 * Stub for `datamachine/execute-workflow` that reproduces its real
	 * permission gate (`PermissionHelper::can_manage()`-style) using the
	 * stand-in PermissionHelper's authenticated-context flag.
	 */
	final class StubExecuteWorkflowAbility {
		/** @var array[] Every execute() call's args, in order. */
		public array $calls = array();

		public function execute( array $args ) {
			$this->calls[] = $args;

			if ( ! PermissionHelper::is_authenticated_context() ) {
				return new WP_Error(
					'ability_invalid_permissions',
					'Ability "datamachine/execute-workflow" does not have necessary permission',
					array( 'status' => 403 )
				);
			}

			// Mirror ExecuteWorkflowAbility::resolveOwnership(): a direct job
			// needs an acting user or an agent, or it is rejected (#914).
			$acting_ids  = PermissionHelper::$acting_user_ids;
			$acting_user = empty( $acting_ids ) ? 0 : (int) end( $acting_ids );
			$agent_id    = (int) ( $args['initial_data']['agent_id'] ?? 0 );
			if ( $acting_user <= 0 && $agent_id <= 0 ) {
				return new WP_Error(
					'workflow_ownership_denied',
					'An authenticated acting caller is required for user-scoped workflow execution.',
					array( 'status' => 403 )
				);
			}

			return array(
				'job_id' => 4242,
			);
		}
	}

	final class EventSubmissionExecuteDirectAuthContextTest extends TestCase {

		private StubExecuteWorkflowAbility $stub_ability;

		protected function setUp(): void {
			parent::setUp();

			PermissionHelper::reset_for_tests();

			$this->stub_ability = new StubExecuteWorkflowAbility();

			$GLOBALS['ec_test_ability_resolver'] = function ( $name ) {
				return 'datamachine/execute-workflow' === $name ? $this->stub_ability : null;
			};
			$GLOBALS['ec_test_filters']          = array();
			$GLOBALS['ec_test_system_agent']     = array(
				'agent_id' => 6,
				'user_id'  => 1,
			);
		}

		protected function tearDown(): void {
			unset( $GLOBALS['ec_test_ability_resolver'], $GLOBALS['ec_test_filters'], $GLOBALS['ec_test_system_agent'] );
			PermissionHelper::reset_for_tests();
			parent::tearDown();
		}

		/**
		 * Minimal anonymous-submission payload. contact_email is
		 * deliberately blank so notifySubmitter() takes its existing
		 * early-return branch — this test is scoped to the permission
		 * elevation, not the notification templates.
		 */
		private function anonymous_submission(): array {
			return array(
				'user_id'       => 0,
				'contact_name'  => 'Anonymous Submitter',
				'contact_email' => '',
				'event_title'   => 'DIY Basement Show',
				'event_date'    => '2026-08-01',
				'event_time'    => '',
				'venue_name'    => '',
				'event_city'    => '',
				'event_lineup'  => '',
				'event_link'    => '',
				'notes'         => '',
			);
		}

		/** Invoke the private executeDirect() method via reflection. */
		private function execute_direct( array $submission ) {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = $reflection->getMethod( 'executeDirect' );
			$method->setAccessible( true );

			return $method->invoke( $instance, $submission, null, '' );
		}

		/**
		 * The core regression proof for #910: an anonymous submitter (no
		 * capabilities, no logged-in user, nothing in this test grants
		 * manage_options) still gets a successful job_id back, because
		 * executeDirect() now runs the workflow ability's execute() call
		 * inside PermissionHelper::run_as_authenticated().
		 */
		public function test_anonymous_submission_executes_workflow_under_authenticated_context(): void {
			$this->assertFalse(
				PermissionHelper::is_authenticated_context(),
				'Precondition: no ambient authenticated context before the call.'
			);

			$result = $this->execute_direct( $this->anonymous_submission() );

			$this->assertIsArray( $result );
			$this->assertSame( 4242, $result['job_id'] );
			$this->assertCount( 1, $this->stub_ability->calls, 'The workflow ability must be executed exactly once.' );
			$this->assertArrayHasKey( 'workflow', $this->stub_ability->calls[0] );
			$this->assertArrayHasKey( 'initial_data', $this->stub_ability->calls[0] );

			$this->assertSame(
				array( 1 ),
				PermissionHelper::$acting_user_ids,
				'The workflow runs as the system agent\'s owner, never as the anonymous visitor.'
			);
			$this->assertSame( 6, $this->stub_ability->calls[0]['initial_data']['agent_id'], 'The job is owned by the system agent (#914).' );
			$this->assertSame( 'event_submission', $this->stub_ability->calls[0]['initial_data']['job_source'] );

			$this->assertFalse(
				PermissionHelper::is_authenticated_context(),
				'run_as_authenticated() must reset the elevated context afterward (finally block) — it must not leak.'
			);
		}

		/**
		 * Negative control: proves the stub's permission gate is real —
		 * i.e. that the anonymous submission above succeeds because of the
		 * elevation, not because the stub always allows execution.
		 */
		public function test_stub_ability_rejects_execution_outside_authenticated_context(): void {
			$result = $this->stub_ability->execute(
				array(
					'workflow'     => array(),
					'initial_data' => array(),
				)
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}

		/** Existing guard clause: still returns dm_unavailable if the ability itself can't be resolved. */
		/** A throwing mail layer is logged, never propagated to the submission. */
		public function test_notification_failure_never_throws(): void {
			$GLOBALS['ec_test_mail_throws'] = true;

			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = $reflection->getMethod( 'dispatchEmail' );
			$method->setAccessible( true );

			try {
				$method->invoke( $instance, array( 'to' => 'fan@example.com', 'subject' => 'Hi' ), 'submitter' );
				$this->addToAssertionCount( 1 );
			} finally {
				unset( $GLOBALS['ec_test_mail_throws'] );
			}
		}

		/** Without a resolvable system agent the submission fails clearly, not with an ownership error. */
		public function test_missing_system_agent_returns_owner_unavailable(): void {
			$GLOBALS['ec_test_system_agent'] = array(
				'agent_id' => 0,
				'user_id'  => 0,
			);

			$result = $this->execute_direct( $this->anonymous_submission() );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'workflow_owner_unavailable', $result->get_error_code() );
			$this->assertCount( 0, $this->stub_ability->calls );
		}

		public function test_missing_execute_workflow_ability_returns_dm_unavailable(): void {
			$GLOBALS['ec_test_ability_resolver'] = function () {
				return null;
			};

			$result = $this->execute_direct( $this->anonymous_submission() );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'dm_unavailable', $result->get_error_code() );
		}

		/**
		 * Source-level defense-in-depth: guards against a future refactor
		 * moving the execute() call back outside run_as_authenticated()
		 * without changing anything the behavioral test above can observe
		 * (e.g. if a future stub/mock happened to always allow execution).
		 */
		public function test_execute_call_site_is_wrapped_in_run_as_authenticated(): void {
			$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php' );
			$this->assertIsString( $source );

			$this->assertMatchesRegularExpression(
				'/PermissionHelper::run_as_authenticated\(\s*function\s*\(\)[^}]*\$execute->execute\(/s',
				$source,
				'executeDirect() must call $execute->execute() from within a closure passed to ' .
				'\\DataMachine\\Abilities\\PermissionHelper::run_as_authenticated().'
			);
		}
	}
}
