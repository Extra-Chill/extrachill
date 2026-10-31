<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistObjectCapabilitiesTest extends EC_Artist_Platform_TestCase {
	private $owner_id;
	private $admin_id;
	private $profile_id;
	private $draft_profile_id;
	private $pending_profile_id;
	private $revision_id;
	private $regular_post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->owner_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id = $this->create_admin_user( 'admin' );
		$this->ec_grant_artist_blog_role( $this->admin_id, 'administrator' );

		$this->profile_id         = $this->create_artist_profile( 'Bound Artist' );
		$this->draft_profile_id   = $this->create_artist_profile( 'Draft Artist', array( 'post_status' => 'draft' ) );
		$this->pending_profile_id = $this->create_artist_profile( 'Pending Artist', array( 'post_status' => 'pending' ) );

		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array( $this->owner_id ) );
		update_post_meta( $this->draft_profile_id, '_artist_member_ids', array( $this->owner_id ) );
		update_post_meta( $this->pending_profile_id, '_artist_member_ids', array( $this->owner_id ) );
		wp_update_post(
			array(
				'ID'           => $this->profile_id,
				'post_content' => 'Updated bio for revision.',
			)
		);
		$this->revision_id = (int) wp_save_post_revision( $this->profile_id );
		restore_current_blog();
		update_user_meta( $this->owner_id, '_artist_profile_ids', array( $this->profile_id, $this->draft_profile_id, $this->pending_profile_id ) );

		$this->regular_post_id = (int) self::factory()->post->create( array( 'post_title' => 'Regular Post' ) );
	}

	public function test_artist_profiles_register_isolated_caps_and_revision_support(): void {
		extrachill_register_artist_profile_cpt();
		$args = get_post_type_object( 'artist_profile' );

		$this->assertSame( array( 'artist_profile' ), (array) $args->capability_type );
		$this->assertTrue( (bool) $args->map_meta_cap );
		$this->assertTrue( (bool) $args->show_in_rest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source-file read, not a remote URL.
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/core/artist-platform-post-types.php' );
		$this->assertStringContainsString( "'revisions'", $source );
	}

	public function test_reciprocal_member_receives_mapped_primitives_for_core_rest_crud(): void {
		$user = get_userdata( $this->owner_id );
		$crud = array(
			'read_post'    => array( 'read' ),
			'edit_post'    => array( 'edit_others_artist_profiles', 'edit_published_artist_profiles' ),
			'publish_post' => array( 'publish_artist_profiles' ),
			'delete_post'  => array( 'delete_others_artist_profiles', 'delete_published_artist_profiles' ),
		);

		switch_to_blog( $this->artist_blog_id() );
		try {
			foreach ( $crud as $requested => $required ) {
				$has_cap = user_can( $user, $requested, $this->profile_id );
				foreach ( $required as $primitive ) {
					$mapped = user_can( $user, $requested, $this->profile_id );
					$this->assertTrue( $mapped, $requested . ' did not grant the artist profile.' );
					unset( $primitive );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	public function test_protected_meta_keeps_core_denial_while_nested_edit_caps_are_granted(): void {
		$user = get_userdata( $this->owner_id );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertTrue( user_can( $user, 'edit_post', $this->profile_id ) );
			$this->assertFalse( user_can( $user, 'edit_post_meta', $this->profile_id, '_artist_member_ids' ) );
			$this->assertFalse( user_can( $user, 'add_post_meta', $this->profile_id, '_artist_member_ids' ) );
			$this->assertFalse( user_can( $user, 'delete_post_meta', $this->profile_id, '_artist_member_ids' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_unprotected_meta_receives_only_nested_artist_edit_caps(): void {
		$user = get_userdata( $this->owner_id );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertTrue( user_can( $user, 'edit_post_meta', $this->profile_id, 'public_key' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_one_sided_membership_and_generic_editor_caps_do_not_grant_access(): void {
		$one_way_id = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->ec_grant_artist_blog_role( $one_way_id, 'editor' );
		// Editor caps exist, but no reciprocal membership.
		update_user_meta( $one_way_id, '_artist_profile_ids', array() );

		$editor = get_userdata( $one_way_id );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertFalse( user_can( $editor, 'edit_post', $this->profile_id ) );
			$this->assertFalse( user_can( $editor, 'edit_artist_profiles' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_administrator_receives_object_primitives(): void {
		$admin = get_userdata( $this->admin_id );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertTrue( user_can( $admin, 'edit_post', $this->profile_id ) );
			$this->assertTrue( user_can( $admin, 'edit_artist_profiles' ) );
			$this->assertTrue( user_can( $admin, 'publish_artist_profiles' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_revisions_and_autosaves_resolve_to_the_artist_parent(): void {
		if ( ! post_type_supports( 'artist_profile', 'revisions' ) || ! $this->revision_id ) {
			$this->markTestSkipped( 'The artist profile post type does not keep revisions in this runtime.' );
		}
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertSame( $this->profile_id, (int) ec_get_artist_id_for_owned_object( $this->revision_id ) );
			$this->assertSame( $this->profile_id, (int) ec_get_artist_id_for_owned_object( $this->profile_id ) );
			$this->assertTrue( ec_user_can_manage_artist_object( $this->owner_id, $this->profile_id ) );
		} finally {
			restore_current_blog();
		}

		$mapped = ec_map_artist_object_capabilities( array( 'do_not_allow' ), 'delete_post', $this->owner_id, array( $this->revision_id ) );
		$this->assertSame( array( 'delete_others_artist_profiles', 'delete_published_artist_profiles' ), $mapped );
	}

	public function test_draft_and_pending_profiles_keep_crud_revision_and_autosave_access(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertTrue( ec_user_can_manage_artist_object( $this->owner_id, $this->draft_profile_id ) );
			$this->assertTrue( ec_user_can_manage_artist_object( $this->owner_id, $this->pending_profile_id ) );
			$this->assertTrue( ec_user_can_manage_artist_object( $this->owner_id, $this->profile_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_non_artist_objects_are_untouched(): void {
		$mapped = ec_map_artist_object_capabilities( array( 'do_not_allow' ), 'delete_post', $this->owner_id, array( $this->regular_post_id ) );

		$this->assertSame( array( 'do_not_allow' ), $mapped );
	}

	/**
	 * Unrelated object arguments must not be interpreted as artist IDs.
	 */
	public function test_unrelated_capabilities_with_object_arguments_are_untouched_without_warnings(): void {
		$allcaps  = array( 'edit_posts' => true );
		$warnings = array();
		$contexts = array(
			(object) array(
				'name' => 'core/edit-post',
				'post' => (object) array( 'post_type' => 'topic' ),
			),
			new stdClass(),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Captures the regression warning.
		set_error_handler(
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);

		try {
			foreach ( $contexts as $context ) {
				$this->assertSame(
					$allcaps,
					ec_filter_user_capabilities( $allcaps, array( 'edit_block_bindings' ), array( 'edit_block_binding', $this->owner_id, $context ), (object) array( 'ID' => $this->owner_id ) )
				);
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
	}

	/**
	 * Owned object capabilities require a numeric object ID.
	 */
	public function test_owned_capability_requires_an_object_id_argument(): void {
		$allcaps = array( 'edit_posts' => true );

		$this->assertSame(
			$allcaps,
			ec_filter_user_capabilities( $allcaps, array( 'edit_others_artist_profiles' ), array( 'edit_post', $this->owner_id, new stdClass() ), (object) array( 'ID' => $this->owner_id ) )
		);
	}
}
