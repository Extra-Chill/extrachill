<?php

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $found_posts = 0;
		public $posts       = array();

		public function __construct( $args ) {
			$GLOBALS['ec_test']['wp_queries'][] = $args;
			$result = array_shift( $GLOBALS['ec_test']['wp_query_results'] );
			$this->found_posts = $result['found_posts'] ?? 0;
			$this->posts       = $result['posts'] ?? array();
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/abilities/handlers/get-artist-platform-stats.php';

final class ArtistPlatformStatsContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'wp_query_results' => array(),
		);
		extrachill_artist_platform_register_abilities();
		$this->registerAbility(
			'extrachill/artists-list',
			static function () {
				return array( 'total' => 3 );
			}
		);
	}

	private function registerAbility( string $name, callable $execute, ?callable $permission = null ): void {
		$GLOBALS['ec_test']['abilities'][ $name ] = new EcTestRegisteredAbility(
			array(
				'execute_callback'    => $execute,
				'permission_callback' => $permission ?? static function () {
					return true;
				},
			)
		);
	}

	private function setQueryResults( array $link_page_ids ): void {
		$GLOBALS['ec_test']['wp_query_results'] = array(
			array(
				'found_posts' => count( $link_page_ids ),
				'posts'       => $link_page_ids,
			),
			array( 'found_posts' => 1 ),
		);
	}

	public function test_provider_data_maps_to_active_link_page_count(): void {
		$this->setQueryResults( array( 10, 20 ) );
		$this->registerAbility(
			'extrachill/get-link-page-analytics',
			static function ( $input ) {
				$GLOBALS['ec_test']['analytics_inputs'][] = $input;
				return array(
					'summary' => array(
						'total_views'  => 10 === $input['link_page_id'] ? 4 : 0,
						'total_clicks' => 0,
					),
				);
			}
		);

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertSame( 1, $result['active_link_pages_recent'] );
		$this->assertSame( 'available', $result['link_page_analytics_status'] );
		$this->assertNull( $result['link_page_analytics_error'] );
		$this->assertSame(
			array(
				array( 'link_page_id' => 10, 'date_range' => 28 ),
				array( 'link_page_id' => 20, 'date_range' => 28 ),
			),
			$GLOBALS['ec_test']['analytics_inputs']
		);
	}

	public function test_available_provider_distinguishes_genuine_no_data(): void {
		$this->setQueryResults( array( 10 ) );
		$this->registerAbility(
			'extrachill/get-link-page-analytics',
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
		$this->setQueryResults( array( 10 ) );
		unset( $GLOBALS['ec_test']['abilities']['extrachill/get-link-page-analytics'] );

		$result = extrachill_artist_platform_ability_get_artist_platform_stats( array( 'days' => 28 ) );

		$this->assertNull( $result['active_link_pages_recent'] );
		$this->assertSame( 'unavailable', $result['link_page_analytics_status'] );
		$this->assertNull( $result['link_page_analytics_error'] );
	}

	public function test_provider_error_is_not_reported_as_zero_activity(): void {
		$this->setQueryResults( array( 10 ) );
		$this->registerAbility(
			'extrachill/get-link-page-analytics',
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
		$this->setQueryResults( array( 10 ) );
		$this->registerAbility(
			'extrachill/get-link-page-analytics',
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
		$GLOBALS['ec_test']['current_blog_id']                 = 1;
		$GLOBALS['ec_test']['current_user_id']                 = 7;
		$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
		$this->setQueryResults( array( 10 ) );
		$this->registerAbility(
			'extrachill/get-link-page-analytics',
			static function () {
				return array();
			},
			static function () {
				return false;
			}
		);

		$result = wp_get_ability( 'extrachill/get-artist-platform-stats' )->execute( array( 'days' => 28 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
		$this->assertSame( 1, get_current_blog_id() );
	}
}
