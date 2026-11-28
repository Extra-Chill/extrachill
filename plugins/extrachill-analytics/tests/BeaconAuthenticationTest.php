<?php
/**
 * Cookie-auth attribution and anonymous fallback for browser beacons.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/** Exercise actual localized configs, Core authentication and stored events. */
final class BeaconAuthenticationTest extends Extrachill_Analytics_TestCase {
	/** Logged-in markup has nonces on every collector; anonymous markup has none. */
	public function test_rendered_configs_only_include_nonces_for_logged_in_users(): void {
		$host = ltrim( extrachill_analytics_visitor_cookie_domain(), '.' );
		$this->set_request( $host ? $host : wp_parse_url( home_url(), PHP_URL_HOST ), 'GET' );
		$_SERVER['REQUEST_URI'] = '/events/';
		foreach ( array( 0, self::factory()->user->create() ) as $user_id ) {
			wp_set_current_user( $user_id );
			wp_scripts()->remove( 'extrachill-view-tracking' );
			wp_scripts()->remove( 'extrachill-outbound-tracking' );
			extrachill_analytics_enqueue_view_tracking();
			extrachill_analytics_enqueue_outbound_tracking();
			$view = wp_scripts()->get_data( 'extrachill-view-tracking', 'before' );
			$this->assertNotEmpty( $view );
			$prefix = 'window.ecViewTracking = ';
			$inline = current( array_filter( (array) $view, static fn( $script ) => is_string( $script ) && 0 === strpos( $script, $prefix ) ) );
			$this->assertIsString( $inline );
			$config = json_decode( substr( $inline, strlen( $prefix ), -1 ), true );
			$this->assertIsArray( $config );
			$outbound = wp_scripts()->get_data( 'extrachill-outbound-tracking', 'data' );
			$click = json_decode( substr( $outbound, strlen( 'var ecOutboundTracking = ' ), -1 ), true );
			$this->assertIsArray( $click );
			$urls = array(
				'view.endpoint'            => $config['endpoint'] ?? null,
				'outbound.endpoint'        => $click['endpoint'] ?? null,
				'outbound.networkEndpoint' => $click['networkEndpoint'] ?? null,
				'outbound.ctaEndpoint'     => $click['ctaEndpoint'] ?? null,
				'outbound.formEndpoint'    => $click['formEndpoint'] ?? null,
			);
			foreach ( $urls as $name => $url ) {
				$this->assertIsString( $url, $name . ' missing; keys: ' . implode( ',', array_keys( 'view.endpoint' === $name ? $config : $click ) ) );
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
				if ( $user_id ) {
					$this->assertArrayHasKey( '_wpnonce', $args, $name . ': ' . $url );
					$this->assertNotFalse( wp_verify_nonce( $args['_wpnonce'], 'wp_rest' ), 'Nonce did not verify for ' . $url );
				} else {
					$this->assertArrayNotHasKey( '_wpnonce', $args );
				}
			}
		}
	}

	/** Core's cookie nonce check runs before dispatch; invalid nonces lose identity only. */
	public function test_cookie_authentication_records_valid_user_and_anonymous_fallback(): void {
		global $wp, $wpdb, $wp_rest_auth_cookie;
		$user_id = self::factory()->user->create();
		$route = '/wp-abilities/v1/abilities/extrachill/track-page-view/run';
		$old_route = $wp->query_vars['rest_route'] ?? null;
		$old_request = $_REQUEST;
		$old_cookie_auth = $wp_rest_auth_cookie;
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$this->set_ext_object_cache( true );
		try {
			foreach ( array( 'valid', 'invalid', 'missing' ) as $mode ) {
				wp_set_current_user( $user_id );
				$wp_rest_auth_cookie = true;
				$wp->query_vars['rest_route'] = $route;
				unset( $_REQUEST['_wpnonce'] );
				if ( 'missing' !== $mode ) {
					$_REQUEST['_wpnonce'] = 'valid' === $mode ? wp_create_nonce( 'wp_rest' ) : 'expired-nonce';
				}
				$this->assertNotWPError( rest_get_server()->check_authentication() );
				$request = new WP_REST_Request( 'POST', $route );
				$request->set_param( 'input', array(
					'source_path' => '/events/',
					'route_family' => 'directory',
					'proof' => extrachill_analytics_pageview_proof( 0, '/events/', 'directory', extrachill_analytics_public_write_source_host() ),
				) );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'input' => $request->get_param( 'input' ) ) ) );
				$response = rest_do_request( $request );
				$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
				$this->assertSame( array( 'recorded' => true ), $response->get_data() );
				$stored_user = $wpdb->get_var( 'SELECT user_id FROM ' . extrachill_analytics_events_table() . ' ORDER BY id DESC LIMIT 1' );
				if ( 'valid' === $mode ) {
					$this->assertSame( $user_id, (int) $stored_user );
				} else {
					$this->assertNull( $stored_user );
				}
			}
		} finally {
			$_REQUEST = $old_request;
			$wp_rest_auth_cookie = $old_cookie_auth;
			if ( null === $old_route ) {
				unset( $wp->query_vars['rest_route'] );
			} else {
				$wp->query_vars['rest_route'] = $old_route;
			}
		}
	}

	/** Protected routes, non-POST requests and other auth failures stay rejected. */
	public function test_fallback_is_limited_to_public_beacon_nonce_errors(): void {
		global $wp;
		$old_route = $wp->query_vars['rest_route'] ?? null;
		$error = new WP_Error( 'rest_cookie_invalid_nonce', 'Invalid nonce', array( 'status' => 403 ) );
		try {
			$this->set_request( 'example.org', 'POST' );
			$wp->query_vars['rest_route'] = '/wp/v2/users/me';
			$this->assertSame( $error, extrachill_analytics_beacon_nonce_fallback( $error ) );
			$wp->query_vars['rest_route'] = '/extrachill/v1/analytics/click';
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$this->assertSame( $error, extrachill_analytics_beacon_nonce_fallback( $error ) );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$other = new WP_Error( 'other_auth_failure', 'Denied' );
			$this->assertSame( $other, extrachill_analytics_beacon_nonce_fallback( $other ) );
			wp_set_current_user( self::factory()->user->create() );
			$this->assertTrue( extrachill_analytics_beacon_nonce_fallback( $error ) );
			$this->assertSame( 0, get_current_user_id() );
		} finally {
			if ( null === $old_route ) {
				unset( $wp->query_vars['rest_route'] );
			} else {
				$wp->query_vars['rest_route'] = $old_route;
			}
		}
	}
}
