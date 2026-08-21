<?php
/**
 * Concert stats public-profile render regression tests.
 *
 * @package ExtraChillEvents\Tests
 */

final class ConcertStatsPublicProfileRenderTest extends WP_UnitTestCase {
	private int $owner_id;
	private int $viewer_id;
	private int $original_user_id;
	private array $original_get;
	private bool $registered_test_category = false;

	protected function setUp(): void {
		parent::setUp();
		$this->original_user_id = get_current_user_id();
		$this->original_get     = $_GET;
		if ( ! wp_has_ability_category( 'extrachill-events-tests' ) ) {
			$this->registered_test_category = true;
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-events-tests',
				array(
					'label'       => 'Extra Chill Events tests',
					'description' => 'Managed test abilities.',
				)
			);
		}
		if ( wp_has_ability( 'extrachill/get-user-settings' ) ) {
			wp_unregister_ability( 'extrachill/get-user-settings' );
		}
		WP_Abilities_Registry::get_instance()->register(
			'extrachill/get-user-settings',
			array(
				'label'               => 'User settings test',
				'description'         => 'Returns an empty managed settings fixture.',
				'category'            => 'extrachill-events-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
			)
		);

		$this->owner_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->viewer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page_id         = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'my-shows',
				'post_title'  => 'My Shows',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		wp_set_current_user( 0 );
		$_GET = array();
	}

	protected function tearDown(): void {
		if ( wp_has_ability( 'extrachill/get-user-settings' ) ) {
			wp_unregister_ability( 'extrachill/get-user-settings' );
		}
		if ( $this->registered_test_category && wp_has_ability_category( 'extrachill-events-tests' ) ) {
			wp_unregister_ability_category( 'extrachill-events-tests' );
		}
		$_GET = $this->original_get;
		wp_set_current_user( $this->original_user_id );
		parent::tearDown();
	}

	public function test_owner_gets_dashboard_and_owner_only_embeds(): void {
		wp_set_current_user( $this->owner_id );

		$output = $this->render_block();

		$this->assertStringContainsString( 'data-user-id="' . $this->owner_id . '"', $output );
		$this->assertStringContainsString( 'data-is-own="1"', $output );
		$this->assertStringContainsString( 'data-public-date-to=""', $output );
		$this->assertStringContainsString( 'data-has-calendar="1"', $output );
		$this->assertStringContainsString( 'data-has-map="1"', $output );
		$this->assertStringContainsString( 'ec-concert-stats__embedded-calendar', $output );
		$this->assertStringContainsString( 'ec-concert-stats__embedded-map', $output );
	}

	public function test_logged_in_viewer_gets_selected_users_public_history(): void {
		wp_set_current_user( $this->viewer_id );
		$_GET['user_id'] = (string) $this->owner_id;

		$this->assert_public_history( $this->render_block() );
	}

	public function test_administrator_does_not_receive_another_users_owner_ui(): void {
		wp_set_current_user( $this->viewer_id );
		$_GET['user_id'] = (string) $this->owner_id;

		$this->assert_public_history( $this->render_block() );
	}

	public function test_logged_out_viewer_gets_selected_users_public_history(): void {
		$_GET['user_id'] = (string) $this->owner_id;
		$output          = $this->render_block();

		$this->assert_public_history( $output );
		$this->assertStringNotContainsString( 'ec-concert-stats-shell--marketing', $output );
	}

	public function test_logged_out_visitor_gets_complete_marketing_and_docs_surface(): void {
		$output = $this->render_block();

		$this->assertStringContainsString( 'ec-concert-stats-shell--marketing', $output );
		$this->assertStringContainsString( 'Every show has a story. Keep yours.', $output );
		$this->assertStringContainsString( 'id="how-it-works"', $output );
		$this->assertStringNotContainsString( '/register/', $output );
		$this->assertStringContainsString( '/events-calendar/getting-started-with-my-shows/', $output );
		$this->assertStringContainsString( '/events-calendar/importing-concert-history/', $output );
		$this->assertStringContainsString( '/events-calendar/concert-history-privacy/', $output );
		$this->assertStringNotContainsString( 'class="ec-concert-stats"', $output );
	}

	/** Signup CTAs consume the Users-owned registration and continuation contract. */
	public function test_signup_ctas_use_canonical_registration_with_only_my_shows_continuation(): void {
		$this->assertTrue( function_exists( 'extrachill_users_get_registration_url' ) );
		$this->assertTrue( function_exists( 'ec_users_login_url_with_redirect' ) );

		$canonical_registration = extrachill_users_get_registration_url();
		$this->assertSame( network_home_url( '/login/', 'https' ) . '#tab-register', $canonical_registration );

		$my_shows_url = trailingslashit( ec_get_site_url( 'events' ) ) . 'my-shows/';
		$expected_url = ec_users_login_url_with_redirect( $canonical_registration, $my_shows_url );
		$output       = $this->render_block();

		$this->assertSame( 2, substr_count( $output, 'href="' . esc_url( $expected_url ) . '"' ) );
		$this->assertStringContainsString( 'redirect_to=', $expected_url );
		$this->assertSame( 'tab-register', wp_parse_url( $expected_url, PHP_URL_FRAGMENT ) );
		parse_str( (string) wp_parse_url( $expected_url, PHP_URL_QUERY ), $query );
		$this->assertSame( array( 'redirect_to' => $my_shows_url ), $query );
	}

	public function test_invalid_selection_does_not_fall_back_to_viewer(): void {
		wp_set_current_user( $this->viewer_id );
		$_GET['user_id'] = '999999';

		$output = $this->render_block();

		$this->assertStringContainsString( 'This concert history could not be found.', $output );
		$this->assertStringNotContainsString( 'data-user-id="' . $this->viewer_id . '"', $output );
		$this->assertStringNotContainsString( 'class="ec-concert-stats"', $output );
	}

	public function test_malformed_selection_fails_safely(): void {
		wp_set_current_user( $this->viewer_id );
		$_GET['user_id'] = array( (string) $this->owner_id );

		$output = $this->render_block();

		$this->assertStringContainsString( 'This concert history could not be found.', $output );
		$this->assertStringNotContainsString( 'data-user-id="' . $this->viewer_id . '"', $output );
	}

	private function assert_public_history( string $output ): void {
		$this->assertStringContainsString( 'data-user-id="' . $this->owner_id . '"', $output );
		$this->assertStringContainsString( 'data-is-own="0"', $output );
		$this->assertStringContainsString( 'data-public-date-to="' . current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ) . '"', $output );
		$this->assertStringContainsString( 'data-has-calendar="0"', $output );
		$this->assertStringContainsString( 'data-has-map="0"', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-calendar', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-map', $output );
	}

	private function render_block(): string {
		$attributes = array();

		ob_start();
		include dirname( __DIR__, 2 ) . '/blocks/concert-stats/render.php';
		return (string) ob_get_clean();
	}
}
