<?php
/**
 * Regression tests for analytics summary event detail (issue #177).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-analytics-summary.php';

/**
 * Verify event detail is exposed only for explicitly filtered summaries.
 */
final class GetAnalyticsSummaryTest extends TestCase {
	/**
	 * Install fresh database and detail fixtures for each test.
	 */
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Queries executed by the report.
			 *
			 * @var string[]
			 */
			public $queries = array();

			/**
			 * Return the query unchanged.
			 *
			 * @param string $query SQL query.
			 * @param mixed  ...$args Prepared values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				unset( $args );
				return $query;
			}

			/**
			 * Return the summary row fixture.
			 *
			 * @param string $query SQL query.
			 * @return array<object>
			 */
			public function get_results( $query ) {
				$this->queries[] = $query;
				return array(
					(object) array(
						'event_type' => 'newsletter_signup',
						'count'      => '3',
					),
				);
			}
		};

		$GLOBALS['extrachill_analytics_event_stats_fixture'] = array(
			'total'      => 3,
			'by_date'    => array(
				(object) array(
					'date'  => '2026-07-16',
					'count' => '3',
				),
			),
			'by_source'  => array(
				(object) array(
					'source_url' => 'https://extrachill.com/newsletter/',
					'count'      => '2',
				),
			),
			'by_context' => array(
				(object) array(
					'context' => 'footer',
					'count'   => '2',
				),
			),
		);
	}

	/**
	 * An explicit event type exposes typed rows from the existing aggregation.
	 */
	public function test_explicit_event_type_exposes_typed_detail_rows(): void {
		$summary = extrachill_analytics_ability_get_summary(
			array(
				'days'       => 28,
				'event_type' => 'newsletter_signup',
			)
		);

		$this->assertSame( 3, $summary['total'] );
		$this->assertSame(
			array(
				array(
					'date'  => '2026-07-16',
					'count' => 3,
				),
			),
			$summary['by_date']
		);
		$this->assertSame(
			array(
				array(
					'source_url' => 'https://extrachill.com/newsletter/',
					'count'      => 2,
				),
			),
			$summary['by_source']
		);
		$this->assertSame(
			array(
				array(
					'context' => 'footer',
					'count'   => 2,
				),
			),
			$summary['by_context']
		);
	}

	/**
	 * The all-event contract remains compact and does not run detail queries.
	 */
	public function test_all_event_summary_contract_is_unchanged(): void {
		$summary = extrachill_analytics_ability_get_summary( array( 'days' => 28 ) );

		$this->assertArrayNotHasKey( 'by_date', $summary );
		$this->assertArrayNotHasKey( 'by_source', $summary );
		$this->assertArrayNotHasKey( 'by_context', $summary );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
	}

	/**
	 * Canonical onboarding grants require no parallel summary reader.
	 */
	public function test_onboarding_grant_is_readable_by_existing_summary(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Capture prepared values and return the fixture query.
			 *
			 * @param string $query Query string.
			 * @param mixed  ...$args Prepared values.
			 * @return string Query string.
			 */
			public function prepare( $query, ...$args ) {
				$this->args = $args;
				return $query;
			}

			/**
			 * Return the canonical grant summary fixture.
			 *
			 * @return array<object> Summary rows.
			 */
			public function get_results() {
				return array(
					(object) array(
						'event_type' => 'artist_access_granted',
						'count'      => '2',
					),
				);
			}
		};

		$summary = extrachill_analytics_ability_get_summary(
			array(
				'days'       => 28,
				'event_type' => EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED,
			)
		);

		$this->assertSame( 'artist_access_granted', $summary['event_types'][0]['event_type'] );
		$this->assertSame( 2, $summary['event_types'][0]['count'] );
		$this->assertContains( 'artist_access_granted', $GLOBALS['wpdb']->args[0] );
	}

	/**
	 * Source and context rankings are deterministic and bounded in SQL.
	 */
	public function test_source_and_context_queries_are_stable_and_bounded(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/events.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'ORDER BY count DESC, source_url ASC', $source );
		$this->assertStringContainsString( 'ORDER BY count DESC, context ASC', $source );
		$this->assertSame( 2, substr_count( $source, 'LIMIT 20' ) );
	}
}
