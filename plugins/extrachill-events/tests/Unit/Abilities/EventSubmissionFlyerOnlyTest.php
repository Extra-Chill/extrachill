<?php
/**
 * Flyer-only submissions (#986).
 *
 * A flyer alone is a valid submission: title and date are only required
 * when no flyer is attached. The flyer step reads what's missing, the AI
 * message says so, and resubmitting the same flyer keeps one identity.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Isolated unit fixture intentionally mixes namespaced test doubles.

namespace {
	if ( ! class_exists( 'WP_Query' ) ) {
		class WP_Query {
			public array $posts = array();

			public function __construct( array $args = array() ) {
				unset( $args );
			}
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {

	use ExtraChillEvents\Abilities\EventSubmissionAbilities;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php';

	final class EventSubmissionFlyerOnlyTest extends TestCase {

		private const FLYER = array(
			'name'     => 'flyer.jpg',
			'tmp_name' => '/tmp/php-flyer',
		);

		private function invoke( string $method, ...$args ) {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$target     = $reflection->getMethod( $method );
			$target->setAccessible( true );

			return $target->invoke( $instance, ...$args );
		}

		private function submission( array $overrides = array() ): array {
			return array_merge(
				array(
					'user_id'       => 137,
					'authenticated' => false,
					'contact_name'  => 'Promoter',
					'contact_email' => '',
					'event_title'   => '',
					'event_date'    => '',
					'event_time'    => '',
					'venue_name'    => '',
					'event_city'    => '',
					'event_lineup'  => '',
					'event_link'    => '',
					'notes'         => '',
				),
				$overrides
			);
		}

		public function test_flyer_alone_is_valid(): void {
			$this->assertNull( EventSubmissionAbilities::validateEventFields( '', '', self::FLYER ) );
		}

		public function test_title_and_date_without_flyer_is_valid(): void {
			$this->assertNull( EventSubmissionAbilities::validateEventFields( 'Show', '2026-10-21', null ) );
		}

		public function test_no_flyer_and_no_title_or_date_is_rejected(): void {
			foreach ( array( array( '', '' ), array( 'Show', '' ), array( '', '2026-10-21' ) ) as $case ) {
				$error = EventSubmissionAbilities::validateEventFields( $case[0], $case[1], null );
				$this->assertInstanceOf( \WP_Error::class, $error );
				$this->assertSame( 'missing_fields', $error->get_error_code() );
			}
		}

		public function test_empty_upload_does_not_count_as_a_flyer(): void {
			$this->assertFalse( EventSubmissionAbilities::hasFlyer( array( 'name' => '', 'tmp_name' => '' ) ) );
			$this->assertFalse( EventSubmissionAbilities::hasFlyer( null ) );
			$this->assertTrue( EventSubmissionAbilities::hasFlyer( self::FLYER ) );
			$this->assertNotNull( EventSubmissionAbilities::validateEventFields( '', '', array( 'tmp_name' => '' ) ) );
		}

		public function test_ability_schema_no_longer_requires_title_and_date(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source read.
			$this->assertStringNotContainsString( "'required'   => array( 'event_title', 'event_date' )", $source );
		}

		public function test_ai_message_asks_to_read_missing_details_from_the_flyer(): void {
			$workflow = $this->invoke( 'buildWorkflow', $this->submission(), array( 'stored_path' => '/dm-files/flyer.png' ) );
			$ai       = $workflow['steps'][1];

			$this->assertSame( 'event_import', $workflow['steps'][0]['step_type'] );
			$this->assertSame( 'ai', $ai['step_type'] );
			$this->assertStringContainsString( 'from the flyer', $ai['user_message'] );
			$this->assertStringNotContainsString( 'Title: ', $ai['user_message'] );
			$this->assertStringNotContainsString( 'Date: ', $ai['user_message'] );
		}

		public function test_ai_message_is_unchanged_when_title_and_date_are_given(): void {
			$workflow = $this->invoke( 'buildWorkflow', $this->submission( array( 'event_title' => 'Show', 'event_date' => '2026-10-21' ) ), null );
			$message  = $workflow['steps'][0]['user_message'];

			$this->assertStringContainsString( "Title: Show\nDate: 2026-10-21\n", $message );
			$this->assertStringNotContainsString( 'from the flyer', $message );
		}

		public function test_flyer_only_identity_is_keyed_by_the_flyer(): void {
			$a = $this->invoke( 'resolveSubmissionItemIdentifier', $this->submission( array( 'flyer_hash' => str_repeat( 'a', 64 ) ) ) );
			$b = $this->invoke( 'resolveSubmissionItemIdentifier', $this->submission( array( 'flyer_hash' => str_repeat( 'a', 64 ) ) ) );
			$c = $this->invoke( 'resolveSubmissionItemIdentifier', $this->submission( array( 'flyer_hash' => str_repeat( 'b', 64 ) ) ) );

			$this->assertSame( 'submission:user:137:flyer:' . str_repeat( 'a', 16 ), $a );
			$this->assertSame( $a, $b );
			$this->assertNotSame( $a, $c );
		}

		public function test_typed_title_and_date_keep_the_existing_identity(): void {
			$id = $this->invoke(
				'resolveSubmissionItemIdentifier',
				$this->submission(
					array(
						'event_title' => 'Show',
						'event_date'  => '2026-10-21',
						'flyer_hash'  => str_repeat( 'a', 64 ),
					)
				)
			);

			$this->assertStringStartsWith( 'submission:user:137:2026-10-21:', $id );
		}

		public function test_flyer_only_emails_say_what_happens(): void {
			$reflection = new ReflectionClass( EventSubmissionAbilities::class );
			$title      = $reflection->getMethod( 'displayTitle' );
			$date       = $reflection->getMethod( 'displayDate' );
			$title->setAccessible( true );
			$date->setAccessible( true );

			$this->assertSame( 'Your flyer submission', $title->invoke( null, $this->submission() ) );
			$this->assertSame( 'Reading it from the flyer', $date->invoke( null, $this->submission() ) );
			$this->assertSame( 'Show', $title->invoke( null, $this->submission( array( 'event_title' => 'Show' ) ) ) );
		}
	}
}
