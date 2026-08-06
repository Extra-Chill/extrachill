<?php

use PHPUnit\Framework\TestCase;

final class CreateArtistOptionalAnalyticsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 1,
			'blog_stack'      => array(),
			'current_user_id' => 7,
			'blogs'           => array(
				4 => array(
					'posts'     => array(),
					'post_meta' => array(),
				),
			),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creation_succeeds_without_analytics(): void {
		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'No Analytics Band' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['id'] );
		$this->assertSame( 'No Analytics Band', $result['name'] );
		$this->assertSame( array( 7 ), $GLOBALS['ec_test']['blogs'][4]['post_meta'][1]['_artist_member_ids'] );
		$this->assertSame( array( 1 ), $GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids'] );
		$this->assertArrayNotHasKey( 'funnel_events', $GLOBALS['ec_test'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creation_emits_event_when_analytics_is_available(): void {
		define( 'EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED', 'artist_profile_created' );

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Analytics Band' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['id'] );
		$this->assertSame(
			array(
				array(
					'artist_profile_created',
					array(
						'user_id'   => 7,
						'artist_id' => 1,
					),
				),
			),
			$GLOBALS['ec_test']['funnel_events']
		);
	}
}
