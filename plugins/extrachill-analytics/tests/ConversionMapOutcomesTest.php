<?php
/**
 * Tests for conversion-map concrete outcome attribution.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Protect outcome deduplication, attribution boundaries, and coverage semantics.
 *
 * Most coverage drives the pure conversion helpers directly with fixture row
 * arrays. The source-attribution tests run against real posts through
 * url_to_postid(), and the bounded-reader test inserts real event rows.
 */
final class ConversionMapOutcomesTest extends Extrachill_Analytics_TestCase {
	/**
	 * Serve extrachill.com permalinks and postname permalinks for attribution.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter(
			'home_url',
			static function ( $url, $path = '' ) {
				return 'https://extrachill.com' . $path;
			},
			10,
			2
		);
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Create one published article for source attribution.
	 *
	 * @param string $post_name Post slug.
	 * @param int    $author_id Post author.
	 * @return int Post ID.
	 */
	private function article( string $post_name, int $author_id = 0 ): int {
		$author = $author_id > 0 ? $author_id : self::factory()->user->create();
		return self::factory()->post->create(
			array(
				'post_name'   => $post_name,
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_author' => $author,
			)
		);
	}
	/**
	 * The outcome lens preserves concrete canonical lifecycle event names.
	 */
	public function test_outcome_types_include_proven_lifecycle_completions(): void {
		$this->assertSame(
			array(
				'newsletter_signup',
				'user_registration',
				'onboarding_completed',
				'artist_profile_first_publish',
			),
			extrachill_analytics_conversion_outcome_types()
		);
	}

	/**
	 * Registration deduplication uses the created user ID stored in event_data.
	 */
	public function test_registration_deduplicates_by_payload_user_before_visitor(): void {
		$key = extrachill_analytics_conversion_outcome_dedupe_key(
			array(
				'id'         => 91,
				'user_id'    => 0,
				'visitor_id' => 'visitor-a',
				'event_data' => array( 'user_id' => 647 ),
			)
		);

		$this->assertSame( 'user:647', $key );
	}

	/**
	 * Identity-free outcomes remain distinct observable events.
	 */
	public function test_identity_free_outcomes_deduplicate_by_event(): void {
		$this->assertSame(
			'event:92',
			extrachill_analytics_conversion_outcome_dedupe_key(
				array(
					'id'         => 92,
					'event_data' => array(),
				)
			)
		);
	}

	/**
	 * Outcome trust uses user identity before the canonical bot stamp.
	 */
	public function test_outcome_trust_classifies_server_browser_bot_and_unknown_rows(): void {
		$this->assertSame(
			'authenticated_server',
			extrachill_analytics_conversion_outcome_trust_class(
				array(
					'event_data' => array(
						'is_bot' => true,
						'user_id' => 23,
					),
				)
			)
		);
		$this->assertSame( 'confirmed_bot', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => array( 'is_bot' => true ) ) ) );
		$this->assertSame( 'visitor_identified_browser', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => array( 'is_bot' => false ), 'visitor_id' => 'visitor-a' ) ) );
		$this->assertSame( 'unclassified', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => array( 'is_bot' => false ) ) ) );
	}

	/**
	 * Bot scanner rows are excluded while identified outcomes remain reportable.
	 */
	public function test_outcome_coverage_separates_trusted_bot_and_unclassified_rows(): void {
		$type   = 'newsletter_signup';
		$result = $this->run_outcome_pages(
			array(
				array(
					$this->outcome_row( 1, $type, 0, '', 1000, array( 'is_bot' => true ) ),
					$this->outcome_row( 2, $type, 0, '', 1100, array( 'is_bot' => true ) ),
					$this->outcome_row( 3, $type, 23, '', 1200, array( 'is_bot' => true ) ),
					$this->outcome_row( 4, $type, 0, 'visitor-a', 1300, array( 'is_bot' => false ) ),
					$this->outcome_row( 5, $type, 0, '', 1400, array( 'is_bot' => false ) ),
				),
			),
			array(),
			array( $type )
		);
		$coverage = extrachill_analytics_conversion_finalize_outcome_coverage( $result['coverage'][ $type ] );

		$this->assertSame( 5, $coverage['stored_events'] );
		$this->assertSame( 2, $coverage['confirmed_bot_events_excluded'] );
		$this->assertSame( 3, $coverage['deduplicated_outcomes'] );
		$this->assertSame( 2, $coverage['trusted_outcomes'] );
		$this->assertSame( 1, $coverage['authenticated_server_outcomes'] );
		$this->assertSame( 1, $coverage['visitor_identified_browser_outcomes'] );
		$this->assertSame( 1, $coverage['unclassified_outcomes'] );
		$this->assertSame( 'partial', $coverage['trust_coverage_status'] );
	}

	/**
	 * Journey attribution preserves before-entry, same-session, and later-session.
	 */
	public function test_outcome_journey_stage_uses_explicit_session_boundary(): void {
		$journey = array(
			'entry_ts'             => 1000,
			'same_session_through' => 2800,
		);

		$this->assertNull( extrachill_analytics_conversion_outcome_journey_stage( 999, $journey ) );
		$this->assertSame( 'same_session', extrachill_analytics_conversion_outcome_journey_stage( 2800, $journey ) );
		$this->assertSame( 'later_session', extrachill_analytics_conversion_outcome_journey_stage( 2801, $journey ) );
	}

	/**
	 * Direct attribution accepts published main-site articles and rejects other hosts.
	 */
	public function test_direct_source_requires_published_entry_blog_article(): void {
		$author_id = self::factory()->user->create();
		$post_id   = $this->article( 'article', $author_id );

		$this->assertSame( $post_id, extrachill_analytics_conversion_source_article_id( 'https://extrachill.com/article/', 1 ) );
		$this->assertSame( $post_id, extrachill_analytics_conversion_source_article_id( 'https://extrachill.com/article/', 1, $author_id ) );
		$this->assertSame( 0, extrachill_analytics_conversion_source_article_id( 'https://extrachill.com/article/', 1, $author_id + 1 ) );
		$this->assertSame( 0, extrachill_analytics_conversion_source_article_id( 'https://events.extrachill.com/article/', 1 ) );
	}

	/**
	 * No visitor instrumentation produces null journey counts, not measured zero.
	 */
	public function test_missing_identity_coverage_is_not_reported_as_zero(): void {
		$coverage                             = extrachill_analytics_conversion_outcome_zero_coverage();
		$coverage['deduplicated_outcomes']    = 3;
		$coverage['with_source_url']          = 3;
		$coverage['direct_source_attributed'] = 3;
		$coverage['missing_visitor_identity'] = 3;
		$finalized                            = extrachill_analytics_conversion_finalize_outcome_coverage( $coverage );
		$coverage_by_type                     = array(
			'newsletter_signup' => $finalized,
			'user_registration' => $finalized,
		);
		$row                                  = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket(),
			$coverage_by_type
		);

		$this->assertSame( 'not_instrumented', $row['newsletter_signup']['visitor_journey']['coverage_status'] );
		$this->assertNull( $row['newsletter_signup']['visitor_journey']['same_session_count'] );
		$this->assertNull( $row['newsletter_signup']['visitor_journey']['later_session_count'] );
		$this->assertSame( 0, $row['newsletter_signup']['direct_source']['count'] );
	}

	/**
	 * Partial coverage preserves measured counts while disclosing the gap.
	 */
	public function test_partial_coverage_preserves_only_observed_counts(): void {
		$coverage                             = extrachill_analytics_conversion_outcome_zero_coverage();
		$coverage['deduplicated_outcomes']    = 4;
		$coverage['with_source_url']          = 2;
		$coverage['direct_source_attributed'] = 1;
		$coverage['with_visitor_identity']    = 3;
		$coverage['missing_source_url']       = 2;
		$coverage['missing_visitor_identity'] = 1;
		$finalized                            = extrachill_analytics_conversion_finalize_outcome_coverage( $coverage );

		$this->assertSame( 'partial', $finalized['direct_source_status'] );
		$this->assertSame( 'partial', $finalized['visitor_journey_status'] );
		$this->assertSame( 2, $finalized['missing_source_url'] );
		$this->assertSame( 1, $finalized['missing_visitor_identity'] );
	}

	/**
	 * Visitor-only and identified duplicates stitch to one person.
	 */
	public function test_mixed_identity_duplicates_stitch_and_later_row_can_attribute(): void {
		$type     = 'artist_profile_first_publish';
		$pages    = array(
			array(
				$this->outcome_row( 1, $type, 0, 'visitor-a', 900 ),
				$this->outcome_row( 2, $type, 11, 'visitor-a', 1200 ),
			),
		);
		$journeys = array(
			'visitor-a' => array(
				'post_id'              => 0,
				'entry_ts'             => 1000,
				'same_session_through' => 2000,
			),
		);
		$result   = $this->run_outcome_pages( $pages, $journeys, array( $type ) );

		$this->assertSame( array( 'visitor-a' => 11 ), $result['visitor_to_user'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['deduplicated_outcomes'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['duplicate_events'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['visitor_journey_attributed'] );
		$this->assertSame( 1, $result['overall'][ $type ]['same_session'] );
	}

	/**
	 * A visitor observed with multiple users remains an independent identity.
	 */
	public function test_ambiguous_visitor_mapping_does_not_stitch(): void {
		$type   = 'artist_profile_first_publish';
		$pages  = array(
			array(
				$this->outcome_row( 1, $type, 0, 'visitor-a', 1200 ),
				$this->outcome_row( 2, $type, 11, 'visitor-a', 1300 ),
				$this->outcome_row( 3, $type, 12, 'visitor-a', 1400 ),
			),
		);
		$result = $this->run_outcome_pages( $pages, array(), array( $type ) );

		$this->assertSame( array(), $result['visitor_to_user'] );
		$this->assertSame( 3, $result['coverage'][ $type ]['deduplicated_outcomes'] );
		$this->assertSame( 0, $result['coverage'][ $type ]['duplicate_events'] );
	}

	/**
	 * Equal timestamps use row-ID order across pages and retain later attribution.
	 */
	public function test_cross_page_equal_timestamp_rows_are_deterministic(): void {
		$type     = 'artist_profile_first_publish';
		$pages    = array(
			array( $this->outcome_row( 499, $type, 21, 'visitor-before', 1000 ) ),
			array( $this->outcome_row( 500, $type, 21, 'visitor-after', 1000 ) ),
		);
		$journeys = array(
			'visitor-before' => array(
				'post_id'              => 0,
				'entry_ts'             => 1100,
				'same_session_through' => 2000,
			),
			'visitor-after'  => array(
				'post_id'              => 0,
				'entry_ts'             => 900,
				'same_session_through' => 2000,
			),
		);
		$result   = $this->run_outcome_pages( $pages, $journeys, array( $type ) );

		$this->assertSame( 1, $result['coverage'][ $type ]['deduplicated_outcomes'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['duplicate_events'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['visitor_journey_attributed'] );
		$this->assertSame( 0, $result['coverage'][ $type ]['outcome_before_entry'] );
		$this->assertSame( 1, $result['overall'][ $type ]['same_session'] );
	}

	/**
	 * The bounded reader advances equal-timestamp pages with the event ID cursor.
	 */
	public function test_outcome_reader_keyset_pages_equal_timestamps_by_id(): void {
		$type    = 'artist_profile_first_publish';
		$created = '2026-01-01 00:00:00';
		$ids     = array();
		for ( $i = 0; $i < 501; ++$i ) {
			global $wpdb;
			$wpdb->insert(
				extrachill_analytics_events_table(),
				array(
					'event_type' => $type,
					'event_data' => wp_json_encode( array( 'user_id' => $i + 1 ) ),
					'source_url' => '',
					'visitor_id' => 'visitor-' . ( $i + 1 ),
					'created_at' => $created,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		$consumed = array();

		$captured = $this->capture_queries();
		extrachill_analytics_conversion_each_outcome_page(
			extrachill_analytics_events_table(),
			array( $type ),
			'2025-12-01 00:00:00',
			'2026-01-02 00:00:00',
			static function ( $page ) use ( &$consumed ) {
				$consumed = array_merge( $consumed, array_map( static fn( $row ) => (int) $row->id, $page ) );
			}
		);

		$this->assertCount( 501, $consumed );
		$this->assertSame( max( $ids ), end( $consumed ) );
		$this->assertSame( $ids, $consumed, 'Equal timestamps page in ascending ID order.' );
		$this->assertCount( 2, $captured->queries );
		$this->assertStringContainsString( 'ORDER BY created_at ASC, id ASC', $captured->queries[0] );
		$this->assertStringContainsString( 'created_at <', $captured->queries[0] );
		$this->assertStringContainsString( 'id >', $captured->queries[1] );
	}

	/**
	 * Identity-free rows remain distinct outcomes through both passes.
	 */
	public function test_identity_free_fixture_rows_remain_distinct(): void {
		$type   = 'onboarding_completed';
		$pages  = array(
			array(
				$this->outcome_row( 1, $type, 0, '', 1200 ),
				$this->outcome_row( 2, $type, 0, '', 1300 ),
			),
		);
		$result = $this->run_outcome_pages( $pages, array(), array( $type ) );

		$this->assertSame( 2, $result['coverage'][ $type ]['deduplicated_outcomes'] );
		$this->assertSame( 0, $result['coverage'][ $type ]['duplicate_events'] );
		$this->assertSame( 2, $result['coverage'][ $type ]['missing_visitor_identity'] );
	}

	/**
	 * An outcome with no stored instrumentation is not projected as measured zero.
	 */
	public function test_absent_lifecycle_instrumentation_projects_null_counts(): void {
		$type        = 'onboarding_completed';
		$coverage    = extrachill_analytics_conversion_finalize_outcome_coverage( extrachill_analytics_conversion_outcome_zero_coverage(), true );
		$outcome_row = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket( array( $type ) ),
			array( $type => $coverage )
		);

		$this->assertSame( 'not_observed', $coverage['instrumentation_status'] );
		$this->assertSame( 'not_instrumented', $outcome_row[ $type ]['direct_source']['coverage_status'] );
		$this->assertNull( $outcome_row[ $type ]['direct_source']['count'] );
		$this->assertNull( $outcome_row[ $type ]['visitor_journey']['same_session_count'] );
		$this->assertNull( $outcome_row[ $type ]['visitor_journey']['later_session_count'] );
	}

	/**
	 * Existing outcome zero semantics remain backward compatible.
	 */
	public function test_absent_established_outcome_remains_measured_zero(): void {
		$type        = 'newsletter_signup';
		$coverage    = extrachill_analytics_conversion_finalize_outcome_coverage( extrachill_analytics_conversion_outcome_zero_coverage() );
		$outcome_row = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket( array( $type ) ),
			array( $type => $coverage )
		);

		$this->assertSame( 'measured', $outcome_row[ $type ]['direct_source']['coverage_status'] );
		$this->assertSame( 0, $outcome_row[ $type ]['direct_source']['count'] );
		$this->assertSame( 0, $outcome_row[ $type ]['visitor_journey']['same_session_count'] );
		$this->assertSame( 0, $outcome_row[ $type ]['visitor_journey']['later_session_count'] );
	}

	/**
	 * Author reports exclude unrelated outcomes while preserving scoped journeys.
	 */
	public function test_author_scope_filters_outcome_population_before_coverage(): void {
		$type            = 'newsletter_signup';
		$author_id       = self::factory()->user->create();
		$matching_post   = $this->article( 'mine', $author_id );
		$other_post      = $this->article( 'other' );

		$matching_source              = $this->outcome_row( 101, $type, 1, '', 1200 );
		$matching_source->source_url  = 'https://extrachill.com/mine/';
		$unrelated_source             = $this->outcome_row( 102, $type, 2, '', 1200 );
		$unrelated_source->source_url = 'https://extrachill.com/other/';
		$journey_source               = $this->outcome_row( 103, $type, 3, 'visitor-mine', 1400 );
		$journey_source->source_url   = 'https://extrachill.com/other/';

		$result = $this->run_outcome_pages(
			array( array( $matching_source, $unrelated_source, $journey_source ) ),
			array(
				'visitor-mine' => array(
					'post_id'              => $matching_post,
					'entry_ts'             => 1000,
					'same_session_through' => 2000,
				),
			),
			array( $type ),
			$author_id
		);

		$this->assertSame( 2, $result['coverage'][ $type ]['stored_events'] );
		$this->assertSame( 1, $result['coverage'][ $type ]['source_outside_scope'] );
		$this->assertSame( 1, $result['overall'][ $type ]['direct_source'] );
		$this->assertSame( 1, $result['overall'][ $type ]['same_session'] );
	}

	/**
	 * Build a stored outcome fixture.
	 *
	 * @param int    $id         Event row ID.
	 * @param string $event_type Concrete outcome event name.
	 * @param int    $user_id    Payload user identity.
	 * @param string $visitor_id Anonymous visitor identity.
	 * @param int    $timestamp  Event timestamp.
	 * @param array  $event_data Additional event payload.
	 * @return object Outcome row.
	 */
	private function outcome_row( int $id, string $event_type, int $user_id, string $visitor_id, int $timestamp, array $event_data = array() ): object {
		if ( $user_id > 0 ) {
			$event_data['user_id'] = $user_id;
		}

		return (object) array(
			'id'         => $id,
			'event_type' => $event_type,
			'event_data' => wp_json_encode( $event_data ),
			'source_url' => '',
			'user_id'    => 0,
			'visitor_id' => $visitor_id,
			'ts'         => $timestamp,
		);
	}

	/**
	 * Run production's two-pass outcome logic over explicit keyset pages.
	 *
	 * @param array $pages                Ordered pages of outcome rows.
	 * @param array $journeys_by_visitor Eligible journeys keyed by visitor.
	 * @param array $types                Concrete outcome types.
	 * @param int   $author_id            Optional primary post author ID.
	 * @return array Aggregated fixture result.
	 */
	private function run_outcome_pages( array $pages, array $journeys_by_visitor, array $types, int $author_id = 0 ): array {
		$visitor_users = array();
		foreach ( $pages as $page ) {
			extrachill_analytics_conversion_observe_outcome_identities( $page, $visitor_users );
		}
		$visitor_to_user = extrachill_analytics_conversion_resolve_outcome_identities( $visitor_users );
		$records         = array();
		$coverage        = array_fill_keys( $types, extrachill_analytics_conversion_outcome_zero_coverage() );
		foreach ( $pages as $page ) {
			extrachill_analytics_conversion_collect_outcome_rows( $page, 1, $journeys_by_visitor, $visitor_to_user, $records, $coverage, $author_id );
		}

		$overall     = extrachill_analytics_conversion_outcome_zero_bucket( $types );
		$by_article  = array();
		$by_category = array();
		extrachill_analytics_conversion_apply_outcome_records( $records, $overall, $by_article, $by_category, $coverage );

		return array(
			'visitor_to_user' => $visitor_to_user,
			'overall'         => $overall,
			'coverage'        => $coverage,
		);
	}

}
