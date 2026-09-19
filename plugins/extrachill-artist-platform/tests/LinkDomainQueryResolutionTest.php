<?php
/**
 * Tests for the extrachill.link main-query emulation.
 *
 * The link-domain resolver patches the 404'd main query by hand so a link page
 * renders as a singular post. The emulation must be complete: Analytics' view
 * tracker (and any other consumer) reads the global $post via get_the_ID(), so
 * a faked singular query without $wp_query->post / $GLOBALS['post'] silently
 * drops view tracking (issue #221).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class LinkDomainQueryResolutionTest extends EC_Artist_Platform_TestCase {
	/**
	 * @var array<string,mixed> Saved $_SERVER entries restored after each test.
	 */
	private $ec_saved_server = array();

	/**
	 * @var WP_Query|null Saved main query restored after each test.
	 */
	private $ec_saved_wp_query = null;

	/**
	 * @var WP_Post|null Saved global post restored after each test.
	 */
	private $ec_saved_global_post = null;

	protected function setUp(): void {
		parent::setUp();

		foreach ( array( 'HTTP_HOST', 'SERVER_NAME', 'REQUEST_URI', 'REQUEST_METHOD' ) as $key ) {
			$this->ec_saved_server[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$this->ec_saved_wp_query    = $GLOBALS['wp_query'] ?? null;
		$this->ec_saved_global_post = $GLOBALS['post'] ?? null;
	}

	protected function tearDown(): void {
		foreach ( $this->ec_saved_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		if ( null !== $this->ec_saved_wp_query ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture: restoring the saved main query.
			$GLOBALS['wp_query'] = $this->ec_saved_wp_query;
		}
		if ( null !== $this->ec_saved_global_post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture: restoring the saved global post.
			$GLOBALS['post'] = $this->ec_saved_global_post;
		} else {
			unset( $GLOBALS['post'] );
		}
		parent::tearDown();
	}

	/**
	 * Simulate the production state the resolver patches: wp() matched no
	 * rewrite rule for the requested path and left the main query 404'd with
	 * no global post.
	 */
	private function ec_simulate_unmatched_link_domain_request( string $slug ): void {
		$_SERVER['HTTP_HOST']      = 'extrachill.link';
		$_SERVER['SERVER_NAME']    = 'extrachill.link';
		$_SERVER['REQUEST_URI']    = '/' . $slug . '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture: emulating the 404'd main query wp() produced.
		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->init();
		$GLOBALS['wp_query']->is_404 = true;
		unset( $GLOBALS['post'] );
	}

	public function test_resolver_sets_truthful_singular_post_globals_for_link_page(): void {
		$link_page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_status' => 'publish',
				'post_title'  => 'Probe Link Page',
				'post_name'   => 'probe-link-page',
			)
		);

		$this->ec_simulate_unmatched_link_domain_request( 'probe-link-page' );

		extrachill_resolve_link_domain_query();

		$this->assertFalse( is_404(), 'A matched link page must not stay a 404.' );
		$this->assertTrue( is_singular( 'artist_link_page' ), 'The emulated query must report the link page post type.' );
		$this->assertSame( $link_page_id, get_the_ID(), 'get_the_ID() must return the link page ID from the global $post.' );
		$this->assertSame( $link_page_id, (int) get_post()->ID, 'get_post() must resolve the global $post.' );
		$this->assertSame( $link_page_id, (int) $GLOBALS['post']->ID, 'The global $post must be the link page.' );
		$this->assertSame( $link_page_id, (int) $GLOBALS['wp_query']->post->ID, 'The main query current post must be the link page.' );
		$this->assertSame( -1, (int) $GLOBALS['wp_query']->current_post, 'The loop must start at the beginning if a template calls the_post().' );
	}

	public function test_resolver_keeps_genuine_404_for_root_without_default_link_page(): void {
		$this->ec_simulate_unmatched_link_domain_request( '' );

		extrachill_resolve_link_domain_query();

		$this->assertTrue( is_404(), 'The link domain root without a default link page must stay a genuine 404.' );
		$this->assertFalse( isset( $GLOBALS['post'] ), 'No global post may be fabricated for a genuine 404.' );
	}

	public function test_resolver_ignores_non_link_domain_hosts(): void {
		$link_page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_status' => 'publish',
				'post_title'  => 'Off Domain Link Page',
				'post_name'   => 'off-domain-link-page',
			)
		);

		$this->ec_simulate_unmatched_link_domain_request( 'off-domain-link-page' );
		$_SERVER['HTTP_HOST']   = 'extrachill.com';
		$_SERVER['SERVER_NAME'] = 'extrachill.com';

		extrachill_resolve_link_domain_query();

		$this->assertTrue( is_404(), 'The resolver must not touch queries on non-link-domain hosts.' );
		$this->assertFalse( isset( $GLOBALS['post'] ) );
		$this->assertGreaterThan( 0, $link_page_id );
	}
}
