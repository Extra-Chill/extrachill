<?php
/**
 * One-click approve for event source submissions (#988).
 *
 * Venue rows resolve their city pipeline instead of making the admin type
 * a pipeline ID, and the approve form leads with one Approve button.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

namespace ExtraChillEvents\Tests\Unit\Abilities;

use ExtraChillEvents\Abilities\VenueAddAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueAddAbilities.php';

final class OneClickApproveTest extends TestCase {

	private const ROWS = array(
		array( 'pipeline_id' => 99, 'pipeline_name' => 'Charleston WV Events' ),
		array( 'pipeline_id' => 10, 'pipeline_name' => 'Austin Events' ),
		array( 'pipeline_id' => 3, 'pipeline_name' => 'Charleston Events' ),
		array( 'pipeline_id' => 50, 'pipeline_name' => 'North Charleston Events' ),
	);

	private static function ids( array $rows ): array {
		return array_map( static fn( $row ) => (int) $row['pipeline_id'], $rows );
	}

	public function test_exact_city_pipeline_is_checked_first(): void {
		$ranked = VenueAddAbilities::rankCityPipelineCandidates( self::ROWS, 'Charleston' );

		$this->assertSame( 3, self::ids( $ranked )[0] );
		// State-disambiguated "Charleston WV Events" next, then the rest by ID.
		$this->assertSame( array( 3, 99, 10, 50 ), self::ids( $ranked ) );
	}

	public function test_every_pipeline_stays_a_candidate(): void {
		$ranked = VenueAddAbilities::rankCityPipelineCandidates( self::ROWS, 'Pittsburgh' );

		$this->assertSame( array( 3, 10, 50, 99 ), self::ids( $ranked ) );
	}

	public function test_city_match_is_case_insensitive(): void {
		$ranked = VenueAddAbilities::rankCityPipelineCandidates( self::ROWS, 'austin' );

		$this->assertSame( 10, self::ids( $ranked )[0] );
	}

	public function test_venue_approval_resolves_the_pipeline_when_none_is_given(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source read.

		$resolve  = strpos( $source, 'findCityPipelineForVenue( $fresh_venue_id )' );
		$validate = strpos( $source, "validateCityPipeline( (int) ( \$input['pipeline_id'] ?? 0 ) )" );
		$this->assertNotFalse( $resolve );
		$this->assertNotFalse( $validate );
		$this->assertLessThan( $validate, $resolve, 'The resolved pipeline must still pass validateCityPipeline().' );
	}

	public function test_approve_form_leads_with_one_button_and_hides_overrides(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/admin/ArtistUrlSubmissionsAdmin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source read.

		$button  = strpos( $source, "esc_html_e( 'Approve', 'extrachill-events' )" );
		$details = strpos( $source, "esc_html_e( 'Change details', 'extrachill-events' )" );
		$kind    = strpos( $source, "esc_html_e( 'Source kind:', 'extrachill-events' )" );
		$this->assertNotFalse( $button );
		$this->assertNotFalse( $details );
		$this->assertLessThan( $details, $button, 'Approve comes before Change details.' );
		$this->assertLessThan( $kind, $details, 'Manual fields live inside Change details.' );
		$this->assertStringContainsString( 'findCityPipelineForVenue( $entity_term_id )', $source );
	}
}
