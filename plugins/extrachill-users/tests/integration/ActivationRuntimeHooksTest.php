<?php
/**
 * inc/core/activation.php is named for its activation entry points, but it
 * also registers hooks that must be live on ordinary requests.
 *
 * The file was previously loaded only from the register_activation_hook and
 * register_deactivation_hook callbacks, so every add_action() in it was dead:
 * new network sites came up with no login page, the welcome-email fallback
 * had no listener, and the admin_init dbDelta guards never ran.
 *
 * These assertions fail if the file stops being loaded during normal plugin
 * init, which is the regression that hid all of the above.
 */

class Test_Activation_Runtime_Hooks extends WP_UnitTestCase {

	/**
	 * Hooks activation.php registers that must survive plugin init.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function runtime_hooks(): array {
		return array(
			'new site login page'    => array( 'wp_initialize_site', 'extrachill_users_on_new_site' ),
			'welcome email fallback' => array( 'extrachill_welcome_email_fallback', 'extrachill_welcome_email_fallback_callback' ),
			'login page self-heal'   => array( 'admin_init', 'extrachill_users_maybe_create_login_page' ),
			'concert tracking table' => array( 'admin_init', 'extrachill_users_maybe_create_concert_tracking_table' ),
			'concert import runs'    => array( 'admin_init', 'extrachill_users_maybe_create_concert_import_runs_table' ),
			'onboarding page'        => array( 'admin_init', 'extrachill_users_maybe_create_onboarding_page' ),
		);
	}

	/**
	 * @dataProvider runtime_hooks
	 */
	public function test_runtime_hook_is_registered( string $hook, string $callback ): void {
		$this->assertNotFalse(
			has_action( $hook, $callback ),
			"{$callback} is not registered on {$hook} — activation.php is not being loaded on normal requests."
		);
	}

	/**
	 * A new site gets a login page, which is what the dead hook cost us.
	 */
	public function test_new_site_receives_a_login_page(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Site creation requires multisite.' );
		}

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$page = get_page_by_path( 'login' );
		restore_current_blog();

		$this->assertInstanceOf( WP_Post::class, $page, 'New site came up without a login page.' );
		$this->assertStringContainsString( 'wp:extrachill/login-register', (string) $page->post_content );
	}
}
