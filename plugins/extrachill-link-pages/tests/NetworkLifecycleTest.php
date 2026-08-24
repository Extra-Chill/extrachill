<?php

use PHPUnit\Framework\TestCase;

final class NetworkLifecycleTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_network_activation_and_deactivation_flush_every_site_once_in_bounded_pages(): void {
		for ( $site_id = 1; $site_id <= 205; ++$site_id ) {
			$GLOBALS['ec_test']['blogs'][ $site_id ] = array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() );
		}
		$GLOBALS['ec_test']['current_blog_id'] = 4;

		$this->assertTrue( ec_prepare_link_pages_activation( true ) );
		$this->assertCount( 205, $GLOBALS['ec_test']['site_flushes'] );
		$this->assertSame( array( 100, 100, 100 ), array_map( static function ( $query ) { return $query['number']; }, $GLOBALS['ec_test']['site_queries'] ) );
		$this->assertSame( array( 0, 100, 200 ), array_map( static function ( $query ) { return $query['offset']; }, $GLOBALS['ec_test']['site_queries'] ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );

		$GLOBALS['ec_test']['site_queries'] = array();
		$GLOBALS['ec_test']['site_flushes'] = array();
		$GLOBALS['ec_test']['site_rule_snapshots'] = array();
		ec_deactivate_link_pages( true );
		$this->assertCount( 205, $GLOBALS['ec_test']['site_flushes'] );
		$this->assertSame( array( 1 => 1 ), $GLOBALS['ec_test']['unregister_calls'] );
		$this->assertFalse( post_type_exists( EC_LINK_PAGE_POST_TYPE ) );
		foreach ( $GLOBALS['ec_test']['site_rule_snapshots'] as $snapshots ) {
			$this->assertSame( array( false ), $snapshots );
		}
		$this->assertSame( array( 0, 100, 200 ), array_map( static function ( $query ) { return $query['offset']; }, $GLOBALS['ec_test']['site_queries'] ) );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_standalone_deactivation_unregisters_before_its_single_flush(): void {
		$this->assertTrue( ec_prepare_link_pages_activation( false ) );
		$GLOBALS['ec_test']['site_flushes'] = array();
		$GLOBALS['ec_test']['site_rule_snapshots'] = array();

		ec_deactivate_link_pages( false );

		$this->assertSame( array( 4 => 1 ), $GLOBALS['ec_test']['unregister_calls'] );
		$this->assertSame( array( 4 => 1 ), $GLOBALS['ec_test']['site_flushes'] );
		$this->assertSame( array( 4 => array( false ) ), $GLOBALS['ec_test']['site_rule_snapshots'] );
		$this->assertFalse( post_type_exists( EC_LINK_PAGE_POST_TYPE ) );
	}

	public function test_deactivation_reports_unregistration_failure_without_flushing(): void {
		$this->assertTrue( ec_prepare_link_pages_activation( false ) );
		$GLOBALS['ec_test']['site_flushes'] = array();
		$GLOBALS['ec_test']['fail_unregister'] = true;

		$result = ec_unregister_and_flush_link_pages_site();

		$this->assertSame( 'ec_link_pages_post_type_unregistration_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['site_flushes'] );
	}

	public function test_network_failure_is_explicit_and_restores_nested_entry_context(): void {
		$GLOBALS['ec_test']['throw_flush_on_blog'] = 7;
		switch_to_blog( 7 );
		switch_to_blog( 4 );

		$result = ec_prepare_link_pages_activation( true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ec_link_pages_site_callback_failed', $result->get_error_code() );
		$this->assertSame( array( 4 => 1 ), $GLOBALS['ec_test']['site_flushes'] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array( 4, 7 ), $GLOBALS['_wp_switched_stack'] );
		$this->assertTrue( $GLOBALS['switched'] );
		restore_current_blog();
		restore_current_blog();
	}

	public function test_activation_hook_terminates_on_observable_network_failure(): void {
		$GLOBALS['ec_test']['fail_switch_to_blog'] = 7;

		try {
			ec_activate_link_pages( true );
			$this->fail( 'Activation should terminate.' );
		} catch ( EcTestActivationException $exception ) {
			$this->assertStringContainsString( 'could not enter a site context', $exception->getMessage() );
		}

		$this->assertSame( 'ec_link_pages_site_switch_failed', $GLOBALS['ec_link_pages_runtime_error']->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_activation_fails_instead_of_deferring_flush_outside_site_context(): void {
		$GLOBALS['ec_test']['did_actions']['wp_loaded'] = 0;
		$result = ec_prepare_link_pages_activation( false );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ec_link_pages_rewrite_flush_too_early', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['site_flushes'] ?? array() );
	}

	public function test_new_site_is_flushed_only_while_network_active(): void {
		$GLOBALS['ec_test']['blogs'][8] = array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() );
		$site = (object) array( 'id' => 8, 'blog_id' => 8 );

		ec_initialize_link_pages_site( $site );
		$this->assertArrayNotHasKey( 8, $GLOBALS['ec_test']['site_flushes'] ?? array() );

		$GLOBALS['ec_test']['site_options']['active_sitewide_plugins'] = array( EXTRACHILL_LINK_PAGES_PLUGIN_BASENAME => time() );
		ec_initialize_link_pages_site( $site );
		$this->assertSame( 1, $GLOBALS['ec_test']['site_flushes'][8] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_new_site_initialized_before_wp_loaded_is_flushed_later_in_its_own_context(): void {
		$GLOBALS['ec_test']['blogs'][8] = array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() );
		$GLOBALS['ec_test']['site_options']['active_sitewide_plugins'] = array( EXTRACHILL_LINK_PAGES_PLUGIN_BASENAME => time() );
		$GLOBALS['ec_test']['did_actions']['wp_loaded'] = 0;

		ec_initialize_link_pages_site( (object) array( 'id' => 8 ) );
		$this->assertArrayNotHasKey( 8, $GLOBALS['ec_test']['site_flushes'] ?? array() );
		$this->assertSame( array( 8 => 8 ), $GLOBALS['ec_link_pages_queued_site_flushes'] );

		$GLOBALS['ec_test']['did_actions']['wp_loaded'] = 1;
		$this->assertTrue( ec_flush_queued_link_pages_sites() );
		$this->assertSame( 1, $GLOBALS['ec_test']['site_flushes'][8] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}
}
