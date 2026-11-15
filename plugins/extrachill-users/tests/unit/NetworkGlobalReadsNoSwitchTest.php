<?php
/**
 * Network-global reads must not switch blogs (#1431).
 *
 * Users and usermeta are network-global tables, and get_users() /
 * is_user_member_of_blog() take a blog ID. These tests prove the logged-in
 * hot-path helpers return the same values without a switch_to_blog() call.
 *
 * @package ExtraChill\Users
 */

// Shared fixture helpers (mapped sites).
require_once dirname( __DIR__ ) . '/support/fixtures.php';

/**
 * Verify online-user, footer, and team helpers read without switching blogs.
 */
class Test_Network_Global_Reads_No_Switch extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $switches = 0;
	private int $community_blog_id;
	private int $main_blog_id;

	public static function wpSetUpBeforeClass(): void {
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'community' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'main' ) );
	}

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network-global read checks require multisite.' );
		}

		require_once dirname( __DIR__, 2 ) . '/inc/core/online-users.php';
		require_once dirname( __DIR__, 2 ) . '/inc/footer/online-users-stats.php';
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';
		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';

		$this->community_blog_id = (int) ec_get_blog_id( 'community' );
		$this->main_blog_id      = (int) ec_get_blog_id( 'main' );

		$this->switches = 0;
		add_action( 'switch_blog', array( $this, 'count_switch' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_action( 'switch_blog', array( $this, 'count_switch' ), 10 );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function count_switch( $new_blog_id, $prev_blog_id, $context = 'switch' ): void {
		if ( 'switch' === $context ) {
			++$this->switches;
		}
	}

	/**
	 * Switch to a blog without counting it as a switch under test.
	 */
	private function go_to_blog( int $blog_id ): void {
		remove_action( 'switch_blog', array( $this, 'count_switch' ), 10 );
		switch_to_blog( $blog_id );
		add_action( 'switch_blog', array( $this, 'count_switch' ), 10, 3 );
	}

	private function leave_blog(): void {
		remove_action( 'switch_blog', array( $this, 'count_switch' ), 10 );
		restore_current_blog();
		add_action( 'switch_blog', array( $this, 'count_switch' ), 10, 3 );
	}

	public function test_last_active_reads_global_meta_without_switching(): void {
		$user_id = self::factory()->user->create();

		$this->assertNull( ec_get_last_active( $user_id ) );
		$this->assertNull( ec_get_last_active( 0 ) );

		update_user_meta( $user_id, 'last_active', 1700000000 );

		$this->assertSame( 1700000000, ec_get_last_active( $user_id ) );
		$this->assertSame( 0, $this->switches, 'last_active is network-global usermeta; no switch is needed.' );
	}

	public function test_record_activity_writes_when_stale_and_busts_community_online_cache(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'last_active', time() - 1000 );

		$this->go_to_blog( $this->community_blog_id );
		set_transient( 'ec_network_stat_online_users', array( 'value' => 99 ), HOUR_IN_SECONDS );
		$this->leave_blog();

		$before = time();
		ec_record_user_activity();

		$this->assertGreaterThanOrEqual( $before, ec_get_last_active( $user_id ) );

		$this->go_to_blog( $this->community_blog_id );
		$cached = get_transient( 'ec_network_stat_online_users' );
		$this->leave_blog();

		$this->assertFalse( $cached, 'A recorded activity invalidates the community online_users cache.' );
	}

	public function test_record_activity_is_throttled_by_last_active_without_switching(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$recent = time() - 60;
		update_user_meta( $user_id, 'last_active', $recent );

		ec_record_user_activity();

		$this->assertSame( $recent, ec_get_last_active( $user_id ), 'Activity within 15 minutes is not rewritten.' );
		$this->assertSame( 0, $this->switches, 'The throttled hot path never switches blogs.' );
	}

	public function test_record_activity_on_community_never_switches(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->go_to_blog( $this->community_blog_id );
		ec_record_user_activity();
		$this->leave_blog();

		$this->assertNotNull( ec_get_last_active( $user_id ), 'First activity is recorded.' );
		$this->assertSame( 0, $this->switches, 'Recording from Community needs no switch.' );
	}

	public function test_record_activity_ignores_logged_out_requests(): void {
		wp_set_current_user( 0 );

		ec_record_user_activity();

		$this->assertSame( 0, $this->switches );
	}

	public function test_footer_reads_total_members_from_network_stats(): void {
		if ( ! function_exists( 'ec_get_network_stats' ) ) {
			$this->markTestSkipped( 'extrachill-network NetworkStats is not loaded.' );
		}

		if ( ! function_exists( 'ec_icon' ) ) {
			// The theme owns ec_icon(); a trivial stand-in keeps the widget renderable.
			eval( 'function ec_icon( $icon_id, $css_class = "" ) { return "<svg></svg>"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		set_transient( 'ec_network_stat_online_users', array( 'value' => 3 ), HOUR_IN_SECONDS );
		set_transient( 'ec_network_stat_total_members', array( 'value' => 1234 ), HOUR_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create() );

		ob_start();
		extrachill_users_display_online_stats();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<span class="stat-value">3</span>', $html );
		$this->assertStringContainsString( '<span class="stat-value">1234</span>', $html );
		$this->assertSame( 0, $this->switches, 'Cached network stats render without a blog switch.' );
	}

	public function test_main_site_account_check_does_not_switch(): void {
		$member_id = self::factory()->user->create();
		add_user_to_blog( $this->main_blog_id, $member_id, 'subscriber' );
		$outsider_id = self::factory()->user->create();
		remove_user_from_blog( $outsider_id, $this->main_blog_id );
		wp_set_current_user( $member_id );

		$this->go_to_blog( $this->community_blog_id );
		$this->switches = 0;

		$member   = ec_has_main_site_account( $member_id );
		$outsider = ec_has_main_site_account( $outsider_id );
		$switches = $this->switches;
		$this->leave_blog();

		$this->assertTrue( $member );
		$this->assertFalse( $outsider );
		$this->assertFalse( ec_has_main_site_account( 0 ) );
		$this->assertSame( 0, $switches, 'is_user_member_of_blog() takes the blog ID; no switch is needed.' );
	}

	public function test_team_member_ids_query_main_site_without_switching(): void {
		$main_site_id = (int) get_main_site_id();

		$this->go_to_blog( $main_site_id );
		ec_users_register_team_role();
		$member_id = self::factory()->user->create( array( 'role' => EC_USERS_TEAM_ROLE ) );
		$this->leave_blog();

		$other_member_id = self::factory()->user->create();
		$this->go_to_blog( $this->community_blog_id );
		ec_users_register_team_role();
		add_user_to_blog( $this->community_blog_id, $other_member_id, EC_USERS_TEAM_ROLE );
		$this->switches = 0;

		$ids      = ec_users_get_team_member_ids();
		$switches = $this->switches;
		remove_role( EC_USERS_TEAM_ROLE );
		$this->leave_blog();

		$this->go_to_blog( $main_site_id );
		remove_role( EC_USERS_TEAM_ROLE );
		$this->leave_blog();

		$this->assertContains( $member_id, $ids, 'Main-site team role holders are returned.' );
		$this->assertNotContains( $other_member_id, $ids, 'Role holders on other sites only are not returned.' );
		$this->assertSame( 0, $switches, 'get_users() is scoped by blog_id; no switch is needed.' );
	}
}
