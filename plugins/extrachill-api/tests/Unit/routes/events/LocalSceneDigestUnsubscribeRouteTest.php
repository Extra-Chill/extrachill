<?php
/**
 * Tests for the signed Local Scene digest unsubscribe adapter (#1426).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the HTML-serving unsubscribe transport over a controlled ability. */
class LocalSceneDigestUnsubscribeRouteTest extends WP_UnitTestCase {

	/** @var array[] */
	private $ability_inputs = array();

	/** @var array|WP_Error */
	private $ability_result = array();

	/** Register a controlled unsubscribe ability. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs = array();
		$this->ability_result = array(
			'status'      => 'confirm',
			'title'       => 'Unsubscribe?',
			'message'     => 'Only this scene.',
			'action_html' => '<form method="post"></form>',
			'html'        => '<!DOCTYPE html><html><body><form method="post"></form></body></html>',
		);

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		if ( wp_has_ability( 'extrachill/unsubscribe-local-scene-digest' ) ) {
			wp_unregister_ability( 'extrachill/unsubscribe-local-scene-digest' );
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/unsubscribe-local-scene-digest',
			array(
				'label'               => 'Test digest unsubscribe',
				'description'         => 'Controlled digest unsubscribe ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_unsubscribe' ),
				'permission_callback' => '__return_true',
			)
		);

		// A fresh server fires rest_api_init once, instead of appending
		// duplicate handlers to the shared one on every test.
		$GLOBALS['wp_rest_server'] = null;
	}

	/** Remove the controlled ability. */
	public function tear_down() {
		if ( wp_has_ability( 'extrachill/unsubscribe-local-scene-digest' ) ) {
			wp_unregister_ability( 'extrachill/unsubscribe-local-scene-digest' );
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** GET and POST stay public at the unchanged URL. */
	public function test_route_is_public_for_get_and_post() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/extrachill/v1/local-scene-digest/unsubscribe', $routes );

		$methods = array();
		foreach ( $routes['/extrachill/v1/local-scene-digest/unsubscribe'] as $endpoint ) {
			$methods = array_merge( $methods, array_keys( array_filter( $endpoint['methods'] ) ) );
			$this->assertSame( '__return_true', $endpoint['permission_callback'] );
			$this->assertSame( array( 'token', 'user', 'location', 'expires', 'signature' ), array_keys( $endpoint['args'] ) );
		}
		sort( $methods );
		$this->assertSame( array( 'GET', 'POST' ), $methods );
	}

	/** The route runs on the events site, where the ability is registered. */
	public function test_route_has_events_affinity() {
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/local-scene-digest/unsubscribe' ) );
	}

	/** GET asks for confirmation and serves the document as uncached HTML. */
	public function test_get_requests_confirmation_as_html() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/local-scene-digest/unsubscribe' );
		$request->set_param( 'token', 'signed.token' );

		$response = extrachill_api_handle_local_scene_digest_unsubscribe( $request );

		$this->assertSame(
			array(
				'confirm' => false,
				'token'   => 'signed.token',
			),
			$this->ability_inputs[0]
		);
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->ability_result['html'], $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 'text/html; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertArrayHasKey( 'Cache-Control', $headers );
	}

	/** POST confirms and forwards the legacy signed fields. */
	public function test_post_confirms_with_legacy_fields() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/local-scene-digest/unsubscribe' );
		$request->set_param( 'user', 7 );
		$request->set_param( 'location', 'austin' );
		$request->set_param( 'expires', 1900000000 );
		$request->set_param( 'signature', 'abc' );

		extrachill_api_handle_local_scene_digest_unsubscribe( $request );

		$this->assertSame(
			array(
				'confirm'   => true,
				'user'      => 7,
				'location'  => 'austin',
				'expires'   => 1900000000,
				'signature' => 'abc',
			),
			$this->ability_inputs[0]
		);
	}

	/** The serve filter prints raw HTML for this route only. */
	public function test_serve_filter_prints_html_only_for_this_route() {
		$request  = new WP_REST_Request( 'GET', '/extrachill/v1/local-scene-digest/unsubscribe' );
		$response = new WP_REST_Response( '<p>page</p>', 200 );

		ob_start();
		$served = extrachill_api_serve_local_scene_digest_unsubscribe_html( false, $response, $request, null );
		$output = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( '<p>page</p>', $output );

		$other = new WP_REST_Request( 'GET', '/extrachill/v1/events/calendar' );
		$this->assertFalse( extrachill_api_serve_local_scene_digest_unsubscribe_html( false, $response, $other, null ) );

		$error = new WP_REST_Response( array( 'code' => 'ability_not_found' ), 503 );
		$this->assertFalse( extrachill_api_serve_local_scene_digest_unsubscribe_html( false, $error, $request, null ) );
	}

	/** A missing owner ability is a 503 JSON error, not an empty page. */
	public function test_missing_ability_is_unavailable() {
		wp_unregister_ability( 'extrachill/unsubscribe-local-scene-digest' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$result = extrachill_api_handle_local_scene_digest_unsubscribe( new WP_REST_Request( 'GET', '/extrachill/v1/local-scene-digest/unsubscribe' ) );

		$this->assertWPError( $result );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/** The adapter contract is in exact parity apart from the method-derived flag. */
	public function test_adapter_documents_the_method_derived_confirm_flag() {
		$manifest = array_values(
			array_filter(
				extrachill_api_rest_ability_adapter_manifest( rest_get_server()->get_routes( 'extrachill/v1' ) ),
				static fn( $adapter ) => 'extrachill/unsubscribe-local-scene-digest' === $adapter['contract']['ability']
			)
		);

		$this->assertCount( 2, $manifest );
		foreach ( $manifest as $adapter ) {
			$this->assertSame( 'events', $adapter['contract']['site'] );
			$this->assertArrayHasKey( 'confirm', $adapter['contract']['exceptions']['ability_only'] );
		}
	}

	/** Controlled ability callback. */
	public function execute_unsubscribe( array $input ) {
		$this->ability_inputs[] = $input;
		return $this->ability_result;
	}
}
