<?php
/**
 * Tests for the public Local Scene member directory.
 *
 * @package ExtraChill\Users
 */

class Test_Local_Scene_Members_Ability extends WP_UnitTestCase {

	/** @var int */
	private $events_blog_id;

	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );

		switch_to_blog( $this->events_blog_id );
		register_taxonomy( 'location', 'post', array( 'public' => true ) );
		$region = wp_insert_term( 'USA', 'location' );
		$state  = wp_insert_term( 'South Carolina', 'location', array( 'parent' => $region['term_id'] ) );
		wp_insert_term( 'Charleston', 'location', array( 'slug' => 'charleston-sc', 'parent' => $state['term_id'] ) );
		restore_current_blog();
	}

	protected function tearDown(): void {
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		parent::tearDown();
	}

	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	public function test_public_readonly_ability_is_registered(): void {
		$ability = wp_get_ability( 'extrachill/local-scene-members' );

		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
	}

	public function test_privacy_filter_excludes_private_members_from_rows_and_totals(): void {
		$legacy_public   = $this->create_member( 'Alpha', null );
		$explicit_public = $this->create_member( 'Bravo', 'public' );
		$private         = $this->create_member( 'Charlie', 'private' );
		$this->create_member( 'Different Scene', 'public', 'charleston-tx' );

		$result = extrachill_users_ability_local_scene_members( array( 'slug' => 'charleston-sc' ) );
		$ids    = wp_list_pluck( $result['members'], 'user_id' );

		$this->assertSame( array( $legacy_public, $explicit_public ), $ids );
		$this->assertNotContains( $private, $ids );
		$this->assertSame( 2, $result['pagination']['total'] );
		$this->assertArrayNotHasKey( 'email', $result['members'][0] );
		$this->assertSame( 'charleston-sc', $result['scene']['slug'] );
	}

	public function test_pagination_counts_only_public_matches(): void {
		$this->create_member( 'Alpha', null );
		$second = $this->create_member( 'Bravo', 'public' );
		$this->create_member( 'Charlie', 'private' );

		$result = extrachill_users_ability_local_scene_members(
			array(
				'slug'     => 'charleston-sc',
				'page'     => 2,
				'per_page' => 1,
			)
		);

		$this->assertSame( array( $second ), wp_list_pluck( $result['members'], 'user_id' ) );
		$this->assertSame( 2, $result['pagination']['total'] );
		$this->assertSame( 2, $result['pagination']['total_pages'] );
	}

	private function create_member( string $display_name, ?string $visibility, string $scene = 'charleston-sc' ): int {
		$user_id = self::factory()->user->create( array( 'display_name' => $display_name ) );
		update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, $scene );
		if ( null !== $visibility ) {
			update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY, $visibility );
		}
		return $user_id;
	}
}
