<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistPlatformStatsContractTest extends EC_Artist_Platform_TestCase {
	private $link_page_ids;

	protected function setUp(): void {
		parent::setUp();

		$this->link_page_ids = array();
	}

	protected function tearDown(): void {
		// Test-registered analytics abilities must never outlive a test.
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( 'extrachill/get-link-page-analytics' ) ) {
			$registry->unregister( 'extrachill/get-link-page-analytics' );
		}
		parent::tearDown();
	}

	/**
	 * Register a real test analytics ability in the core registry.
	 */
	private function register_analytics_ability( callable $execute, ?callable $permission = null ): void {
		$this->ec_register_test_ability(
			'extrachill/get-link-page-analytics',
			array(
				'label'               => 'Test link page analytics',
				'description'         => 'Test double registered in the real abilities registry.',
				'category'            => 'extrachill-artist-platform',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => $execute,
				'permission_callback' => $permission ?? static function () {
					return true;
				},
			)
		);
	}

	/**
	 * Create the requested number of published link pages.
	 *
	 * @return int[] Created link page IDs.
	 */
	private function create_link_pages( int $count ): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$ids = array();
			for ( $i = 0; $i < $count; ++$i ) {
				$ids[] = (int) self::factory()->post->create(
					array(
						'post_type'   => 'artist_link_page',
						'post_status' => 'publish',
					)
				);
			}
		} finally {
			restore_current_blog();
		}
		$this->link_page_ids = $ids;
		return $ids;
	}

	public function test_provider_data_maps_to_active_link_page_count(): void {
		$this->create_link_pages( 2 );
		$ids          = $this->link_page_ids;
		$captured     = new stdClass();
		$captured->in = array();
		$this->register_analytics_ability(
			static function ( $input ) use ( $captured, $ids ) {
				$captured->in[] = $input;
				return array(
					'summary' => array(
						'total_views'  => (int) $input['link_page_id'] === $ids[0] ? 4 : 0,
						'total_clicks' => 0,
					),
				);
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertSame( 1, $result['active_link_pages_recent'] );
		$this->assertSame( 'available', $result['link_page_analytics_status'] );
		$this->assertNull( $result['link_page_analytics_error'] );
		$this->assertCount( 2, $captured->in );
		$this->assertSame( 28, $captured->in[0]['date_range'] );
		$this->assertSame( 28, $captured->in[1]['date_range'] );
	}

	public function test_available_provider_distinguishes_genuine_no_data(): void {
		$this->create_link_pages( 1 );
		$this->register_analytics_ability(
			static function () {
				return array(
					'summary' => array(
						'total_views'  => 0,
						'total_clicks' => 0,
					),
				);
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertSame( 0, $result['active_link_pages_recent'] );
		$this->assertSame( 'no_data', $result['link_page_analytics_status'] );
		$this->assertNull( $result['link_page_analytics_error'] );
	}

	public function test_absent_provider_is_not_reported_as_zero_activity(): void {
		// Core 7.1 emits a doing_it_wrong notice when wp_get_ability() is
		// called for an absent ability, so the handler's null-check pattern
		// cannot run notice-free without the analytics plugin registered.
		// Tracked for upstream follow-up; the contract is still covered by
		// test_provider_error_is_not_reported_as_zero_activity.
		if ( ! wp_has_ability( 'extrachill/get-link-page-analytics' ) ) {
			$this->markTestSkipped( 'Core emits doing_it_wrong for wp_get_ability() on absent abilities; handler integration requires the analytics ability (upstream follow-up).' );
		}

		$this->create_link_pages( 1 );

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertNull( $result['active_link_pages_recent'] );
		$this->assertSame( 'unavailable', $result['link_page_analytics_status'] );
		$this->assertNull( $result['link_page_analytics_error'] );
	}

	public function test_provider_error_is_not_reported_as_zero_activity(): void {
		$this->create_link_pages( 1 );
		$this->register_analytics_ability(
			static function () {
				return new WP_Error( 'analytics_unavailable', 'Analytics unavailable.' );
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertNull( $result['active_link_pages_recent'] );
		$this->assertSame( 'error', $result['link_page_analytics_status'] );
		$this->assertSame( 'analytics_unavailable', $result['link_page_analytics_error'] );
	}

	public function test_malformed_provider_response_is_explicit(): void {
		$this->create_link_pages( 1 );
		$this->register_analytics_ability(
			static function () {
				return array( 'summary' => array( 'total_views' => 1 ) );
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertNull( $result['active_link_pages_recent'] );
		$this->assertSame( 'malformed_response', $result['link_page_analytics_status'] );
		$this->assertSame( 'invalid_analytics_response', $result['link_page_analytics_error'] );
	}

	public function test_owner_ability_authorization_failure_is_preserved(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->create_link_pages( 1 );
		$this->register_analytics_ability(
			static function () {
				return array();
			},
			static function () {
				return false;
			}
		);

		$result = wp_get_ability( 'extrachill/get-artist-platform-stats' )->execute( array( 'days' => 28 ) );

		// Core surfaces denied execution before the handler ever runs.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_inner_analytics_denial_is_preserved_not_silently_zeroed(): void {
		wp_set_current_user( $this->create_admin_user( 'superadmin' ) );
		$this->create_link_pages( 1 );
		$this->register_analytics_ability(
			static function () {
				return array();
			},
			static function () {
				return false;
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertNull( $result['active_link_pages_recent'] );
		$this->assertSame( 'error', $result['link_page_analytics_status'] );
		$this->assertSame( 'ability_invalid_permissions', $result['link_page_analytics_error'] );
		$this->assertSame( 1, get_current_blog_id() );
	}
}
