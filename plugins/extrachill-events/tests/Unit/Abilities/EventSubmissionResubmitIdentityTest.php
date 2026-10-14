<?php
/**
 * Tests for resubmission identity (issue #964).
 *
 * A submitter who resubmits the same show used to create a second pending
 * event: the AI step rewrote the title and the venue string changed, so
 * Data Machine Events' fuzzy duplicate detection missed the first one.
 *
 * executeDirect() now seeds `source_type` + `item_identifier` into the
 * workflow's initial data. Data Machine Events' upsert hashes those into a
 * source identity and resolves the event that already claims it before any
 * fuzzy matching, so the resubmission updates the first pending event.
 *
 * This suite drives executeDirect() exactly as production does (same
 * plain-PHP harness as EventSubmissionWorkflowStepShapeTest) and asserts
 * what reaches `datamachine/execute-workflow`.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Isolated unit fixture intentionally mixes namespaced test doubles.

namespace DataMachine\Abilities {

	if ( ! class_exists( __NAMESPACE__ . '\\PermissionHelper' ) ) {
		class PermissionHelper {
			public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ) {
				unset( $acting_user_id );
				return $callback();
			}
		}
	}
}

namespace {

	/**
	 * WP_Query double: records every query and answers from
	 * `$GLOBALS['ec_test_wp_query_posts']`, keyed by the source-identity
	 * meta value the query asks for.
	 */
	if ( ! class_exists( 'WP_Query' ) ) {
		class WP_Query {
			public array $posts = array();

			public function __construct( array $args = array() ) {
				$GLOBALS['ec_test_wp_query_calls'][] = $args;
				$value                               = (string) ( $args['meta_query'][0]['value'] ?? '' );
				$this->posts                         = $GLOBALS['ec_test_wp_query_posts'][ $value ] ?? array();
			}
		}
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {

	use ExtraChillEvents\Abilities\EventSubmissionAbilities;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php';

	final class StubExecuteWorkflowAbilityForResubmitTest {
		/** @var array[] Every execute() call's args, in order. */
		public array $calls = array();

		public function execute( array $args ) {
			$this->calls[] = $args;
			return array( 'job_id' => 964 );
		}
	}

	final class EventSubmissionResubmitIdentityTest extends TestCase {

		private StubExecuteWorkflowAbilityForResubmitTest $stub_ability;

		protected function setUp(): void {
			parent::setUp();

			$this->stub_ability = new StubExecuteWorkflowAbilityForResubmitTest();

			$GLOBALS['ec_test_ability_resolver'] = function ( $name ) {
				return 'datamachine/execute-workflow' === $name ? $this->stub_ability : null;
			};
			$GLOBALS['ec_test_filters']          = array();
			$GLOBALS['ec_test_system_agent']     = array(
				'agent_id' => 6,
				'user_id'  => 1,
			);
			$GLOBALS['ec_test_wp_query_posts']   = array();
			$GLOBALS['ec_test_wp_query_calls']   = array();
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['ec_test_system_agent'],
				$GLOBALS['ec_test_ability_resolver'],
				$GLOBALS['ec_test_filters'],
				$GLOBALS['ec_test_wp_query_posts'],
				$GLOBALS['ec_test_wp_query_calls']
			);
			parent::tearDown();
		}

		private function submission( array $overrides = array() ): array {
			return array_merge(
				array(
					'user_id'       => 137,
					'contact_name'  => 'Christopher Judge',
					'contact_email' => '',
					'event_title'   => 'Nightmare On King St',
					'event_date'    => '2026-10-18',
					'event_time'    => '20:00',
					'venue_name'    => 'Recoery Room Tavern',
					'event_city'    => 'Charleston',
					'event_lineup'  => 'Wolfgang Zimmerman',
					'event_link'    => '',
					'notes'         => '',
				),
				$overrides
			);
		}

		/**
		 * Resolve the item identifier directly. Used for submissions carrying
		 * a contact email, which would otherwise send real notification mail
		 * from executeDirect().
		 */
		private function identifier_for( array $submission ): string {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = $reflection->getMethod( 'resolveSubmissionItemIdentifier' );
			$method->setAccessible( true );

			return $method->invoke( $instance, $submission );
		}

		/** Run executeDirect() and return the initial_data it handed the workflow. */
		private function initial_data_for( array $submission ): array {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method     = $reflection->getMethod( 'executeDirect' );
			$method->setAccessible( true );

			$result = $method->invoke( $instance, $submission, null, '' );
			$this->assertIsArray( $result );
			$this->assertSame( 964, $result['job_id'] );

			$call = end( $this->stub_ability->calls );
			return $call['initial_data'];
		}

		private static function source_identity( string $item_identifier ): string {
			return hash( 'sha256', EventSubmissionAbilities::SUBMISSION_SOURCE_TYPE . "\0" . $item_identifier );
		}

		public function test_submission_seeds_source_identity_for_upsert(): void {
			$initial = $this->initial_data_for( $this->submission() );

			$this->assertSame( 'event_submission', $initial['source_type'] );
			$this->assertStringStartsWith( 'submission:user:137:2026-10-18:', $initial['item_identifier'] );
		}

		/**
		 * The #964 scenario: the resubmission fixed the venue typo and added
		 * the lineup, and the title differs only in case and punctuation.
		 * Both submissions must carry the same identity so upsert updates
		 * the first pending event.
		 */
		public function test_resubmission_with_corrected_details_reuses_the_identity(): void {
			$first  = $this->initial_data_for( $this->submission() );
			$second = $this->initial_data_for(
				$this->submission(
					array(
						'event_title'  => 'NIGHTMARE on King St.',
						'venue_name'   => 'Recovery Room Tavern',
						'event_lineup' => 'Wolfgang Zimmerman, Cosmic Dust, Slowcaster',
						'event_time'   => '21:00',
						'notes'        => 'Doors at 8, $10',
					)
				)
			);

			$this->assertSame( $first['item_identifier'], $second['item_identifier'] );
		}

		public function test_identity_differs_by_date_title_and_submitter(): void {
			$base = $this->initial_data_for( $this->submission() )['item_identifier'];

			$this->assertNotSame( $base, $this->initial_data_for( $this->submission( array( 'event_date' => '2026-10-19' ) ) )['item_identifier'] );
			$this->assertNotSame( $base, $this->initial_data_for( $this->submission( array( 'event_title' => 'Halloween Bash' ) ) )['item_identifier'] );
			$this->assertNotSame( $base, $this->initial_data_for( $this->submission( array( 'user_id' => 138 ) ) )['item_identifier'] );
		}

		public function test_contact_without_account_is_keyed_by_email(): void {
			$a = $this->identifier_for(
				$this->submission(
					array(
						'user_id'       => 0,
						'contact_email' => 'submitter@example.test',
					)
				)
			);
			$b = $this->identifier_for(
				$this->submission(
					array(
						'user_id'       => 0,
						'contact_email' => ' Submitter@Example.test ',
					)
				)
			);
			$c = $this->identifier_for(
				$this->submission(
					array(
						'user_id'       => 0,
						'contact_email' => 'someone-else@example.test',
					)
				)
			);

			$this->assertStringStartsWith( 'submission:email:', $a );
			$this->assertStringNotContainsString( 'submitter@example.test', $a, 'The raw email must not be stored in engine data.' );
			$this->assertSame( $a, $b );
			$this->assertNotSame( $a, $c );
		}

		public function test_account_takes_precedence_over_email(): void {
			$this->assertSame(
				$this->identifier_for( $this->submission() ),
				$this->identifier_for( $this->submission( array( 'contact_email' => 'submitter@example.test' ) ) )
			);
		}

		public function test_submission_without_any_submitter_identity_gets_no_source_identity(): void {
			$initial = $this->initial_data_for(
				$this->submission(
					array(
						'user_id'       => 0,
						'contact_email' => '',
					)
				)
			);

			$this->assertArrayNotHasKey( 'source_type', $initial );
			$this->assertArrayNotHasKey( 'item_identifier', $initial );
			$this->assertSame( array(), $GLOBALS['ec_test_wp_query_calls'] );
		}

		public function test_pending_claimant_is_updated_in_place(): void {
			$identifier = $this->initial_data_for( $this->submission() )['item_identifier'];

			$query = end( $GLOBALS['ec_test_wp_query_calls'] );
			$this->assertSame( array( 'publish', 'future', 'private', 'trash' ), $query['post_status'], 'Only reviewed events may block identity reuse; pending and draft are updated in place.' );
			$this->assertSame( '_datamachine_event_source_identity', $query['meta_query'][0]['key'] );
			$this->assertSame( self::source_identity( $identifier ), $query['meta_query'][0]['value'] );
		}

		/**
		 * Once the event has been reviewed and published, a resubmission
		 * must not claim it through the source identity.
		 */
		public function test_reviewed_claimant_is_never_claimed_by_a_resubmission(): void {
			$identifier = $this->initial_data_for( $this->submission() )['item_identifier'];

			$GLOBALS['ec_test_wp_query_posts'][ self::source_identity( $identifier ) ] = array( 595644 );

			$initial = $this->initial_data_for( $this->submission() );

			$this->assertArrayNotHasKey( 'source_type', $initial );
			$this->assertArrayNotHasKey( 'item_identifier', $initial );
		}

		public function test_title_normalization(): void {
			$this->assertSame( 'nightmare on king st', EventSubmissionAbilities::normalizeSubmissionTitle( '  Nightmare   On King St. ' ) );
			$this->assertSame( 'rock roll night', EventSubmissionAbilities::normalizeSubmissionTitle( 'Rock &amp; Roll — Night!' ) );
		}
	}
}
