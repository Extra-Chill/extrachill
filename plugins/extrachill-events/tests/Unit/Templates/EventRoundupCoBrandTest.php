<?php
/**
 * Event roundup co-brand (partner logo) tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Templates\EventRoundupTemplate;

require_once dirname( __DIR__, 2 ) . '/fixtures/data-machine-template-interface.php';
require_once dirname( __DIR__, 3 ) . '/inc/Templates/EventRoundupTemplate.php';

/**
 * The partner logo is cover/closing decoration only. When no partner logo is
 * given, the template must plan and size slides exactly as it does today, so
 * these tests pin the geometry contract and prove packing never reads the
 * partner input.
 */
final class EventRoundupCoBrandTest extends BookingTestCase {

	/** Read the template source once. */
	private function source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Templates/EventRoundupTemplate.php' );
	}

	/** Extract one method body from the template source. */
	private function method_body( string $name ): string {
		$source = $this->source();
		$start  = strpos( $source, "function {$name}(" );
		$this->assertNotFalse( $start, "Method {$name} exists." );
		$end = strpos( $source, "\n\t}", $start );
		return substr( $source, $start, $end - $start );
	}

	/** The co-brand input is an explicit optional template field, not venue-wired. */
	public function test_partner_logo_path_is_an_optional_template_field(): void {
		$fields = ( new EventRoundupTemplate() )->get_fields();

		$this->assertArrayHasKey( 'partner_logo_path', $fields );
		$this->assertFalse( $fields['partner_logo_path']['required'] );
		$this->assertSame( 'string', $fields['partner_logo_path']['type'] );
	}

	/** Slide packing and geometry never read the partner logo. */
	public function test_slide_packing_never_reads_the_partner_logo(): void {
		foreach ( array( 'distribute_days_to_slides', 'plan_slides', 'content_bottom', 'balance_slides', 'render_slide' ) as $method ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'partner',
				$this->method_body( $method ),
				"{$method}() must not depend on the partner logo, or co-branded and plain renders would pack differently."
			);
		}
	}

	/** The partner mark lands only on the cover and closing slides. */
	public function test_partner_mark_is_drawn_only_on_cover_and_closing_slides(): void {
		$source = $this->source();

		$this->assertSame( 2, substr_count( $source, '$this->draw_partner_mark(' ) );
		$this->assertStringContainsString( 'draw_partner_mark', $this->method_body( 'render_cover_slide' ) );
		$this->assertStringContainsString( 'draw_partner_mark', $this->method_body( 'render_closing_slide' ) );
	}

	/** Without a partner logo the plan and dimensions match today's output. */
	public function test_plan_and_dimensions_are_unchanged_without_a_partner_logo(): void {
		// Geometry contract pinned from EventRoundupSlidePlanTest: the
		// content bottom is a pure function of height, CTA, and brand mark.
		$this->assertSame( 1290, EventRoundupTemplate::content_bottom( 1350, '' ) );
		$this->assertSame( 1350 - 60 - 72 - 24, EventRoundupTemplate::content_bottom( 1350, '', 72 ) );

		// And the packing itself is a pure function of day groups + heights:
		// the partner logo is not even an argument, so a co-branded render
		// plans identically to a plain one.
		$day = array(
			'date_obj' => new DateTime( '2026-10-02' ),
			'events'   => array(
				array(
					'post'       => (object) array( 'post_title' => 'Show A' ),
					'event_data' => array( 'startTime' => '19:00:00' ),
				),
				array(
					'post'       => (object) array( 'post_title' => 'Show B' ),
					'event_data' => array( 'startTime' => '21:00:00' ),
				),
			),
		);

		$plan = EventRoundupTemplate::plan_slides( array( '2026-10-02' => $day ), 60, 1000, 100, 60, static fn(): int => 70 );

		$this->assertCount( 1, $plan );
		$this->assertCount( 2, $plan[0][0]['events'] );
	}
}
