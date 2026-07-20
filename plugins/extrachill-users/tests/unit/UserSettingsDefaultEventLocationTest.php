<?php
/**
 * Tests for canonical event market discovery and the private user preference.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify Users owns canonical event market resolution across the network.
 */
class Test_User_Settings_Default_Event_Location extends WP_UnitTestCase {

	/**
	 * Events fixture blog ID.
	 *
	 * @var int
	 */
	private $events_blog_id;

	/**
	 * Community fixture blog ID.
	 *
	 * @var int
	 */
	private $community_blog_id;

	/**
	 * Create the authoritative taxonomy fixture.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->events_blog_id    = self::factory()->blog->create();
		$this->community_blog_id = self::factory()->blog->create();
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		register_taxonomy( 'location', 'post', array( 'public' => true ) );

		switch_to_blog( $this->events_blog_id );
		$region = wp_insert_term( 'USA', 'location' );
		$sc     = wp_insert_term( 'South Carolina', 'location', array( 'parent' => $region['term_id'] ) );
		$texas  = wp_insert_term( 'Texas', 'location', array( 'parent' => $region['term_id'] ) );
		$city   = wp_insert_term(
			'Charleston',
			'location',
			array(
				'slug'   => 'charleston-sc',
				'parent' => $sc['term_id'],
			)
		);
		wp_insert_term(
			'Charleston',
			'location',
			array(
				'slug'   => 'charleston-tx',
				'parent' => $texas['term_id'],
			)
		);
		update_term_meta( $city['term_id'], '_location_coordinates', '32.7765,-79.9311' );
		restore_current_blog();
	}

	/**
	 * Restore filters and taxonomy registration.
	 */
	protected function tearDown(): void {
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		unregister_taxonomy( 'location' );
		parent::tearDown();
	}

	/**
	 * Point the resolver at the fixture Events site.
	 *
	 * @return int Events blog ID.
	 */
	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	/**
	 * The public Ability remains registered on main and Community requests.
	 */
	public function test_ability_is_registered_on_main_and_community(): void {
		$this->assertNotNull( wp_get_ability( 'extrachill/user-event-locations' ) );

		switch_to_blog( $this->community_blog_id );
		try {
			$this->assertNotNull( wp_get_ability( 'extrachill/user-event-locations' ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Search returns selectable duplicate city names with distinct labels.
	 */
	public function test_search_returns_hierarchy_labels_and_restores_context(): void {
		$original_blog_id = get_current_blog_id();
		$result           = extrachill_users_ability_event_locations(
			array(
				'mode'   => 'search',
				'search' => 'Charleston',
			)
		);

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertCount( 2, $result['locations'] );
		$this->assertSame( 'Charleston, South Carolina', $result['locations'][0]['hierarchy']['label'] );
		$this->assertSame( 'Charleston, Texas', $result['locations'][1]['hierarchy']['label'] );
		$this->assertArrayHasKey( 'url', $result['locations'][0] );
	}

	/**
	 * Resolve returns the stable public shape and canonical coordinates.
	 */
	public function test_resolve_returns_canonical_location_shape(): void {
		$result   = extrachill_users_ability_event_locations(
			array(
				'mode' => 'resolve',
				'slug' => 'charleston-sc',
			)
		);
		$location = $result['location'];

		$this->assertSame( array(), $result['locations'] );
		$this->assertSame( 'charleston-sc', $location['slug'] );
		$this->assertSame( 'Charleston', $location['name'] );
		$this->assertIsInt( $location['term_id'] );
		$this->assertSame( 32.7765, $location['coordinates']['lat'] );
		$this->assertSame( 'USA', $location['hierarchy']['region'] );
		$this->assertSame( 'South Carolina', $location['hierarchy']['state'] );
		$this->assertNotSame( '', $location['url'] );
	}

	/**
	 * Onboarding persists the canonical scene and explicit visibility.
	 */
	public function test_onboarding_persists_private_local_scene_before_completion(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'sceneuser' ) );
		update_user_meta( $user_id, 'onboarding_completed', '0' );

		$result = extrachill_users_ability_complete_onboarding(
			array(
				'user_id'                => $user_id,
				'username'               => 'sceneuser',
				'user_is_artist'         => false,
				'user_is_professional'   => false,
				'local_scene'            => 'charleston-sc',
				'local_scene_visibility' => 'private',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '1', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertSame( 'charleston-sc', get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true ) );
		$this->assertSame( 'private', extrachill_users_get_local_scene_visibility( $user_id ) );
	}

	/**
	 * A rejected scene must not mark onboarding complete.
	 */
	public function test_onboarding_local_scene_failure_prevents_completion(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'invalidsceneuser' ) );
		update_user_meta( $user_id, 'onboarding_completed', '0' );

		$result = extrachill_users_ability_complete_onboarding(
			array(
				'user_id'                => $user_id,
				'username'               => 'invalidsceneuser',
				'local_scene'            => 'not-a-scene',
				'local_scene_visibility' => 'public',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( '0', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY ) );
	}

	/**
	 * Non-city terms and unknown slugs are rejected.
	 */
	public function test_invalid_write_returns_error_without_persisting(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_settings( array( 'default_event_location' => 'south-carolina' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'location_not_found', $result->get_error_code() );
		$this->assertSame( '', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * A canonical write resolves on read and an empty string clears it.
	 */
	public function test_resolved_settings_read_and_clear(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$updated = extrachill_users_ability_update_settings( array( 'default_event_location' => 'Charleston SC' ) );
		$this->assertSame( 'charleston-sc', $updated['default_event_location']['slug'] );
		$this->assertSame( 'charleston-sc', $updated['local_scene']['slug'] );
		$this->assertSame( 'charleston-sc', get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true ) );

		$settings = extrachill_users_ability_get_settings();
		$this->assertSame( 'Charleston, South Carolina', $settings['default_event_location']['hierarchy']['label'] );

		$cleared = extrachill_users_ability_update_settings( array( 'default_event_location' => '' ) );
		$this->assertNull( $cleared['default_event_location'] );
		$this->assertNull( $cleared['local_scene'] );
		$this->assertTrue( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );
	}

	/**
	 * Prompt dismissal is private settings state with a false default.
	 */
	public function test_local_scene_prompt_dismissal_is_private_and_writable(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$settings = extrachill_users_ability_get_settings();
		$this->assertTrue( $settings['onboarding_completed'] );
		$this->assertFalse( $settings['local_scene_prompt_dismissed'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY ) );

		$updated = extrachill_users_ability_update_settings( array( 'local_scene_prompt_dismissed' => true ) );

		$this->assertTrue( $updated['local_scene_prompt_dismissed'] );
		$this->assertSame( '1', get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY, true ) );
		$this->assertArrayNotHasKey( 'local_scene_prompt_dismissed', extrachill_users_ability_get_profile( array( 'user_id' => $user_id ) ) );

		$reset = extrachill_users_ability_update_settings( array( 'local_scene_prompt_dismissed' => false ) );
		$this->assertFalse( $reset['local_scene_prompt_dismissed'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY ) );
	}

	/**
	 * Selecting a scene resets dismissal so a later explicit clear can prompt again.
	 */
	public function test_selecting_local_scene_resets_prompt_dismissal(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		extrachill_users_ability_update_settings( array( 'local_scene_prompt_dismissed' => true ) );

		$selected = extrachill_users_ability_update_settings(
			array(
				'local_scene'                  => 'charleston-sc',
				'local_scene_prompt_dismissed' => true,
			)
		);

		$this->assertFalse( $selected['local_scene_prompt_dismissed'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY ) );

		$cleared = extrachill_users_ability_update_settings( array( 'local_scene' => '' ) );
		$this->assertNull( $cleared['local_scene'] );
		$this->assertFalse( $cleared['local_scene_prompt_dismissed'] );
	}

	/**
	 * Legacy storage is an idempotent fallback and canonical writes do not destroy it.
	 */
	public function test_legacy_default_falls_back_without_migration_write(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, 'charleston-sc' );

		$settings = extrachill_users_ability_get_settings();

		$this->assertSame( 'charleston-sc', $settings['local_scene']['slug'] );
		$this->assertSame( $settings['local_scene'], $settings['default_event_location'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );

		extrachill_users_ability_update_settings( array( 'local_scene' => '' ) );
		$this->assertNull( extrachill_users_ability_get_settings()['local_scene'] );
		$this->assertSame( 'charleston-sc', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );
	}

	/**
	 * Migration resolves deterministic hierarchy values but never guesses namesakes.
	 */
	public function test_legacy_local_scene_migration_dry_run_and_apply(): void {
		$matched   = self::factory()->user->create();
		$ambiguous = self::factory()->user->create();
		$unmatched = self::factory()->user->create();
		$already   = self::factory()->user->create();

		update_user_meta( $matched, 'local_city', 'Charleston, SC' );
		update_user_meta( $ambiguous, 'local_city', 'Charleston' );
		update_user_meta( $unmatched, 'local_city', 'Nowhere' );
		update_user_meta( $already, 'local_city', 'Charleston, SC' );
		update_user_meta( $already, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, 'charleston-tx' );

		$dry_run = extrachill_users_migrate_legacy_local_scenes( false, array( $matched, $ambiguous, $unmatched, $already ) );

		$this->assertSame(
			array(
				'matched'     => 1,
				'ambiguous'   => 1,
				'unmatched'   => 1,
				'already-set' => 1,
			),
			$dry_run['totals']
		);
		$this->assertFalse( metadata_exists( 'user', $matched, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );

		$applied = extrachill_users_migrate_legacy_local_scenes( true, array( $matched, $ambiguous, $unmatched, $already ) );

		$this->assertTrue( $applied['applied'] );
		$this->assertSame( 'charleston-sc', get_user_meta( $matched, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true ) );
		$this->assertSame( 'Charleston, SC', get_user_meta( $matched, 'local_city', true ) );
		$this->assertFalse( metadata_exists( 'user', $ambiguous, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );
		$this->assertFalse( metadata_exists( 'user', $unmatched, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );
		$this->assertFalse( metadata_exists( 'user', $already, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) );
	}

	/**
	 * A canonical empty Local Scene is an intentional setting and is not overwritten.
	 */
	public function test_legacy_migration_respects_explicitly_cleared_local_scene(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'local_city', 'Charleston, SC' );
		update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, '' );

		$result = extrachill_users_migrate_legacy_local_scenes( true, array( $user_id ) );

		$this->assertSame( 1, $result['totals']['already-set'] );
		$this->assertSame( '', get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true ) );
	}

	/**
	 * Visibility defaults public and private scenes hide both canonical and legacy city output.
	 */
	public function test_local_scene_visibility_controls_public_profile(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'local_city', 'Charleston' );
		extrachill_users_ability_update_settings( array( 'local_scene' => 'charleston-sc' ) );

		$public = extrachill_users_ability_get_profile( array( 'user_id' => $user_id ) );
		$this->assertSame( 'public', extrachill_users_ability_get_settings()['local_scene_visibility'] );
		$this->assertSame( 'charleston-sc', $public['local_scene']['slug'] );
		$this->assertSame( 'Charleston', $public['local_city'] );

		$private = extrachill_users_ability_update_settings( array( 'local_scene_visibility' => 'private' ) );
		$profile = extrachill_users_ability_get_profile( array( 'user_id' => $user_id ) );
		$this->assertSame( 'private', $private['local_scene_visibility'] );
		$this->assertNull( $profile['local_scene'] );
		$this->assertSame( '', $profile['local_city'] );
		$this->assertSame( 'charleston-sc', $private['local_scene']['slug'] );
	}

	/**
	 * Direct callers receive the same visibility validation as schema consumers.
	 */
	public function test_invalid_visibility_is_rejected(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_settings( array( 'local_scene_visibility' => 'friends' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_local_scene_visibility', $result->get_error_code() );
	}

	/**
	 * Concert privacy settings are canonical ability fields with legacy-public defaults.
	 */
	public function test_concert_visibility_settings_round_trip_and_validate(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		delete_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY );
		delete_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY );

		$schema = wp_get_ability( 'extrachill/update-user-settings' )->get_input_schema()['properties'];
		$this->assertSame( array( 'public', 'private' ), $schema['concert_history_visibility']['enum'] );
		$this->assertSame( array( 'public', 'private' ), $schema['event_attendance_visibility']['enum'] );

		$legacy = extrachill_users_ability_get_settings();
		$this->assertSame( 'public', $legacy['concert_history_visibility'] );
		$this->assertSame( 'public', $legacy['event_attendance_visibility'] );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY ) );

		$updated = extrachill_users_ability_update_settings(
			array(
				'concert_history_visibility'  => 'private',
				'event_attendance_visibility' => 'private',
			)
		);
		$this->assertSame( 'private', $updated['concert_history_visibility'] );
		$this->assertSame( 'private', $updated['event_attendance_visibility'] );

		$invalid = extrachill_users_ability_update_settings( array( 'concert_history_visibility' => 'friends' ) );
		$this->assertWPError( $invalid );
		$this->assertSame( 'invalid_concert_history_visibility', $invalid->get_error_code() );
	}

	/**
	 * Effective visibility changes publish one generic Users-owned transition.
	 */
	public function test_visibility_transition_action_contains_setting_and_values(): void {
		$user_id     = self::factory()->user->create();
		delete_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY );
		delete_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY );
		$transitions = array();
		$listener    = static function ( int $changed_user_id, string $setting, string $old, string $new ) use ( &$transitions ): void {
			$transitions[] = array( $changed_user_id, $setting, $old, $new );
		};
		add_action( 'extrachill_users_visibility_changed', $listener, 10, 4 );

		try {
			extrachill_users_set_concert_history_visibility( $user_id, 'private' );
			extrachill_users_set_concert_history_visibility( $user_id, 'private' );
			extrachill_users_set_event_attendance_visibility( $user_id, 'private' );
		} finally {
			remove_action( 'extrachill_users_visibility_changed', $listener, 10 );
		}

		$this->assertSame(
			array(
				array( $user_id, 'concert_history_visibility', 'public', 'private' ),
				array( $user_id, 'event_attendance_visibility', 'public', 'private' ),
			),
			$transitions
		);
	}

	/**
	 * Failed metadata writes remain private and publish no transition.
	 */
	public function test_visibility_write_failure_returns_error_without_transition(): void {
		$user_id     = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$transitions = 0;
		$block_write = static function ( $check, int $object_id, string $meta_key ) use ( $user_id ) {
			if ( $user_id === $object_id && EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY === $meta_key ) {
				return false;
			}

			return $check;
		};
		$listener = static function () use ( &$transitions ): void {
			++$transitions;
		};
		add_filter( 'update_user_metadata', $block_write, 10, 3 );
		add_action( 'extrachill_users_visibility_changed', $listener, 20, 0 );

		try {
			$result = extrachill_users_ability_update_settings( array( 'concert_history_visibility' => 'public' ) );
		} finally {
			remove_filter( 'update_user_metadata', $block_write, 10 );
			remove_action( 'extrachill_users_visibility_changed', $listener, 20 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'visibility_update_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 'concert_history_visibility', $result->get_error_data()['setting'] );
		$this->assertSame( 'private', get_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, true ) );
		$this->assertSame( 0, $transitions );
	}

	/**
	 * Users selects valid Community and Events sites and purges each once.
	 */
	public function test_visibility_cache_purge_targets_valid_domain_sites_and_restores_context(): void {
		$original_blog_id = get_current_blog_id();
		$purged_blog_ids  = array();
		$target_sites     = function (): array {
			return array( $this->community_blog_id, $this->events_blog_id, $this->community_blog_id, 999999 );
		};
		$capture_purge    = static function () use ( &$purged_blog_ids ): void {
			$purged_blog_ids[] = get_current_blog_id();
		};
		add_filter( 'extrachill_users_visibility_cache_blog_ids', $target_sites );
		add_action( 'extrachill_cache_flush', $capture_purge, 10, 0 );

		try {
			extrachill_users_purge_visibility_caches( 123, 'concert_history_visibility', 'public', 'private' );
		} finally {
			remove_filter( 'extrachill_users_visibility_cache_blog_ids', $target_sites );
			remove_action( 'extrachill_cache_flush', $capture_purge, 10 );
		}

		$this->assertSame( array( $this->community_blog_id, $this->events_blog_id ), $purged_blog_ids );
		$this->assertSame( $original_blog_id, get_current_blog_id() );
	}

	/**
	 * A failing generic cache callback cannot strand the active blog context.
	 */
	public function test_visibility_cache_purge_restores_context_when_callback_fails(): void {
		$original_blog_id = get_current_blog_id();
		$target_sites     = function (): array {
			return array( $this->community_blog_id );
		};
		$fail_purge       = static function (): void {
			throw new RuntimeException( 'cache callback failed' );
		};
		add_filter( 'extrachill_users_visibility_cache_blog_ids', $target_sites );
		add_action( 'extrachill_cache_flush', $fail_purge, 10, 0 );

		try {
			try {
				extrachill_users_purge_visibility_caches( 123, 'event_attendance_visibility', 'public', 'private' );
				$this->fail( 'Expected the cache callback exception.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'cache callback failed', $error->getMessage() );
			}
		} finally {
			remove_filter( 'extrachill_users_visibility_cache_blog_ids', $target_sites );
			remove_action( 'extrachill_cache_flush', $fail_purge, 10 );
		}

		$this->assertSame( $original_blog_id, get_current_blog_id() );
	}

	/**
	 * Missing Events infrastructure fails reads open and writes explicitly.
	 */
	public function test_unavailable_site_and_taxonomy_restore_context_and_fail_safely(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, 'charleston-sc' );
		$original_blog_id = get_current_blog_id();

		add_filter( 'extrachill_users_events_blog_id', '__return_zero', 20 );
		$site_error = extrachill_users_ability_update_settings( array( 'default_event_location' => 'charleston-tx' ) );
		remove_filter( 'extrachill_users_events_blog_id', '__return_zero', 20 );
		$this->assertWPError( $site_error );
		$this->assertSame( 'events_site_unavailable', $site_error->get_error_code() );

		unregister_taxonomy( 'location' );
		$settings       = extrachill_users_ability_get_settings();
		$taxonomy_error = extrachill_users_ability_update_settings( array( 'default_event_location' => 'charleston-tx' ) );
		$this->assertNull( $settings['default_event_location'] );
		$this->assertWPError( $taxonomy_error );
		$this->assertSame( 'location_taxonomy_unavailable', $taxonomy_error->get_error_code() );
		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertSame( 'charleston-sc', get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) );

		register_taxonomy( 'location', 'post', array( 'public' => true ) );
	}
}
