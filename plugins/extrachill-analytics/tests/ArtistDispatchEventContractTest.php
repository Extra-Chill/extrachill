<?php
/**
 * Tests for the canonical Artist Dispatch analytics event contract.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/event-types.php';

/**
 * Verify canonical values, grouping, uniqueness, and payload documentation.
 */
final class ArtistDispatchEventContractTest extends TestCase {
	/**
	 * Persisted lifecycle constants retain their exact canonical values.
	 */
	public function test_persisted_event_values_are_exact(): void {
		$this->assertSame( 'artist_dispatch_access_requested', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED );
		$this->assertSame( 'artist_dispatch_access_approved', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_APPROVED );
		$this->assertSame( 'artist_dispatch_access_rejected', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REJECTED );
		$this->assertSame( 'artist_dispatch_access_revoked', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REVOKED );
		$this->assertSame( 'artist_dispatch_draft_created', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED );
		$this->assertSame( 'artist_dispatch_submitted', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED );
		$this->assertSame( 'artist_dispatch_published', EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED );
	}

	/**
	 * The grouped set contains every persisted event once in lifecycle order.
	 */
	public function test_group_contains_unique_lifecycle_events(): void {
		$expected = array(
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_APPROVED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REJECTED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REVOKED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED,
			EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED,
		);

		$this->assertSame( $expected, EC_ANALYTICS_ARTIST_DISPATCH_EVENTS );
		$this->assertSame( EC_ANALYTICS_ARTIST_DISPATCH_EVENTS, array_values( array_unique( EC_ANALYTICS_ARTIST_DISPATCH_EVENTS ) ) );
	}

	/**
	 * No canonical event constant aliases another canonical event value.
	 */
	public function test_all_canonical_event_values_are_unique(): void {
		$event_constants = array_filter(
			get_defined_constants( true )['user'],
			static function ( $name ) {
				return 0 === strpos( $name, 'EC_ANALYTICS_EVENT_' );
			},
			ARRAY_FILTER_USE_KEY
		);

		$this->assertSame( count( $event_constants ), count( array_unique( $event_constants ) ) );
	}

	/**
	 * Source documentation keeps payloads bounded and discovery events external.
	 */
	public function test_payload_and_discovery_contract_is_documented(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/event-types.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		foreach ( array( '`user_id`', '`request_id`', '`eligibility_cohort`', '`post_id`', '`submitter_user_id`', '`artist_id`' ) as $allowed_field ) {
			$this->assertStringContainsString( $allowed_field, $source );
		}

		foreach ( array( 'names', 'emails', 'proposal or application text', 'article title or', 'content', 'internal decision notes', 'rights acknowledgements', 'free-form', 'disclosure text' ) as $prohibited_data ) {
			$this->assertStringContainsString( $prohibited_data, $source );
		}

		foreach ( array( 'artist_dispatch_writer_cta_clicked', 'artist_dispatch_pathway_viewed', 'artist_dispatch_editor_opened', 'artist_dispatch_resumed' ) as $discovery_event ) {
			$this->assertStringContainsString( $discovery_event, $source );
			$this->assertNotContains( $discovery_event, EC_ANALYTICS_ARTIST_DISPATCH_EVENTS );
		}

		$this->assertStringContainsString( 'fixed, emitter-owned enum', $source );
		$this->assertStringContainsString( 'optional `request_id`', $source );
		$this->assertStringContainsString( 'canonical lowercase UUID v4 string', $source );
		$this->assertStringContainsString( '36 ASCII characters', $source );
		$this->assertStringContainsString( '`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`', $source );
		$this->assertStringContainsString( '`request_id` when unavailable', $source );
		$this->assertStringNotContainsString( 'positive integer `request_id`', $source );
		$this->assertStringContainsString( 'existing GA/GTM', $source );
		$this->assertStringContainsString( 'MUST NOT be persisted', $source );
	}
}
