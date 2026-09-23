<?php
/**
 * Tests for exact link-page analytics date windows.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/** Verify validation, SQL bounds, compatibility, and response metadata. */
final class LinkPageAnalyticsDateRangeTest extends Extrachill_Analytics_TestCase {
	/**
	 * Ensure the link-page daily tables exist and seed deterministic rows.
	 */
	public function set_up(): void {
		parent::set_up();

		extrachill_analytics_link_page_create_table();

		$today     = current_time( 'Y-m-d' );
		$two_ago   = gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) );
		global $wpdb;
		$views  = extrachill_analytics_link_page_views_table();
		$clicks = extrachill_analytics_link_page_clicks_table();
		$wpdb->insert( $views, array( 'link_page_id' => 42, 'stat_date' => $two_ago, 'view_count' => 2 ), array( '%d', '%s', '%d' ) );
		$wpdb->insert( $views, array( 'link_page_id' => 42, 'stat_date' => $today, 'view_count' => 5 ), array( '%d', '%s', '%d' ) );
		$wpdb->insert( $clicks, array( 'link_page_id' => 42, 'stat_date' => $two_ago, 'link_url' => 'https://example.com', 'link_text' => 'Example', 'click_count' => 1 ), array( '%d', '%s', '%s', '%s', '%d' ) );
		$wpdb->insert( $clicks, array( 'link_page_id' => 42, 'stat_date' => $today, 'link_url' => 'https://example.com', 'link_text' => 'Example', 'click_count' => 3 ), array( '%d', '%s', '%s', '%s', '%d' ) );
	}

	/**
	 * Drop the seeded rows after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . extrachill_analytics_link_page_views_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		$wpdb->query( 'DELETE FROM ' . extrachill_analytics_link_page_clicks_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.

		parent::tear_down();
	}

	/** Exact dates override the numeric range across every query and chart bucket. */
	public function test_exact_window_controls_queries_and_response(): void {
		$today   = current_time( 'Y-m-d' );
		$two_ago = gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) );
		$window  = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => $two_ago,
				'end_date'   => $today,
			),
			90
		);

		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 90, $window );

		$this->assertSame( $two_ago, $result['start_date'] );
		$this->assertSame( $today, $result['end_date'] );
		$this->assertSame( 3, $result['days'] );
		$this->assertSame( array( $two_ago, gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ), $today ), $result['chart_data']['labels'] );
		$this->assertSame( array( 2, 0, 5 ), $result['chart_data']['datasets'][0]['data'] );
		$this->assertSame( array( 1, 0, 3 ), $result['chart_data']['datasets'][1]['data'] );
		$this->assertSame(
			array(
				'total_views'  => 7,
				'total_clicks' => 4,
			),
			$result['summary']
		);
		$this->assertSame( 4, $result['top_links'][0]['clicks'] );
	}

	/** External consumers may pass raw paired dates to the owning provider. */
	public function test_raw_exact_pair_is_validated_by_provider(): void {
		$today   = current_time( 'Y-m-d' );
		$two_ago = gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) );

		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 90, $two_ago, $today );

		$this->assertSame( $two_ago, $result['start_date'] );
		$this->assertSame( $today, $result['end_date'] );
		$this->assertSame( 3, $result['days'] );

		$partial = extrachill_analytics_provide_link_page_analytics( null, 42, 90, $two_ago, '' );
		$this->assertSame( 'invalid_analytics_date_range', $partial->get_error_code() );
	}

	/** Numeric callers retain their inclusive relative site-calendar window. */
	public function test_legacy_numeric_range_remains_supported(): void {
		$today = current_time( 'Y-m-d' );
		$one_ago = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 2 );

		$this->assertSame( $one_ago, $result['start_date'] );
		$this->assertSame( $today, $result['end_date'] );
		$this->assertSame( 2, $result['days'] );
		$this->assertSame( array( $one_ago, $today ), $result['chart_data']['labels'] );
	}

	/** Empty optional dates preserve the numeric relative window. */
	public function test_empty_date_pair_preserves_relative_range(): void {
		$today = current_time( 'Y-m-d' );
		$one_ago = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$result = extrachill_analytics_provide_link_page_analytics( null, 42, 2, '', '' );

		$this->assertSame( $one_ago, $result['start_date'] );
		$this->assertSame( $today, $result['end_date'] );
		$this->assertSame( 2, $result['days'] );
		$this->assertSame( array( $one_ago, $today ), $result['chart_data']['labels'] );
	}

	/** The ability exposes paired dates and uses the shared 90-day validator. */
	public function test_ability_date_contract_uses_shared_validation(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-link-page-analytics.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.

		$this->assertStringContainsString( "'start_date'", $source );
		$this->assertStringContainsString( "'end_date'", $source );
		$this->assertStringContainsString( 'extrachill_analytics_resolve_date_range( $input, 90 )', $source );
		$this->assertStringContainsString( '$date_range, $date_window', $source );

		$partial = extrachill_analytics_resolve_date_range( array( 'start_date' => '2026-01-01' ), 90 );
		$large   = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-04-01',
			),
			90
		);

		$this->assertSame( 'invalid_analytics_date_range', $partial->get_error_code() );
		$this->assertSame( 'analytics_date_range_too_large', $large->get_error_code() );
	}

	/**
	 * The post-type guard resolves through the storage-aware Link Pages
	 * runtime function rather than a hardcoded literal
	 * (Extra-Chill/extrachill-link-pages#34), falling back to the legacy
	 * literal only when that function does not exist yet.
	 */
	public function test_ability_validates_post_type_via_storage_aware_resolver(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-link-page-analytics.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.

		$this->assertStringContainsString(
			"\$link_page_post_type = function_exists( 'ec_link_page_post_type' ) ? ec_link_page_post_type() : 'artist_link_page';",
			$source
		);
		$this->assertStringContainsString( 'get_post_type( $link_page_id ) !== $link_page_post_type', $source );
	}
}
