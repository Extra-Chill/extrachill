<?php

use PHPUnit\Framework\TestCase;

final class ArtistAnalyticsContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'current_user_id' => 7,
			'managed_artists' => array( 7 => array( 42 ) ),
			'analytics_result' => array(
				'summary'    => array( 'total_views' => 1, 'total_clicks' => 0 ),
				'chart_data' => array( 'labels' => array(), 'datasets' => array() ),
				'top_links'  => array(),
			),
			'blogs' => array(
				4 => array(
					'posts' => array(
						42  => (object) array(
							'ID'          => 42,
							'post_type'   => 'artist_profile',
							'post_status' => 'publish',
						),
						142 => (object) array(
							'ID'          => 142,
							'post_type'   => 'artist_link_page',
							'post_status' => 'publish',
						),
					),
					'post_meta' => array(
						142 => array( '_associated_artist_profile_id' => 42 ),
					),
				),
			),
		);

		extrachill_artist_platform_register_abilities();
	}

	public function test_ability_schema_preserves_legacy_range_and_adds_exact_dates(): void {
		$properties = wp_get_ability( 'extrachill/artist-get-analytics' )
			->get_input_schema()['properties'];

		$this->assertSame( 90, $properties['date_range']['maximum'] );
		$this->assertSame( '^\\d{4}-\\d{2}-\\d{2}$', $properties['start_date']['pattern'] );
		$this->assertSame( '^\\d{4}-\\d{2}-\\d{2}$', $properties['end_date']['pattern'] );
	}

	public function test_legacy_date_range_is_clamped_and_forwarded(): void {
		$result = extrachill_artist_platform_ability_artist_get_analytics(
			array( 'id' => 42, 'date_range' => 120 )
		);

		$this->assertSame( $GLOBALS['ec_test']['analytics_result'], $result );
		$this->assertSame(
			array( 142, 90, '', '' ),
			$GLOBALS['ec_test']['analytics_filter_args'][0]
		);
	}

	public function test_exact_dates_are_forwarded_with_the_legacy_fallback(): void {
		extrachill_artist_platform_ability_artist_get_analytics(
			array(
				'id'         => 42,
				'date_range' => 7,
				'start_date' => '2026-06-01',
				'end_date'   => '2026-06-30',
			)
		);

		$this->assertSame(
			array( 142, 7, '2026-06-01', '2026-06-30' ),
			$GLOBALS['ec_test']['analytics_filter_args'][0]
		);
	}

	public function test_analytics_assets_use_shared_script_and_style_handles(): void {
		$render  = file_get_contents( dirname( __DIR__ ) . '/src/blocks/artist-analytics/render.php' );
		$webpack = file_get_contents( dirname( __DIR__ ) . '/webpack.config.js' );

		$this->assertStringContainsString( "'extrachill-analytics-date-range'", $render );
		$this->assertStringContainsString( "wp_enqueue_style( 'extrachill-analytics-date-range' )", $render );
		$this->assertStringNotContainsString( 'flatpickr', strtolower( $webpack ) );
	}
}
