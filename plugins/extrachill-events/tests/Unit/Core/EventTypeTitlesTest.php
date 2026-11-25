<?php
/**
 * Format-aware archive titles (#1816).
 *
 * Covers title resolution for a non-music format (comedy), a music format
 * (concert), and a location archive filtered by `?event_type=`.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Test-local WP doubles.

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $id, $key, $single = false ) {
		return $GLOBALS['ett_term_meta'][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $key, $default_value = '' ) {
		return $GLOBALS['ett_query_vars'][ $key ] ?? $default_value;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		return $GLOBALS['ett_terms'][ $taxonomy ][ $value ] ?? false;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/event-type-vocabulary.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/event-type-titles.php';

/** Verifies event_type archive and filtered-location title resolution. */
final class EventTypeTitlesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ett_term_meta']  = array(
			11 => array( '_schema_type' => 'ComedyEvent' ),
			12 => array( '_schema_type' => 'MusicEvent' ),
		);
		$GLOBALS['ett_query_vars'] = array();
		$GLOBALS['ett_terms']      = array(
			'event_type' => array(
				'comedy'  => $this->term( 11, 'Comedy', 'comedy' ),
				'concert' => $this->term( 12, 'Concert', 'concert' ),
				'karaoke' => $this->term( 0, 'Karaoke', 'karaoke' ),
			),
		);
		$_GET = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ett_term_meta'], $GLOBALS['ett_query_vars'], $GLOBALS['ett_terms'] );
		$_GET = array();
		parent::tearDown();
	}

	private function term( int $id, string $name, string $slug ): object {
		return (object) array(
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => $slug,
			'taxonomy' => 'event_type',
		);
	}

	public function test_comedy_archive_is_titled_comedy_shows(): void {
		$profile = extrachill_events_event_type_term_title_profile( $GLOBALS['ett_terms']['event_type']['comedy'] );

		$this->assertFalse( $profile['music'] );
		$this->assertSame( 'Comedy Shows', $profile['archive_title'] );
		$this->assertStringNotContainsString( 'Live Music', $profile['archive_title'] );
	}

	public function test_concert_archive_keeps_live_music_wording(): void {
		$profile = extrachill_events_event_type_term_title_profile( $GLOBALS['ett_terms']['event_type']['concert'] );

		$this->assertTrue( $profile['music'] );
		$this->assertSame( 'Concert Live Music Calendar', $profile['archive_title'] );
	}

	public function test_schema_type_falls_back_to_vocabulary_when_meta_missing(): void {
		$this->assertSame( 'MusicEvent', extrachill_events_event_type_schema_type( 0, 'dj-set' ) );
		$this->assertSame( 'Event', extrachill_events_event_type_schema_type( 0, 'karaoke' ) );

		$karaoke = extrachill_events_event_type_term_title_profile( $GLOBALS['ett_terms']['event_type']['karaoke'] );
		$this->assertSame( 'Karaoke Events', $karaoke['archive_title'] );
	}

	public function test_non_music_formats_never_say_live_music(): void {
		foreach ( extrachill_events_event_type_vocabulary( array() ) as $entry ) {
			$profile = extrachill_events_event_type_title_profile( $entry['name'], $entry['slug'], $entry['schema_type'] );
			if ( in_array( $entry['slug'], array( 'concert', 'dj-set', 'dance-party' ), true ) ) {
				$this->assertTrue( $profile['music'], $entry['slug'] );
				continue;
			}
			$this->assertFalse( $profile['music'], $entry['slug'] );
			$this->assertStringNotContainsString( 'Live Music', $profile['archive_title'], $entry['slug'] );
			$this->assertStringNotContainsString( 'Live Music', $profile['location_noun'], $entry['slug'] );
		}
	}

	public function test_location_filtered_to_comedy_is_titled_comedy_in_city(): void {
		$GLOBALS['ett_query_vars']['event_type'] = 'comedy';

		$filter = extrachill_events_get_single_event_type_filter();
		$this->assertSame( 'Comedy in Charleston', extrachill_events_location_archive_title( 'Charleston', $filter ) );
	}

	public function test_location_filter_reads_get_param_fallback(): void {
		$_GET['event_type'] = 'comedy';

		$filter = extrachill_events_get_single_event_type_filter();
		$this->assertSame( 'Comedy in Charleston', extrachill_events_location_archive_title( 'Charleston', $filter ) );
	}

	public function test_unfiltered_and_music_filtered_location_keep_live_music(): void {
		$this->assertNull( extrachill_events_get_single_event_type_filter() );
		$this->assertSame( 'Live Music in Charleston', extrachill_events_location_archive_title( 'Charleston', null ) );

		$GLOBALS['ett_query_vars']['event_type'] = 'concert';
		$this->assertSame( 'Live Music in Charleston', extrachill_events_location_archive_title( 'Charleston', extrachill_events_get_single_event_type_filter() ) );
	}

	public function test_multi_format_filter_keeps_live_music(): void {
		$GLOBALS['ett_query_vars']['event_type'] = 'comedy,karaoke';

		$this->assertNull( extrachill_events_get_single_event_type_filter() );
	}
}
