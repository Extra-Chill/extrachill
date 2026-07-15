<?php
/**
 * Tests for conversion-map entry and destination scope helpers.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php';

/**
 * Protect the intentionally narrow, currently collected conversion-map scope.
 */
final class ConversionMapScopeTest extends TestCase {
	/**
	 * Non-post singular objects cannot inflate the editorial-entry denominator.
	 */
	public function test_only_published_posts_are_editorial_entries(): void {
		$page                   = new WP_Post();
		$page->ID               = 10;
		$page->post_type        = 'page';
		$page->post_status      = 'publish';
		$draft                  = new WP_Post();
		$draft->ID              = 11;
		$draft->post_type       = 'post';
		$draft->post_status     = 'draft';
		$published              = new WP_Post();
		$published->ID          = 12;
		$published->post_type   = 'post';
		$published->post_status = 'publish';

		$GLOBALS['extrachill_analytics_classifier_posts'] = array(
			10 => $page,
			11 => $draft,
			12 => $published,
		);

		$this->assertFalse(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => 10,
				),
				1
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => 11,
				),
				1
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => 12,
				),
				1
			)
		);
	}

	/**
	 * Homepage and archive events have no post ID, so they are explicitly outside
	 * the current post-view collector's destination scope.
	 */
	public function test_homepage_and_archive_destinations_are_not_counted(): void {
		$platform = array(
			7 => 'events',
			2 => 'community',
			4 => 'artist',
		);

		$this->assertFalse(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 7,
					'post_id' => 0,
				),
				$platform
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 2,
					'post_id' => 0,
				),
				$platform
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 4,
					'post_id' => 99,
				),
				$platform
			)
		);
	}

	/**
	 * Pre-window sessions and late entries do not enter the mature denominator.
	 */
	public function test_entry_session_requires_full_return_observation_period(): void {
		$published                                        = new WP_Post();
		$published->ID                                    = 12;
		$published->post_type                             = 'post';
		$published->post_status                           = 'publish';
		$GLOBALS['extrachill_analytics_classifier_posts'] = array( 12 => $published );

		$since  = '2026-07-01 00:00:00';
		$cutoff = '2026-07-08 00:00:00';
		$this->assertFalse(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => 12,
					'ts'      => strtotime( '2026-06-30 23:59:59' ),
				),
				1,
				$since,
				$cutoff
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => 12,
					'ts'      => strtotime( '2026-07-05 12:00:00' ),
				),
				1,
				$since,
				$cutoff
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => 12,
					'ts'      => strtotime( '2026-07-08 00:00:01' ),
				),
				1,
				$since,
				$cutoff
			)
		);
	}

	/**
	 * The contract documents buffered lower-boundary sessionization and mature
	 * journey denominator semantics instead of implying every entry session.
	 */
	public function test_contract_declares_boundary_and_denominator_semantics(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source as a contract fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php' );

		$this->assertStringContainsString( 'stream_since', $source );
		$this->assertStringContainsString( 'return_observation_days', $source );
		$this->assertStringContainsString( 'first eligible, mature entry journey per visitor', $source );
	}
}
