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
