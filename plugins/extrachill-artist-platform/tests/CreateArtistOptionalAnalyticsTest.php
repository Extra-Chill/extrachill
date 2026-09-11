<?php

require_once __DIR__ . '/support/base-test-case.php';

final class CreateArtistOptionalAnalyticsTest extends EC_Artist_Platform_TestCase {
	private $owner_id;

	protected function setUp(): void {
		parent::setUp();

		$this->owner_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $this->owner_id, 'user_is_artist', '1' );
		wp_set_current_user( $this->owner_id );
	}

	protected function tearDown(): void {
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( 'extrachill/track-analytics-event' ) ) {
			$registry->unregister( 'extrachill/track-analytics-event' );
		}
		parent::tearDown();
	}

	public function test_creation_succeeds_without_analytics(): void {
		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'No Analytics Band' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'No Analytics Band', $result['name'] );
		$this->assertSame( array( (int) $result['id'] ), array_map( 'intval', (array) get_user_meta( $this->owner_id, '_artist_profile_ids', true ) ) );
		$this->assertFalse( wp_has_ability( 'extrachill/track-analytics-event' ) );
	}

	public function test_creation_emits_event_when_analytics_is_available(): void {
		$state         = new stdClass();
		$state->events = array();
		$this->ec_register_test_ability(
			'extrachill/track-analytics-event',
			array(
				'label'               => 'Test analytics event sink',
				'description'         => 'Test double registered in the real abilities registry.',
				'category'            => 'extrachill-artist-platform',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) use ( $state ) {
					$state->events[] = $input;
					return true;
				},
				'permission_callback' => '__return_true',
			)
		);

		if ( ! defined( 'EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED' ) ) {
			define( 'EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED', 'artist_profile_created' );
		}

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Analytics Band' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Analytics Band', $result['name'] );
		$this->assertNotEmpty( $state->events, 'The funnel event must fire when analytics is available.' );
		$this->assertSame( 'artist_profile_created', $state->events[0]['event_type'] ?? null );
		$this->assertSame( $this->owner_id, (int) ( $state->events[0]['event_data']['user_id'] ?? 0 ) );
		$this->assertSame( (int) $result['id'], (int) ( $state->events[0]['event_data']['artist_id'] ?? 0 ) );
	}
}
