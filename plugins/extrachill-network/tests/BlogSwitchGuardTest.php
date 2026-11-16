<?php
/**
 * Regression tests for the leaked blog switch guard (#1512).
 *
 * A Studio 404 rendered as the Documentation site because something switched
 * to blog 10 and never restored before the template ran. These tests leak a
 * switch the same way and assert the guard renders the request blog again
 * and names the code that switched.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * @group blog-switch-guard
 */
class BlogSwitchGuardTest extends WP_UnitTestCase {

	/**
	 * Leaks captured through the repair action.
	 *
	 * @var array<int,array>
	 */
	private $leaks = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/inc/core/blog-switch-guard.php';
	}

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'The blog switch guard only applies to multisite.' );
		}

		$this->leaks = array();
		unset( $GLOBALS['ec_network_last_outbound_switch'] );
		add_filter( 'ec_network_log_blog_switch_leak', '__return_false' );
		add_action( 'ec_network_blog_switch_leak_repaired', array( $this, 'capture_leak' ) );
	}

	public function tear_down(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		remove_filter( 'ec_network_log_blog_switch_leak', '__return_false' );
		remove_action( 'ec_network_blog_switch_leak_repaired', array( $this, 'capture_leak' ) );
		parent::tear_down();
	}

	/**
	 * @param array $leak Leak details.
	 */
	public function capture_leak( $leak ): void {
		$this->leaks[] = $leak;
	}

	/**
	 * A different blog ID. Switching only needs a different blog context, not a
	 * provisioned site (the managed test runtime cannot create sites).
	 *
	 * @param int $offset Offset from the request blog.
	 */
	private function other_blog( int $offset ): int {
		return ec_network_request_blog_id() + $offset;
	}

	/**
	 * Simulates the #1512 leak: a helper switches to another site and returns
	 * early without restoring.
	 *
	 * @param int $blog_id Blog to leak into.
	 */
	private function leak_switch_without_restore( int $blog_id ): void {
		switch_to_blog( $blog_id );
	}

	public function test_hooks_are_registered_at_template_boundaries(): void {
		$this->assertSame( PHP_INT_MAX, has_filter( 'template_include', 'ec_network_guard_blog_switch_before_template' ) );
		$this->assertSame( PHP_INT_MIN, has_action( 'extrachill_before_body_content', 'ec_network_guard_blog_switch_before_body_content' ) );
		$this->assertSame( 10, has_action( 'switch_blog', 'ec_network_note_blog_switch' ) );
	}

	public function test_balanced_request_is_left_alone(): void {
		$request_blog = get_current_blog_id();
		$other_blog   = $this->other_blog( 1 );

		switch_to_blog( $other_blog );
		restore_current_blog();

		$this->assertNull( ec_network_restore_leaked_blog_switch( 'test' ) );
		$this->assertSame( $request_blog, get_current_blog_id() );
		$this->assertSame( array(), $this->leaks );
	}

	public function test_template_include_restores_request_blog_after_leaked_switch(): void {
		$request_blog = get_current_blog_id();
		$docs_blog    = $this->other_blog( 9 );

		$this->leak_switch_without_restore( $docs_blog );
		$this->assertSame( $docs_blog, get_current_blog_id(), 'Precondition: the switch leaked.' );

		$template = apply_filters( 'template_include', '/theme/404.php' );

		$this->assertSame( '/theme/404.php', $template, 'The guard must not change the template.' );
		$this->assertSame( $request_blog, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );

		$this->assertCount( 1, $this->leaks );
		$this->assertSame( $docs_blog, $this->leaks[0]['leaked_blog_id'] );
		$this->assertSame( $request_blog, $this->leaks[0]['request_blog_id'] );
		$this->assertSame( 1, $this->leaks[0]['depth'] );
		$this->assertSame( 'template_include', $this->leaks[0]['surface'] );
		$this->assertStringContainsString( 'leak_switch_without_restore', $this->leaks[0]['caller'], 'The leak must name the code that switched.' );
	}

	public function test_body_content_boundary_unwinds_nested_leaks(): void {
		$request_blog = get_current_blog_id();
		$first        = $this->other_blog( 1 );
		$second       = $this->other_blog( 2 );

		switch_to_blog( $first );
		switch_to_blog( $second );

		do_action( 'extrachill_before_body_content' );

		$this->assertSame( $request_blog, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
		$this->assertCount( 1, $this->leaks );
		$this->assertSame( $second, $this->leaks[0]['leaked_blog_id'] );
		$this->assertSame( 2, $this->leaks[0]['depth'] );
	}

	public function test_out_of_order_restore_with_empty_stack_is_repaired(): void {
		$request_blog = get_current_blog_id();
		$other_blog   = $this->other_blog( 1 );

		// A stray extra restore elsewhere pops the request blog's entry, leaving
		// the wrong blog current with nothing left on the stack.
		switch_to_blog( $other_blog );
		$GLOBALS['_wp_switched_stack'] = array();
		$GLOBALS['switched']           = false;
		$this->assertSame( $other_blog, get_current_blog_id(), 'Precondition: wrong blog with an empty stack.' );

		$leak = ec_network_restore_leaked_blog_switch( 'test' );

		$this->assertIsArray( $leak );
		$this->assertSame( $request_blog, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_only_outbound_switches_from_request_blog_are_recorded(): void {
		$first  = $this->other_blog( 1 );
		$second = $this->other_blog( 2 );

		switch_to_blog( $first );
		$recorded = $GLOBALS['ec_network_last_outbound_switch'] ?? array();
		switch_to_blog( $second ); // Nested: not an outbound switch from the request blog.
		restore_current_blog();
		restore_current_blog();

		$this->assertSame( $first, $GLOBALS['ec_network_last_outbound_switch']['blog_id'] );
		$this->assertSame( $recorded, $GLOBALS['ec_network_last_outbound_switch'] );
		$this->assertStringContainsString( __FUNCTION__, $recorded['caller'] );
	}
}
