<?php
/**
 * Owner-neutral /edit entry.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class EditEntryTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		unset( $GLOBALS['ec_link_page_edit_request'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_link_page_edit_request'] );
	}

	public function test_edit_path_on_public_host_serves_the_shell(): void {
		$_SERVER['HTTP_HOST']   = 'extrachill.link';
		$_SERVER['REQUEST_URI'] = '/edit';
		$GLOBALS['wp_query']    = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
		ec_resolve_link_page_public_query();
		$this->assertTrue( ec_is_link_page_edit_request() );
		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'templates/edit-shell.php', ec_link_page_public_template( '/theme/index.php' ) );
		$this->assertSame( 'Edit your link page', ec_link_page_edit_document_title( 'x' ) );
		$this->assertTrue( ec_link_page_edit_robots( array() )['noindex'] );
	}

	public function test_other_paths_are_not_the_edit_shell(): void {
		$_SERVER['HTTP_HOST']   = 'extrachill.link';
		$_SERVER['REQUEST_URI'] = '/editor-fan/';
		$GLOBALS['wp_query']    = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
		add_filter( 'ec_link_page_terminate_request', '__return_false' );
		ec_resolve_link_page_public_query();
		$this->assertFalse( ec_is_link_page_edit_request() );
		$this->assertSame( 'x', ec_link_page_edit_document_title( 'x' ) );
	}

	public function test_endpoints_are_host_supplied_and_url_sanitized(): void {
		$this->assertSame( array( 'configuration_url' => '', 'handoff_url' => '', 'login_url' => '' ), ec_get_link_page_edit_endpoints() );
		add_filter( 'ec_link_page_edit_endpoints', static function () { return array( 'configuration_url' => 'https://host.example/wp-json/x', 'login_url' => 'javascript:alert(1)' ); } );
		$endpoints = ec_get_link_page_edit_endpoints();
		$this->assertSame( 'https://host.example/wp-json/x', $endpoints['configuration_url'] );
		$this->assertSame( '', $endpoints['login_url'] );
	}

	public function test_configuration_is_unavailable_without_an_owner_answer(): void {
		$this->assertSame( array( 'available' => false ), ec_get_link_page_editor_configuration_for_user( array() ) );
	}

	public function test_configuration_passes_the_requested_page_and_keeps_only_valid_scripts(): void {
		$seen = null;
		add_filter(
			'ec_link_page_editor_configuration',
			static function ( $configuration, $attributes ) use ( &$seen ) {
				$seen = $attributes;
				return array(
					'adapter'    => 'owner',
					'identities' => array( array( 'id' => 1 ) ),
					'scripts'    => array( array( 'src' => 'https://host.example/adapter.js' ), array( 'src' => 'javascript:alert(1)' ), 'bad' ),
				);
			},
			10,
			2
		);
		$configuration = ec_get_link_page_editor_configuration_for_user( array( 'link_page_id' => 40 ) );
		$this->assertSame( array( 'link_page_id' => 40 ), $seen );
		$this->assertTrue( $configuration['available'] );
		$this->assertSame( array( array( 'src' => 'https://host.example/adapter.js' ) ), $configuration['scripts'] );
		$this->assertNotEmpty( $configuration['apiRoot'] );
	}
}
