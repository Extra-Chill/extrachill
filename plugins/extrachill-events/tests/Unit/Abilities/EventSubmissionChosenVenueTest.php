<?php
/**
 * Tests for the submission form's chosen venue (issue #965).
 *
 * A submitter typed "Recoery Room Tavern" into the free-text venue field,
 * and the upsert created a new venue term for the typo. The form now
 * suggests existing venues and submits the chosen term's ID. The ability
 * validates it, uses the venue's canonical name, and hands the ID to Data
 * Machine Events' upsert, which assigns that term directly.
 *
 * Runs in its own host suite (phpunit-submission-venue-unit.xml.dist):
 * the get_term()/get_term_meta() doubles here would collide with the
 * file-level doubles other Abilities tests declare in the shared suite.
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

	if ( ! class_exists( 'WP_Term' ) ) {
		class WP_Term {
			public int $term_id;
			public string $name;
			public string $taxonomy;

			public function __construct( int $term_id, string $name, string $taxonomy ) {
				$this->term_id  = $term_id;
				$this->name     = $name;
				$this->taxonomy = $taxonomy;
			}
		}
	}

	if ( ! function_exists( 'get_term' ) ) {
		function get_term( $term_id, $taxonomy = '' ) {
			$term = $GLOBALS['ec_test_terms'][ (int) $term_id ] ?? null;
			if ( ! $term instanceof WP_Term || ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) ) {
				return null;
			}
			return $term;
		}
	}

	if ( ! function_exists( 'get_term_meta' ) ) {
		function get_term_meta( $term_id, $key, $single = false ) {
			unset( $single );
			return $GLOBALS['ec_test_term_meta'][ (int) $term_id ][ $key ] ?? '';
		}
	}

	// Data Machine's system agent resolver, which owns the workflow.
	if ( ! function_exists( 'datamachine_resolve_system_agent_context' ) ) {
		function datamachine_resolve_system_agent_context() {
			return $GLOBALS['ec_test_system_agent'] ?? array(
				'agent_id' => 0,
				'user_id'  => 0,
			);
		}
	}

	// '' sends notifyAdmin() down its early-return branch.
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $option, $default_value = false ) {
			unset( $option, $default_value );
			return '';
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {

	use ExtraChillEvents\Abilities\EventSubmissionAbilities;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use WP_Term;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php';

	final class StubExecuteWorkflowAbilityForChosenVenueTest {
		/** @var array[] Every execute() call's args, in order. */
		public array $calls = array();

		public function execute( array $args ) {
			$this->calls[] = $args;
			return array( 'job_id' => 965 );
		}
	}

	final class EventSubmissionChosenVenueTest extends TestCase {

		private const VENUE_ID = 104506;

		private StubExecuteWorkflowAbilityForChosenVenueTest $stub_ability;

		protected function setUp(): void {
			parent::setUp();

			$this->stub_ability = new StubExecuteWorkflowAbilityForChosenVenueTest();

			$GLOBALS['ec_test_ability_resolver'] = function ( $name ) {
				return 'datamachine/execute-workflow' === $name ? $this->stub_ability : null;
			};
			$GLOBALS['ec_test_filters']          = array();
			$GLOBALS['ec_test_system_agent']     = array(
				'agent_id' => 6,
				'user_id'  => 1,
			);
			$GLOBALS['ec_test_terms']            = array(
				self::VENUE_ID => new WP_Term( self::VENUE_ID, 'Recovery Room Tavern', 'venue' ),
				777            => new WP_Term( 777, 'Some Promoter', 'promoter' ),
			);
			$GLOBALS['ec_test_term_meta']        = array(
				self::VENUE_ID => array( '_venue_city' => 'Charleston' ),
			);
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['ec_test_system_agent'],
				$GLOBALS['ec_test_ability_resolver'],
				$GLOBALS['ec_test_filters'],
				$GLOBALS['ec_test_terms'],
				$GLOBALS['ec_test_term_meta']
			);
			parent::tearDown();
		}

		private function submission( array $overrides = array() ): array {
			return array_merge(
				array(
					'user_id'       => 0,
					'contact_name'  => 'Anonymous Submitter',
					'contact_email' => '',
					'event_title'   => 'Nightmare On King St',
					'event_date'    => '2026-10-18',
					'event_time'    => '21:00',
					'venue_name'    => 'Recoery Room Tavern',
					'event_city'    => '',
					'event_lineup'  => 'Wolfgang Zimmerman',
					'event_link'    => '',
					'notes'         => '',
				),
				$overrides
			);
		}

		private function invoke( string $method, ...$args ) {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$callable   = $reflection->getMethod( $method );
			$callable->setAccessible( true );

			return $callable->invoke( $instance, ...$args );
		}

		/** Apply a venue pick, then run the workflow; returns the execute() args. */
		private function submit_with_venue( array $submission, int $venue_term_id ): array {
			$submission = $this->invoke( 'applyChosenVenue', $submission, $venue_term_id );
			$result     = $this->invoke( 'executeDirect', $submission, null, '' );

			$this->assertIsArray( $result );
			$this->assertSame( 965, $result['job_id'] );

			return end( $this->stub_ability->calls );
		}

		private static function ai_message( array $call ): string {
			foreach ( $call['workflow']['steps'] as $step ) {
				if ( 'ai' === $step['step_type'] ) {
					return $step['user_message'];
				}
			}
			return '';
		}

		/** The #965 scenario: a picked venue reaches the upsert as a term ID. */
		public function test_chosen_venue_is_handed_to_upsert_by_term_id(): void {
			$call = $this->submit_with_venue( $this->submission(), self::VENUE_ID );

			$this->assertSame( self::VENUE_ID, $call['initial_data']['venue_term_id'] );
			$this->assertSame( self::VENUE_ID, $call['initial_data']['submission']['venue_term_id'] );
		}

		public function test_chosen_venue_replaces_typed_name_and_fills_empty_city(): void {
			$call = $this->submit_with_venue( $this->submission(), self::VENUE_ID );

			$submission = $call['initial_data']['submission'];
			$this->assertSame( 'Recovery Room Tavern', $submission['venue_name'] );
			$this->assertSame( 'Charleston', $submission['event_city'] );

			$message = self::ai_message( $call );
			$this->assertStringContainsString( 'Venue: Recovery Room Tavern', $message );
			$this->assertStringNotContainsString( 'Recoery', $message );
		}

		public function test_chosen_venue_keeps_a_city_the_submitter_typed(): void {
			$call = $this->submit_with_venue( $this->submission( array( 'event_city' => 'Charleston, SC' ) ), self::VENUE_ID );

			$this->assertSame( 'Charleston, SC', $call['initial_data']['submission']['event_city'] );
		}

		/** A brand-new venue typed as free text still submits, by name. */
		public function test_free_text_venue_submits_by_name_without_term_id(): void {
			$call = $this->submit_with_venue( $this->submission( array( 'venue_name' => 'Brand New Room' ) ), 0 );

			$this->assertArrayNotHasKey( 'venue_term_id', $call['initial_data'] );
			$this->assertSame( 0, $call['initial_data']['submission']['venue_term_id'] );
			$this->assertSame( 'Brand New Room', $call['initial_data']['submission']['venue_name'] );
			$this->assertStringContainsString( 'Venue: Brand New Room', self::ai_message( $call ) );
		}

		public function test_unknown_venue_term_id_falls_back_to_typed_name(): void {
			$call = $this->submit_with_venue( $this->submission(), 999999 );

			$this->assertArrayNotHasKey( 'venue_term_id', $call['initial_data'] );
			$this->assertSame( 'Recoery Room Tavern', $call['initial_data']['submission']['venue_name'] );
		}

		public function test_term_from_another_taxonomy_is_not_accepted_as_a_venue(): void {
			$call = $this->submit_with_venue( $this->submission(), 777 );

			$this->assertArrayNotHasKey( 'venue_term_id', $call['initial_data'] );
			$this->assertSame( 'Recoery Room Tavern', $call['initial_data']['submission']['venue_name'] );
		}

		/** The form time is the show start, not doors (data-machine-events#922). */
		public function test_ai_message_labels_form_time_as_show_start(): void {
			$call = $this->submit_with_venue( $this->submission(), 0 );

			$this->assertStringContainsString( 'Show time (start): 21:00', self::ai_message( $call ) );
		}

		public function test_flyer_handler_config_gets_the_canonical_venue_name(): void {
			$submission = $this->invoke( 'applyChosenVenue', $this->submission(), self::VENUE_ID );
			$workflow   = $this->invoke( 'buildWorkflow', $submission, array( 'stored_path' => '/dm-files/flyer.png' ) );

			$this->assertSame( 'event_import', $workflow['steps'][0]['step_type'] );
			$this->assertSame( 'Recovery Room Tavern', $workflow['steps'][0]['handler_configs']['event_flyer']['venue_name'] );
		}
	}
}
