<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistAnalyticsContractTest extends EC_Artist_Platform_TestCase {
	private $owner_id;
	private $profile_id;
	private $link_page_id;
	private $captured_args;
	private $analytics_result;

	protected function setUp(): void {
		parent::setUp();

		$this->owner_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bound            = $this->create_bound_artist( 'Analytics Artist' );
		$this->profile_id = $bound['profile_id'];

		switch_to_blog( $this->artist_blog_id() );
		$this->link_page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->link_page_id, '_associated_artist_profile_id', $this->profile_id );
		update_post_meta( $this->link_page_id, EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->profile_id );
		update_post_meta( $this->profile_id, '_extrch_link_page_id', $this->link_page_id );
		restore_current_blog();

		$this->create_artist_membership( $this->owner_id, $this->profile_id );
		wp_set_current_user( $this->owner_id );

		$this->analytics_result     = array(
			'summary'    => array(
				'total_views'  => 1,
				'total_clicks' => 0,
			),
			'chart_data' => array(
				'labels'   => array(),
				'datasets' => array(),
			),
			'top_links'  => array(),
		);
		$this->captured_args        = new stdClass();
		$this->captured_args->calls = array();
		$state                      = $this->captured_args;
		$result                     = $this->analytics_result;
		$this->ec_inject_filter(
			'extrachill_get_link_page_analytics',
			static function ( $value, $link_page_id, $date_range, $start_date, $end_date ) use ( $state, $result ) {
				$state->calls[] = array( $link_page_id, $date_range, $start_date, $end_date );
				return $result;
			},
			100
		);
	}

	public function test_ability_schema_preserves_legacy_range_and_adds_exact_dates(): void {
		$properties = wp_get_ability( 'extrachill/artist-get-analytics' )
			->get_input_schema()['properties'];

		$this->assertSame( 90, $properties['date_range']['maximum'] );
		$this->assertSame( '^\\d{4}-\\d{2}-\\d{2}$', $properties['start_date']['pattern'] );
		$this->assertSame( '^\\d{4}-\\d{2}-\\d{2}$', $properties['end_date']['pattern'] );
	}

	public function test_legacy_date_range_is_clamped_and_forwarded(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = extrachill_artist_platform_ability_artist_get_analytics(
				array(
					'id'         => $this->profile_id,
					'date_range' => 120,
				)
			);
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $this->analytics_result, $result );
		$this->assertSame(
			array( $this->link_page_id, 90, '', '' ),
			$this->captured_args->calls[0]
		);
	}

	public function test_exact_dates_are_forwarded_with_the_legacy_fallback(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			extrachill_artist_platform_ability_artist_get_analytics(
				array(
					'id'         => $this->profile_id,
					'date_range' => 7,
					'start_date' => '2026-06-01',
					'end_date'   => '2026-06-30',
				)
			);
		} finally {
			restore_current_blog();
		}

		$this->assertSame(
			array( $this->link_page_id, 7, '2026-06-01', '2026-06-30' ),
			$this->captured_args->calls[0]
		);
	}

	public function test_analytics_assets_use_shared_script_and_style_handles(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$render = file_get_contents( dirname( __DIR__ ) . '/src/blocks/artist-analytics/render.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$webpack = file_get_contents( dirname( __DIR__ ) . '/webpack.config.js' );

		$this->assertStringContainsString( "'extrachill-analytics-date-range'", $render );
		$this->assertStringContainsString( "wp_enqueue_style( 'extrachill-analytics-date-range' )", $render );
		$this->assertStringNotContainsString( 'flatpickr', strtolower( $webpack ) );
	}
}
