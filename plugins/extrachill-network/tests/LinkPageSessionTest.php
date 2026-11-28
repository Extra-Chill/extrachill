<?php
/** Link Pages browser session boundaries. */

class LinkPageSessionTest extends WP_UnitTestCase {
	public function test_only_canonical_link_host_gets_host_only_cookies(): void {
		$this->assertTrue( ec_network_link_page_cookie_host( 'ExtraChill.Link:443' ) );
		$this->assertFalse( ec_network_link_page_cookie_host( 'artist.extrachill.com' ) );
		$this->assertFalse( ec_network_link_page_cookie_host( 'extrachill.link.example.org' ) );
		$this->assertFalse( ec_network_link_page_cookie_host( '' ) );
	}

	public function test_handoff_accepts_only_link_host_frontend_destinations(): void {
		foreach ( array( 'https://extrachill.link/', 'https://extrachill.link/my-band', 'https://extrachill.link/edit?ec_link_sso=1&link_page=42' ) as $url ) {
			$this->assertSame( $url, ec_network_link_session_return_url( $url ) );
		}
		foreach ( array( 'http://extrachill.link/edit', 'https://user@extrachill.link/edit', 'https://extrachill.link:444/edit', 'https://extrachill.link.example.org/edit', 'https://extrachill.link/wp-admin/admin-post.php', 'https://extrachill.link/edit?custom_logout=1' ) as $url ) {
			$this->assertSame( '', ec_network_link_session_return_url( $url ) );
		}
	}

	public function test_redemption_requires_the_initiating_browsers_state(): void {
		$state = str_repeat( 'a', 64 );
		$payload = array( 'browser_state_hash' => hash( 'sha256', $state ) );
		$this->assertTrue( ec_network_link_session_matches_state( $payload, $state ) );
		$this->assertFalse( ec_network_link_session_matches_state( $payload, str_repeat( 'b', 64 ) ) );
		$this->assertFalse( ec_network_link_session_matches_state( $payload, '' ) );
		$this->assertFalse( ec_network_link_session_matches_state( array(), $state ) );
	}

	public function test_edit_sign_in_preserves_the_requested_page(): void {
		$url = ec_network_link_page_edit_login_url( '', 42 );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'ec_link_session_start', $query['action'] );
		$this->assertSame( '1', $query['login'] );
		$this->assertSame( rtrim( ec_get_site_url( 'link_pages' ), '/' ) . '/edit?ec_link_sso=1&link_page=42', $query['return'] );
	}
}
