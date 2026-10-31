<?php
/**
 * Gating tests for the logged-out comment login CTA (issue #464).
 *
 * Logged-out readers on singular pages outside the main blog (e.g. single
 * newsletters) saw only the theme's bare "Log in to leave a comment." note
 * because the only CTA implementation lived in extrachill-blog and was gated
 * on `is_singular( 'post' )`. extrachill-users is network-active and owns the
 * `extrachill/login-register` block, so it now owns the CTA for every site
 * and post type.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify the comment login CTA renders exactly when it should.
 */
class Test_Comment_Login_Cta extends WP_UnitTestCase {

	/**
	 * Post fixture with open comments.
	 *
	 * @var int
	 */
	private static $open_post_id;

	/**
	 * Post fixture with closed comments.
	 *
	 * @var int
	 */
	private static $closed_post_id;

	/**
	 * Password-protected post fixture with open comments.
	 *
	 * @var int
	 */
	private static $password_post_id;

	/**
	 * Non-"post" post type fixture (stand-in for newsletters) with open comments.
	 *
	 * @var int
	 */
	private static $page_id;

	/**
	 * Create shared post fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Fixture factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$open_post_id = $factory->post->create(
			array( 'comment_status' => 'open' )
		);

		self::$closed_post_id = $factory->post->create(
			array( 'comment_status' => 'closed' )
		);

		self::$password_post_id = $factory->post->create(
			array(
				'comment_status' => 'open',
				'post_password'  => 'secret',
			)
		);

		self::$page_id = $factory->post->create(
			array(
				'post_type'      => 'page',
				'comment_status' => 'open',
			)
		);
	}

	/**
	 * Start every test logged out.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	/**
	 * The CTA must be attached to the theme's pre-comments hook.
	 */
	public function test_cta_is_hooked_to_before_comments_template(): void {
		$this->assertNotFalse(
			has_action( 'extrachill_before_comments_template', 'extrachill_users_render_comment_login_cta' ),
			'extrachill_users_render_comment_login_cta is not registered on extrachill_before_comments_template.'
		);
	}

	/**
	 * Logged-out visitor on a singular post with open comments sees the CTA.
	 */
	public function test_cta_renders_for_logged_out_visitor_on_open_singular_post(): void {
		$this->go_to( get_permalink( self::$open_post_id ) );

		$this->assertTrue( extrachill_users_should_show_comment_login_cta() );

		$output = $this->render_cta();
		$this->assertStringContainsString( 'Login or Register to Comment', $output );
		$this->assertStringContainsString( 'comment-login-cta', $output );
	}

	/**
	 * The CTA is not gated on the "post" post type: any singular content with
	 * open comments qualifies (this was the newsletter regression).
	 */
	public function test_cta_renders_on_non_post_singular_content(): void {
		$this->go_to( get_permalink( self::$page_id ) );

		$this->assertTrue( extrachill_users_should_show_comment_login_cta() );
		$this->assertStringContainsString( 'Login or Register to Comment', $this->render_cta() );
	}

	/**
	 * Logged-in users get the real comment form, not the CTA.
	 */
	public function test_cta_hidden_for_logged_in_user(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->go_to( get_permalink( self::$open_post_id ) );

		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * No CTA on non-singular views such as the posts archive.
	 */
	public function test_cta_hidden_on_non_singular_view(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_singular() );
		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * No CTA when comments are closed.
	 */
	public function test_cta_hidden_when_comments_closed(): void {
		$this->go_to( get_permalink( self::$closed_post_id ) );

		$this->assertTrue( is_singular() );
		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * No CTA behind a post password gate.
	 */
	public function test_cta_hidden_when_post_password_required(): void {
		$this->go_to( get_permalink( self::$password_post_id ) );

		$this->assertTrue( post_password_required() );
		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * Sites can opt out via the extrachill_users_show_comment_login_cta filter.
	 */
	public function test_filter_can_suppress_cta(): void {
		$this->go_to( get_permalink( self::$open_post_id ) );
		add_filter( 'extrachill_users_show_comment_login_cta', '__return_false' );

		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * The filter cannot force the CTA onto ineligible views.
	 */
	public function test_filter_cannot_force_cta_onto_ineligible_view(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->go_to( get_permalink( self::$open_post_id ) );
		add_filter( 'extrachill_users_show_comment_login_cta', '__return_true' );

		$this->assertFalse( extrachill_users_should_show_comment_login_cta() );
		$this->assertSame( '', $this->render_cta() );
	}

	/**
	 * Post types can retitle the CTA heading.
	 */
	public function test_heading_is_filterable(): void {
		$this->go_to( get_permalink( self::$open_post_id ) );
		$filter = static fn() => 'Log in or register to rate this show';
		add_filter( 'extrachill_users_comment_login_cta_heading', $filter );

		$output = $this->render_cta();
		remove_filter( 'extrachill_users_comment_login_cta_heading', $filter );

		$this->assertStringContainsString( 'Log in or register to rate this show', $output );
		$this->assertStringNotContainsString( 'Login or Register to Comment', $output );
	}

	/**
	 * Render the CTA through the real action, capturing output.
	 *
	 * @return string Rendered markup.
	 */
	private function render_cta(): string {
		ob_start();
		do_action( 'extrachill_before_comments_template' );
		return (string) ob_get_clean();
	}
}
