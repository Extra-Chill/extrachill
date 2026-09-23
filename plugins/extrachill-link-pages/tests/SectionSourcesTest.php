<?php
/**
 * Typed sections, push-based section sources, and the managed-section write
 * operations (extrachill-link-pages#37).
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class SectionSourcesTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Sections Page', 'post_name' => 'sections-page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish' );
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	private function registerTestSource( $resolver = null ): void {
		$default_links = array( array( 'link_text' => 'Show 1', 'link_url' => 'https://example.com/show-1' ) );
		$this->assertTrue(
			ec_register_link_page_section_source(
				'upcoming_events',
				array(
					'label'         => 'Upcoming Events',
					'config_schema' => array(
						'venue_id' => array( 'type' => 'int' ),
					),
					'resolve'       => function ( $context ) use ( $resolver, $default_links ) {
						$GLOBALS['ec_test']['resolve_calls'][] = $context;
						if ( is_callable( $resolver ) ) {
							return $resolver( $context );
						}
						if ( ! empty( $GLOBALS['ec_test']['force_empty_resolve'] ) ) {
							return array();
						}
						return $default_links;
					},
				)
			)
		);
	}

	public function test_registry_rejects_invalid_ids_and_manual_reserved_word(): void {
		$this->assertSame( 'invalid_link_page_section_source', $this->errorCode( ec_register_link_page_section_source( 'manual', array( 'label' => 'x', 'resolve' => '__return_true' ) ) ) );
		$this->assertSame( 'invalid_link_page_section_source', $this->errorCode( ec_register_link_page_section_source( 'Bad Id', array( 'label' => 'x', 'resolve' => '__return_true' ) ) ) );
		$this->assertSame( 'invalid_link_page_section_source', $this->errorCode( ec_register_link_page_section_source( 'ok', array( 'label' => '', 'resolve' => '__return_true' ) ) ) );
		$this->assertTrue( ec_register_link_page_section_source( 'ok', array( 'label' => 'OK', 'resolve' => '__return_true' ) ) );
		$this->assertSame( 'duplicate_link_page_section_source', $this->errorCode( ec_register_link_page_section_source( 'ok', array( 'label' => 'OK', 'resolve' => '__return_true' ) ) ) );
	}

	public function test_manual_only_sections_keep_the_exact_historical_shape(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => 'new-1', 'section_title' => 'Listen', 'links' => array( array( 'id' => '', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ) ) ),
				),
			)
		);
		$this->assertIsArray( $saved );
		$section = $saved['links'][0];
		$this->assertSame( array( 'id', 'section_title', 'links' ), array_keys( $section ) );
	}

	public function test_new_managed_section_starts_empty_and_a_test_source_refreshes_it_through_the_save_path(): void {
		$this->registerTestSource();
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array(
						'id'            => 'new-1',
						'section_title' => 'Upcoming Shows',
						'type'          => 'upcoming_events',
						'source_config' => array( 'venue_id' => 30 ),
						'links'         => array(),
					),
				),
			)
		);
		$this->assertIsArray( $saved );
		$section = $saved['links'][0];
		$this->assertSame( 'upcoming_events', $section['type'] );
		$this->assertSame( array( 'venue_id' => 30 ), $section['source_config'] );
		$this->assertSame( array(), $section['links'] );
		$this->assertNull( $section['refreshed_at'] );

		$section_id = $section['id'];
		$refreshed  = ec_refresh_link_page_section( 40, $section_id );
		$this->assertIsArray( $refreshed );
		$refreshed_section = $refreshed['links'][0];
		$this->assertSame( 'Show 1', $refreshed_section['links'][0]['link_text'] );
		$this->assertNotEmpty( $refreshed_section['links'][0]['id'] );
		$this->assertNotNull( $refreshed_section['refreshed_at'] );

		// Write-time context reaches the source with the exact contract.
		$context = $GLOBALS['ec_test']['resolve_calls'][0];
		$this->assertSame( 40, $context['link_page_id'] );
		$this->assertSame( 'post:4:profile:20', $context['owner_reference'] );
		$this->assertSame( 'post:4:profile:20', $context['owner']['reference'] );
		$this->assertSame( $section_id, $context['section']['id'] );
	}

	public function test_manual_edits_to_a_managed_sections_links_are_rejected_but_retitle_and_reorder_are_allowed(): void {
		$this->registerTestSource();
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => 'new-1', 'section_title' => 'Manual', 'links' => array( array( 'id' => '', 'link_text' => 'Manual link', 'link_url' => 'https://example.com/manual' ) ) ),
					array( 'id' => 'new-2', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => array() ),
				),
			)
		);
		$this->assertIsArray( $saved );
		$managed_id = $saved['links'][1]['id'];
		ec_refresh_link_page_section( 40, $managed_id );
		$after_refresh = ec_read_link_page_persistence( 40 );
		$stored_links  = $after_refresh['links'][1]['links'];
		$this->assertNotEmpty( $stored_links );

		// Hand-editing the managed section's links is refused...
		$rejected = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					$after_refresh['links'][0],
					array(
						'id'            => $managed_id,
						'section_title' => 'Auto',
						'type'          => 'upcoming_events',
						'links'         => array( array( 'id' => 'hand-edited', 'link_text' => 'Injected', 'link_url' => 'https://example.com/injected' ) ),
					),
				),
			)
		);
		$this->assertSame( 'link_page_managed_section_links_readonly', $this->errorCode( $rejected ) );
		$this->assertSame( $stored_links, ec_read_link_page_persistence( 40 )['links'][1]['links'] );

		// ...but retitling, reordering, and removing sections are allowed,
		// as long as the managed section's links are echoed back verbatim.
		$reordered = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array(
						'id'            => $managed_id,
						'section_title' => 'Renamed Auto Section',
						'type'          => 'upcoming_events',
						'links'         => $stored_links,
					),
					$after_refresh['links'][0],
				),
			)
		);
		$this->assertIsArray( $reordered );
		$this->assertSame( 'Renamed Auto Section', $reordered['links'][0]['section_title'] );
		$this->assertSame( $stored_links, $reordered['links'][0]['links'] );

		// Removing the manual section entirely (leaving only the managed
		// one) is a legitimate edit.
		$removed = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array(
						'id'            => $managed_id,
						'section_title' => 'Renamed Auto Section',
						'type'          => 'upcoming_events',
						'links'         => $stored_links,
					),
				),
				'allow_empty' => true,
			)
		);
		$this->assertIsArray( $removed );
		$this->assertCount( 1, $removed['links'] );
	}

	public function test_a_new_managed_section_cannot_start_with_hand_typed_links(): void {
		$this->registerTestSource();
		$result = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => 'new-1', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => array( array( 'id' => '', 'link_text' => 'Hand typed', 'link_url' => 'https://example.com/hand' ) ) ),
				),
			)
		);
		$this->assertSame( 'link_page_managed_section_links_readonly', $this->errorCode( $result ) );
	}

	public function test_managed_section_content_is_excluded_from_the_manual_only_silent_empty_count(): void {
		// A page whose only content lives in a managed section is, by
		// definition, not "populated" for the manual-links guard: an
		// `upcoming_events` source legitimately resolving to zero shows
		// must never be blocked as a silent-empty.
		$populated_managed_only = array(
			array( 'id' => '40-section-1', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'source_config' => array(), 'links' => array( array( 'id' => '40-link-1', 'link_text' => 'Show', 'link_url' => 'https://example.com/show' ) ), 'refreshed_at' => '2026-01-01 00:00:00' ),
		);
		$this->assertTrue( ec_refuse_link_page_silent_empty( $populated_managed_only, array(), false ) );

		// A populated manual section stays protected exactly as before.
		$populated_manual = array(
			array( 'id' => '40-section-2', 'section_title' => 'Manual', 'links' => array( array( 'id' => '40-link-2', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ) ) ),
		);
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_refuse_link_page_silent_empty( $populated_manual, array(), false ) ) );
	}

	public function test_a_populated_manual_section_stays_protected_when_a_managed_section_is_also_present(): void {
		$this->registerTestSource();
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => 'new-1', 'section_title' => 'Manual', 'links' => array( array( 'id' => '', 'link_text' => 'Manual link', 'link_url' => 'https://example.com/manual' ) ) ),
					array( 'id' => 'new-2', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => array() ),
				),
			)
		);
		$managed_id = $saved['links'][1]['id'];
		$after_refresh = ec_refresh_link_page_section( 40, $managed_id );
		$managed_links = $after_refresh['links'][1]['links'];
		$this->assertNotEmpty( $managed_links );

		// Emptying only the manual section, while leaving the managed
		// section's stored links untouched, is still a silent empty of the
		// hand-typed content and stays refused without explicit intent.
		$refused = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => $managed_id, 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => $managed_links ),
				),
			)
		);
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( $refused ) );
	}

	public function test_a_failed_resolve_keeps_the_last_stored_links_and_records_the_error(): void {
		$calls = 0;
		$this->assertTrue(
			ec_register_link_page_section_source(
				'flaky_source',
				array(
					'label'   => 'Flaky',
					'resolve' => static function () use ( &$calls ) {
						++$calls;
						if ( 1 === $calls ) {
							return array( array( 'link_text' => 'First Success', 'link_url' => 'https://example.com/first' ) );
						}
						return new WP_Error( 'flaky_source_unavailable', 'The flaky source is temporarily unavailable.' );
					},
				)
			)
		);
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'links' => array(
					array( 'id' => 'new-1', 'section_title' => 'Flaky', 'type' => 'flaky_source', 'links' => array() ),
				),
			)
		);
		$this->assertIsArray( $saved );
		$section_id = $saved['links'][0]['id'];
		$first      = ec_refresh_link_page_section( 40, $section_id );
		$this->assertIsArray( $first );
		$this->assertSame( 'First Success', $first['links'][0]['links'][0]['link_text'] );

		$GLOBALS['ec_test']['fired_actions'] = array();
		$result = ec_refresh_link_page_section( 40, $section_id );
		$this->assertSame( 'flaky_source_unavailable', $this->errorCode( $result ) );
		$this->assertSame(
			'First Success',
			ec_read_link_page_persistence( 40 )['links'][0]['links'][0]['link_text'],
			'A failed resolve must never blank the section beyond what was already stored.'
		);
		$failures = array_values( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_link_page_section_source_refresh_failed' === $action[0]; } ) );
		$this->assertCount( 1, $failures, 'The failure must be recorded through the refresh-failed hook.' );
	}

	public function test_unregistered_section_type_renders_its_stored_links_without_requiring_the_source(): void {
		// A section can be typed for a source this process never loaded —
		// rendering must never depend on the source being registered.
		$stored = array(
			array(
				'id'            => '40-section-1',
				'section_title' => 'Auto',
				'type'          => 'not_loaded_here',
				'source_config' => array(),
				'links'         => array( array( 'id' => '40-link-1', 'link_text' => 'Cached Show', 'link_url' => 'https://example.com/cached' ) ),
				'refreshed_at'  => '2026-01-01 00:00:00',
			),
		);
		update_post_meta( 40, '_link_page_links', $stored );
		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( 'Cached Show', $data['link_sections'][0]['links'][0]['link_text'] );
		$html = ec_render_link_page_section( $data['link_sections'][0], 40, false );
		$this->assertStringContainsString( 'Cached Show', $html );

		$refresh_attempt = ec_refresh_link_page_section( 40, '40-section-1' );
		$this->assertSame( 'link_page_section_source_missing', $this->errorCode( $refresh_attempt ) );
	}

	public function test_refresh_requires_a_managed_section_and_rejects_unknown_ids(): void {
		ec_save_link_page_persistence( 40, array( 'links' => array( array( 'id' => 'new-1', 'section_title' => 'Manual', 'links' => array( array( 'id' => '', 'link_text' => 'X', 'link_url' => 'https://example.com/x' ) ) ) ) ) );
		$manual_id = ec_read_link_page_persistence( 40 )['links'][0]['id'];
		$this->assertSame( 'link_page_section_not_managed', $this->errorCode( ec_refresh_link_page_section( 40, $manual_id ) ) );
		$this->assertSame( 'link_page_section_not_found', $this->errorCode( ec_refresh_link_page_section( 40, 'missing-section' ) ) );
	}

	public function test_section_source_config_schema_validates_and_rejects_unknown_fields(): void {
		$this->registerTestSource();
		$this->assertSame(
			array( 'venue_id' => 30 ),
			ec_sanitize_link_page_section_source_config( 'upcoming_events', array( 'venue_id' => '30' ) )
		);
		$this->assertSame(
			'invalid_link_page_section_source_config',
			$this->errorCode( ec_sanitize_link_page_section_source_config( 'upcoming_events', array( 'unknown_field' => 'x' ) ) )
		);
		$this->assertSame(
			'invalid_link_page_section_source_config',
			$this->errorCode( ec_sanitize_link_page_section_source_config( 'upcoming_events', array( 'venue_id' => 'not-a-number' ) ) )
		);
	}

	public function test_bulk_refresh_targets_one_owner_or_every_page_for_a_source(): void {
		$this->registerTestSource();
		ec_save_link_page_persistence( 40, array( 'links' => array( array( 'id' => 'new-1', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => array() ) ) ) );

		$GLOBALS['ec_test']['blogs'][4]['posts'][41] = (object) array( 'ID' => 41, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Second', 'post_name' => 'second-page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][21] = (object) array( 'ID' => 21, 'post_type' => 'profile', 'post_status' => 'publish' );
		ec_assign_link_page_owner( 41, 'post:4:profile:21' );
		ec_save_link_page_persistence( 41, array( 'links' => array( array( 'id' => 'new-1', 'section_title' => 'Auto', 'type' => 'upcoming_events', 'links' => array() ) ) ) );

		$scoped = ec_refresh_link_page_sections_for_source( 'upcoming_events', 'post:4:profile:20' );
		$this->assertSame( array( 'processed' => 1, 'refreshed' => 1, 'errors' => array() ), $scoped );
		$this->assertNotEmpty( ec_read_link_page_persistence( 40 )['links'][0]['links'] );
		$this->assertSame( array(), ec_read_link_page_persistence( 41 )['links'][0]['links'] );

		$bulk = ec_refresh_link_page_sections_for_source( 'upcoming_events' );
		$this->assertSame( 2, $bulk['processed'] );
		$this->assertSame( 2, $bulk['refreshed'] );
		$this->assertNotEmpty( ec_read_link_page_persistence( 41 )['links'][0]['links'] );

		$this->assertSame( 'invalid_link_page_section_source', $this->errorCode( ec_refresh_link_page_sections_for_source( 'Not Valid' ) ) );
	}
}
