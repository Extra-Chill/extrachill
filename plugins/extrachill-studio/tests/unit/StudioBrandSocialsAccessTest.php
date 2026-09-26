<?php
/**
 * Studio brand-socials render access contract tests.
 *
 * Ports the standalone tests/studio-brand-socials-access.php harness to real
 * WordPress: real users, real capabilities, and the real Extra Chill team
 * gate (access_studio capability via ec_is_team_member).
 *
 * @package ExtraChillStudio
 */

/**
 * Verify brand-socials access gating in the Studio block render.
 */
class Test_Studio_Brand_Socials_Access extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function render_studio_block( int $user_id ): string {
		wp_set_current_user( $user_id );

		$attributes = array();
		ob_start();
		include dirname( __DIR__, 2 ) . '/src/blocks/studio/render.php';
		return (string) ob_get_clean();
	}

	private function create_team_member( array $extra_caps = array() ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		// access_studio is the capability ec_is_team_member() checks.
		$user->add_cap( 'access_studio' );
		foreach ( $extra_caps as $cap ) {
			$user->add_cap( $cap );
		}
		return $user_id;
	}

	public function test_ordinary_team_members_do_not_receive_brand_social_access(): void {
		$output = $this->render_studio_block( $this->create_team_member() );

		$this->assertStringContainsString( 'data-ec-studio-root', $output, 'Ordinary team members reach the Studio app shell.' );
		$this->assertStringNotContainsString( 'data-can-brand-socials="true"', $output, 'Ordinary team members must not receive brand-social access.' );
		$this->assertStringContainsString( 'data-can-brand-socials="false"', $output );
	}

	public function test_explicitly_granted_team_members_receive_brand_social_access(): void {
		$output = $this->render_studio_block( $this->create_team_member( array( 'manage_brand_socials' ) ) );

		$this->assertStringContainsString( 'data-ec-studio-root', $output );
		$this->assertStringContainsString( 'data-can-brand-socials="true"', $output, 'Explicitly granted team members must receive brand-social access.' );
	}

	public function test_administrators_retain_brand_social_access(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$output = $this->render_studio_block( $admin_id );

		$this->assertStringContainsString( 'data-ec-studio-root', $output );
		$this->assertStringContainsString( 'data-can-brand-socials="true"', $output, 'Administrators must retain brand-social access.' );
	}
}
