<?php
/**
 * Main-site MCP transport redirect tests.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * @group routing
 */
class McpTransportRedirectTest extends WP_UnitTestCase {

	private function endpoint(): string {
		return untrailingslashit( (string) ec_get_site_url( 'auth' ) ) . '/wp-json/extrachill-mcp/v1/mcp';
	}

	public function test_post_mcp_redirect_target_uses_auth_endpoint(): void {
		$this->assertSame(
			$this->endpoint(),
			ec_get_mcp_transport_redirect_url( '/mcp', 'POST', 'application/json' )
		);
	}

	public function test_post_mcp_with_trailing_slash_redirects_to_same_endpoint(): void {
		$this->assertSame(
			$this->endpoint(),
			ec_get_mcp_transport_redirect_url( '/mcp/', 'POST', 'application/json' )
		);
	}

	public function test_event_stream_get_redirects_to_auth_endpoint(): void {
		$this->assertSame(
			$this->endpoint(),
			ec_get_mcp_transport_redirect_url( '/mcp', 'GET', 'text/html, text/event-stream' )
		);
	}

	public function test_browser_get_does_not_redirect(): void {
		$this->assertNull( ec_get_mcp_transport_redirect_url( '/mcp/', 'GET', 'text/html' ) );
		$this->assertNull( ec_get_mcp_transport_redirect_url( '/mcp/', 'GET', '' ) );
	}

	public function test_redirect_handler_is_guarded_to_main_site(): void {
		$this->assertSame( 0, has_action( 'template_redirect', 'ec_redirect_mcp_transport_to_auth' ) );

		$main_site_id = ec_get_blog_id( 'main' );
		$other_site_id = self::factory()->blog->create();
		$this->assertNotSame( (int) $main_site_id, (int) $other_site_id );

		switch_to_blog( $other_site_id );
		try {
			$_SERVER['REQUEST_URI']    = '/mcp';
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$this->assertSame( (int) $other_site_id, get_current_blog_id() );
			// A non-main request must return normally rather than issuing a redirect.
			ec_redirect_mcp_transport_to_auth();
			$this->assertSame( (int) $other_site_id, get_current_blog_id() );
		} finally {
			restore_current_blog();
			unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		}
	}

	public function test_query_string_is_preserved(): void {
		$this->assertSame(
			$this->endpoint() . '?foo=bar&session=abc',
			ec_get_mcp_transport_redirect_url( '/mcp?foo=bar&session=abc', 'POST', 'application/json' )
		);
	}
}
