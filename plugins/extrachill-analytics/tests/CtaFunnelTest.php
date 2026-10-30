<?php
/**
 * Tests for extrachill/get-cta-funnel (Extra-Chill/extrachill-analytics#293).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify the per-CTA funnel: pageviews, distinct clicking visitors, and
 * later conversions by the same visitors, against fixture event rows.
 */
final class CtaFunnelTest extends Extrachill_Analytics_TestCase {

	/**
	 * Insert one raw event row into the real events table.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Event dimensions.
	 * @param string $source_url Stored source_url (already a bare path, matching production writes).
	 * @param string $visitor_id Visitor UUID, or ''.
	 * @param string $created_at UTC datetime string.
	 * @return int Row ID.
	 */
	private function event( $event_type, $event_data, $source_url, $visitor_id, $created_at ) {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $event_type,
				'event_data' => wp_json_encode( $event_data ),
				'source_url' => $source_url,
				'visitor_id' => '' !== $visitor_id ? $visitor_id : null,
				'blog_id'    => 1,
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * A path or route is required to scope the report.
	 */
	public function test_missing_scope_is_rejected(): void {
		$result = extrachill_analytics_ability_get_cta_funnel( array( 'days' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_cta_funnel_scope', $result->get_error_code() );
	}

	/**
	 * An unknown route family is rejected rather than silently matching nothing.
	 */
	public function test_invalid_route_is_rejected(): void {
		$result = extrachill_analytics_ability_get_cta_funnel(
			array(
				'route' => 'not-a-real-route',
				'days'  => 0,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_cta_funnel_route', $result->get_error_code() );
	}

	/**
	 * The full funnel: pageviews, per-CTA distinct visitors, and a later
	 * conversion by one of the clicking visitors — the exact /power shape
	 * this issue exists to make visible.
	 */
	public function test_full_funnel_counts_pageviews_clicks_and_later_conversions(): void {
		// Three pageviews on the scoped page (one bot-stamped, excluded).
		$this->event( EC_ANALYTICS_EVENT_PAGEVIEW, array( 'route_family' => 'singular', 'is_bot' => false ), '/power/', '', '2026-01-01 10:00:00' );
		$this->event( EC_ANALYTICS_EVENT_PAGEVIEW, array( 'route_family' => 'singular', 'is_bot' => false ), '/power/', '', '2026-01-01 10:05:00' );
		$this->event( EC_ANALYTICS_EVENT_PAGEVIEW, array( 'route_family' => 'singular', 'is_bot' => true ), '/power/', '', '2026-01-01 10:06:00' );

		$visitor_converts   = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$visitor_never_conv = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

		// Two visitors click "power-join"; $visitor_converts clicks twice
		// (dedup to one visitor, but the raw click count stays 3).
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-join',
				'label'     => 'Join the Scene',
				'dest'      => 'example.org/power/join',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			$visitor_converts,
			'2026-01-01 10:01:00'
		);
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-join',
				'label'     => 'Join the Scene',
				'dest'      => 'example.org/power/join',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			$visitor_converts,
			'2026-01-01 10:02:00'
		);
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-join',
				'label'     => 'Join the Scene',
				'dest'      => 'example.org/power/join',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			$visitor_never_conv,
			'2026-01-01 10:03:00'
		);

		// visitor_converts registers AFTER their click: counts.
		$this->event( EC_ANALYTICS_EVENT_USER_REGISTRATION, array( 'user_id' => 42 ), '/register/', $visitor_converts, '2026-01-01 10:10:00' );
		// A newsletter signup that PREDATES the click must not count.
		$this->event( EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP, array(), '/', $visitor_never_conv, '2025-12-31 09:00:00' );

		$report = extrachill_analytics_ability_get_cta_funnel(
			array(
				'path' => '/power/',
				'days' => 0,
			)
		);

		$this->assertIsArray( $report );
		$this->assertSame( 2, $report['pageviews'], 'Bot-stamped pageview excluded.' );
		$this->assertCount( 1, $report['ctas'] );

		$cta = $report['ctas'][0];
		$this->assertSame( 'power-join', $cta['cta'] );
		$this->assertSame( 'Join the Scene', $cta['label'] );
		$this->assertSame( 'example.org/power/join', $cta['dest'] );
		$this->assertSame( 3, $cta['clicks'], 'Raw click count includes the repeat click.' );
		$this->assertSame( 2, $cta['visitors'], 'Distinct visitors dedupe the repeat click.' );
		$this->assertSame( 1, $cta['conversions'][ EC_ANALYTICS_EVENT_USER_REGISTRATION ] );
		$this->assertSame( 0, $cta['conversions'][ EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP ], 'Conversion before the click does not count.' );
		$this->assertSame( 0, $cta['conversions'][ EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED ] );
		$this->assertSame( 1, $cta['converted_visitors'] );
		$this->assertSame( 0.5, $cta['conversion_rate'] );
	}

	/**
	 * A conversion long after the click still counts even though the gap
	 * between the two is far wider than the click's own reporting window —
	 * the conversion lookup carries no separate date_from bound (see the
	 * ability's docblock).
	 */
	public function test_conversion_far_after_click_still_counts(): void {
		$visitor = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

		// Click lands just inside a 30-day window.
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-join',
				'label'     => 'Join',
				'dest'      => 'example.org/power/join',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			$visitor,
			gmdate( 'Y-m-d H:i:s', strtotime( '-29 days' ) )
		);
		// Registration lands 28 days after the click.
		$this->event(
			EC_ANALYTICS_EVENT_USER_REGISTRATION,
			array( 'user_id' => 7 ),
			'/register/',
			$visitor,
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) )
		);

		$report = extrachill_analytics_ability_get_cta_funnel(
			array(
				'path' => '/power/',
				'days' => 30,
			)
		);

		$this->assertSame( 1, $report['ctas'][0]['visitors'] );
		$this->assertSame( 1, $report['ctas'][0]['conversions'][ EC_ANALYTICS_EVENT_USER_REGISTRATION ] );
		$this->assertSame( 1, $report['ctas'][0]['converted_visitors'] );
	}

	/**
	 * Filtering to a single CTA excludes the others.
	 */
	public function test_cta_filter_scopes_to_one_cta(): void {
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-join',
				'label'     => 'Join',
				'dest'      => 'example.org/power/join',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			'',
			'2026-01-01 10:00:00'
		);
		$this->event(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => 'power-learn-more',
				'label'     => 'Learn More',
				'dest'      => 'example.org/about/',
				'placement' => 'main',
				'route'     => 'singular',
				'is_bot'    => false,
			),
			'/power/',
			'',
			'2026-01-01 10:00:00'
		);

		$report = extrachill_analytics_ability_get_cta_funnel(
			array(
				'path' => '/power/',
				'cta'  => 'power-join',
				'days' => 0,
			)
		);

		$this->assertCount( 1, $report['ctas'] );
		$this->assertSame( 'power-join', $report['ctas'][0]['cta'] );
	}

	/**
	 * No matching clicks yields an explicit empty report, not an error.
	 */
	public function test_no_matching_clicks_returns_empty_ctas(): void {
		$report = extrachill_analytics_ability_get_cta_funnel(
			array(
				'path' => '/nonexistent/',
				'days' => 0,
			)
		);

		$this->assertSame( array(), $report['ctas'] );
		$this->assertSame( 0, $report['pageviews'] );
	}
}
