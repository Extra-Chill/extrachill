<?php
/**
 * Team member New Post guard tests (#425).
 *
 * The extra_chill_team role grants edit_posts network-wide, so core
 * shows New -> Post in the admin bar on every site and the block editor
 * happily creates a pending `post` wherever the writer is. Editorial
 * posts only have a lifecycle on main (via Studio Compose), so those
 * writes strand. These tests verify the entry-point guard: non-admins
 * on non-main sites are redirected off wp-admin New Post for `post`
 * and lose the admin-bar entry, while main site, admins, and other
 * post types are untouched.
 */

class Test_Team_New_Post_Redirect extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $subsite_blog_id;
	private int $main_blog_id;
	private int $team_user_id;
	private int $admin_user_id;

	private array $original_get;
	private $original_typenow;

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';

		$this->main_blog_id = (int) ec_get_blog_id( 'main' );

		// Materialize the mapped Studio blog BEFORE creating the throwaway
		// subsite, so the subsite can never be allocated Studio's ID.
		$studio_blog_id = (int) ec_get_blog_id( 'studio' );
		$this->assertNotFalse( get_blog_details( $studio_blog_id ), 'Mapped Studio blog fixture missing.' );

		$this->subsite_blog_id = self::factory()->blog->create();
		$this->assertNotSame( $this->main_blog_id, $this->subsite_blog_id );
		$this->assertNotSame( $studio_blog_id, $this->subsite_blog_id );

		$this->team_user_id  = self::factory()->user->create();
		$this->admin_user_id = self::factory()->user->create();

		$this->original_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test state save/restore.
		$this->original_typenow = $GLOBALS['typenow'] ?? null;
		unset( $GLOBALS['typenow'] );
	}

	protected function tearDown(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		$_GET = $this->original_get;
		if ( null === $this->original_typenow ) {
			unset( $GLOBALS['typenow'] );
		} else {
			$GLOBALS['typenow'] = $this->original_typenow;
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure a site exists at a fixed, mapped blog ID.
	 *
	 * Blog IDs are auto-increment and InnoDB does not roll the counter back
	 * between tests, so "create blogs until we reach ID N" breaks as soon as
	 * earlier tests have allocated past N. Insert the row at the exact ID
	 * instead and initialize it like core does.
	 */
	/**
	 * Create the mapped Studio blog once per class. Fixtures created here are
	 * committed by the WP test framework, so the row and its tables stay
	 * consistent across tests instead of rolling back per test.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		self::ensure_blog_exists( (int) ec_get_blog_id( 'studio' ) );
	}

	private static function ensure_blog_exists( int $blog_id ): void {
		global $wpdb;

		clean_blog_cache( $blog_id );
		if ( $blog_id <= 0 || get_blog_details( $blog_id ) ) {
			return;
		}

		$network = get_network();
		$now     = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture must pin the mapped blog ID.
		$wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id'      => $blog_id,
				'site_id'      => (int) $network->id,
				'domain'       => $network->domain,
				'path'         => '/mapped-' . $blog_id . '/',
				'registered'   => $now,
				'last_updated' => $now,
			)
		);
		clean_blog_cache( $blog_id );
		$initialized = wp_initialize_site( $blog_id, array( 'title' => 'Mapped ' . $blog_id ) );
		if ( is_wp_error( $initialized ) && 'site_already_initialized' !== $initialized->get_error_code() ) {
			throw new RuntimeException( 'Could not initialize mapped blog ID ' . $blog_id . ': ' . $initialized->get_error_message() );
		}
		if ( ! get_blog_details( $blog_id ) ) {
			throw new RuntimeException( 'Could not create expected mapped blog ID ' . $blog_id . '.' );
		}
	}

	private function add_user_role_on_blog( int $user_id, int $blog_id, string $role ): void {
		switch_to_blog( $blog_id );
		if ( EC_USERS_TEAM_ROLE === $role ) {
			ec_users_register_team_role();
		}
		$user = new WP_User( $user_id );
		$user->add_role( $role );
		restore_current_blog();
	}

	private function act_as_team_member_on_subsite(): void {
		$this->add_user_role_on_blog( $this->team_user_id, $this->subsite_blog_id, EC_USERS_TEAM_ROLE );
		switch_to_blog( $this->subsite_blog_id );
		wp_set_current_user( $this->team_user_id );
	}

	private function act_as_admin_on_subsite(): void {
		$this->add_user_role_on_blog( $this->admin_user_id, $this->subsite_blog_id, 'administrator' );
		switch_to_blog( $this->subsite_blog_id );
		wp_set_current_user( $this->admin_user_id );
	}

	private function act_as_team_member_on_main(): void {
		$this->add_user_role_on_blog( $this->team_user_id, $this->main_blog_id, EC_USERS_TEAM_ROLE );
		switch_to_blog( $this->main_blog_id );
		wp_set_current_user( $this->team_user_id );
	}

	private function restore_blog(): void {
		if ( ms_is_switched() ) {
			restore_current_blog();
		}
	}

	/**
	 * Exercise the real redirect callback without letting it exit PHPUnit.
	 *
	 * @return array{location: string, status: int}
	 */
	private function capture_redirect(): array {
		$redirect_filter = static function ( $location, $status ) {
			throw new RuntimeException(
				wp_json_encode(
					array(
						'location' => $location,
						'status'   => $status,
					)
				)
			);
		};
		add_filter( 'wp_redirect', $redirect_filter, 10, 2 );

		try {
			ec_users_redirect_new_post_to_studio();
			$this->fail( 'Expected New Post to redirect off the non-main site.' );
		} catch ( RuntimeException $exception ) {
			$redirect = json_decode( $exception->getMessage(), true );
			$this->assertIsArray( $redirect );
			return $redirect;
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 10 );
			$this->restore_blog();
		}
	}

	private function expect_no_redirect(): void {
		$redirect_filter = static function ( $location, $status ) {
			throw new RuntimeException(
				wp_json_encode(
					array(
						'unexpected_redirect_to' => $location,
						'status'                 => $status,
					)
				)
			);
		};
		add_filter( 'wp_redirect', $redirect_filter, 10, 2 );

		try {
			ec_users_redirect_new_post_to_studio();
			$this->assertTrue( true );
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 10 );
			$this->restore_blog();
		}
	}

	// -----------------------------------------------------------------
	// Hooks registered
	// -----------------------------------------------------------------

	public function test_redirect_hook_is_registered(): void {
		$this->assertNotFalse( has_action( 'load-post-new.php', 'ec_users_redirect_new_post_to_studio' ) );
	}

	public function test_admin_bar_hook_is_registered_late(): void {
		$this->assertSame( 1000, has_action( 'admin_bar_menu', 'ec_users_prune_admin_bar_new_post' ) );
	}

	// -----------------------------------------------------------------
	// Redirect behavior
	// -----------------------------------------------------------------

	public function test_team_member_new_post_on_subsite_redirects_to_studio(): void {
		$this->act_as_team_member_on_subsite();

		$redirect = $this->capture_redirect();

		$this->assertSame( get_site_url( (int) ec_get_blog_id( 'studio' ), '/' ), $redirect['location'] );
		$this->assertSame( 302, $redirect['status'] );
	}

	public function test_team_member_explicit_post_type_post_redirects(): void {
		$this->act_as_team_member_on_subsite();
		$_GET['post_type'] = 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test simulation of the post-new.php entry point.

		$redirect = $this->capture_redirect();

		$this->assertSame( get_site_url( (int) ec_get_blog_id( 'studio' ), '/' ), $redirect['location'] );
	}

	public function test_team_member_other_post_type_is_not_redirected(): void {
		$this->act_as_team_member_on_subsite();
		$_GET['post_type'] = 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test simulation of the post-new.php entry point.

		$this->expect_no_redirect();
	}

	public function test_admin_on_subsite_is_not_redirected(): void {
		$this->act_as_admin_on_subsite();

		$this->expect_no_redirect();
	}

	public function test_team_member_on_main_site_is_not_redirected(): void {
		$this->act_as_team_member_on_main();

		$this->expect_no_redirect();
	}

	// -----------------------------------------------------------------
	// Admin bar behavior
	// -----------------------------------------------------------------

	/**
	 * Build an admin bar shaped the way core leaves it for a team
	 * member: New -> Post and New -> Media.
	 */
	private function make_admin_bar_with_new_content( string $parent_href ): WP_Admin_Bar {
		$bar = new WP_Admin_Bar();

		$bar->add_node(
			array(
				'id'   => 'new-content',
				'href' => $parent_href,
			)
		);
		$bar->add_node(
			array(
				'parent' => 'new-content',
				'id'     => 'new-post',
				'href'   => admin_url( 'post-new.php' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'new-content',
				'id'     => 'new-media',
				'href'   => admin_url( 'media-new.php' ),
			)
		);

		return $bar;
	}

	public function test_admin_bar_new_post_removed_and_parent_repointed_on_subsite(): void {
		$this->act_as_team_member_on_subsite();

		$bar = $this->make_admin_bar_with_new_content( admin_url( 'post-new.php' ) );
		ec_users_prune_admin_bar_new_post( $bar );

		$this->assertNull( $bar->get_node( 'new-post' ) );

		$new_content = $bar->get_node( 'new-content' );
		$this->assertNotNull( $new_content );
		$this->assertSame( admin_url( 'media-new.php' ), $new_content->href );
	}

	public function test_admin_bar_parent_removed_when_no_children_remain(): void {
		$this->act_as_team_member_on_subsite();

		$bar = new WP_Admin_Bar();
		$bar->add_node(
			array(
				'id'   => 'new-content',
				'href' => admin_url( 'post-new.php' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'new-content',
				'id'     => 'new-post',
				'href'   => admin_url( 'post-new.php' ),
			)
		);

		ec_users_prune_admin_bar_new_post( $bar );

		$this->assertNull( $bar->get_node( 'new-post' ) );
		$this->assertNull( $bar->get_node( 'new-content' ) );
	}

	public function test_admin_bar_parent_with_post_type_href_is_left_alone(): void {
		$this->act_as_team_member_on_subsite();

		$bar = $this->make_admin_bar_with_new_content( admin_url( 'post-new.php?post_type=page' ) );
		ec_users_prune_admin_bar_new_post( $bar );

		$this->assertNull( $bar->get_node( 'new-post' ) );

		$new_content = $bar->get_node( 'new-content' );
		$this->assertNotNull( $new_content );
		$this->assertSame( admin_url( 'post-new.php?post_type=page' ), $new_content->href );
	}

	public function test_admin_bar_untouched_for_admin_on_subsite(): void {
		$this->act_as_admin_on_subsite();

		$bar = $this->make_admin_bar_with_new_content( admin_url( 'post-new.php' ) );
		ec_users_prune_admin_bar_new_post( $bar );

		$this->assertNotNull( $bar->get_node( 'new-post' ) );
		$this->assertSame( admin_url( 'post-new.php' ), $bar->get_node( 'new-content' )->href );
	}

	public function test_admin_bar_untouched_on_main_site(): void {
		$this->act_as_team_member_on_main();

		$bar = $this->make_admin_bar_with_new_content( admin_url( 'post-new.php' ) );
		ec_users_prune_admin_bar_new_post( $bar );

		$this->assertNotNull( $bar->get_node( 'new-post' ) );
		$this->assertSame( admin_url( 'post-new.php' ), $bar->get_node( 'new-content' )->href );
	}
}
