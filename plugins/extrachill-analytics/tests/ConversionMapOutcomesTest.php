<?php
/**
 * Tests for conversion-map newsletter and registration outcome attribution.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php';

/**
 * Protect outcome deduplication, attribution boundaries, and coverage semantics.
 */
final class ConversionMapOutcomesTest extends TestCase {
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
		$post              = new WP_Post();
		$post->ID          = 173;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		$GLOBALS['extrachill_analytics_classifier_posts']  = array( 173 => $post );
		$GLOBALS['extrachill_analytics_test_url_post_ids'] = array(
			'https://extrachill.com/article/' => 173,
		);
		$GLOBALS['extrachill_analytics_test_home_urls']    = array( 1 => 'https://extrachill.com' );

		$this->assertSame( 173, extrachill_analytics_conversion_source_article_id( 'https://extrachill.com/article/', 1 ) );
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
}
