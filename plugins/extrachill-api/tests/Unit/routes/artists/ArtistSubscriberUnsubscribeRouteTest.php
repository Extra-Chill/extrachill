<?php
/**
 * Tests for the artist mailing list unsubscribe adapters (#1568).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the signed-link HTML transport and member self-service adapters. */
class ArtistSubscriberUnsubscribeRouteTest extends WP_UnitTestCase {

	/** @var array<string, array[]> */
	private $ability_inputs = array();

	/** @var array<string, mixed> */
	private $ability_results = array();

	/** @var array<string, array> Input schemas mirroring extrachill-artist-platform. */
	private $schemas = array();

	/** Register controlled owner abilities with the owner's input schemas. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs  = array();
		$this->ability_results = array(
			'extrachill/artist-subscriber-unsubscribe' => array(
				'status'  => 'confirm',
				'title'   => 'Unsubscribe from Futurebirds?',
				'message' => 'Only this artist.',
				'html'    => '<!DOCTYPE html><html><body><form method="post"></form></body></html>',
			),
			'extrachill/list-my-artist-subscriptions'  => array( 'subscriptions' => array() ),
			'extrachill/remove-my-artist-subscription' => array(
				'removed'       => true,
				'subscriber_id' => 9,
			),
		);
		$this->schemas         = array(
			'extrachill/artist-subscriber-unsubscribe' => array(
				'type'                 => 'object',
				'properties'           => array(
					'token'   => array( 'type' => 'string' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'extrachill/list-my-artist-subscriptions'  => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'extrachill/remove-my-artist-subscription' => array(
				'type'                 => 'object',
				'required'             => array( 'subscriber_id' ),
				'properties'           => array(
					'subscriber_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
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

		foreach ( $this->schemas as $name => $schema ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
			WP_Abilities_Registry::get_instance()->register(
				$name,
				array(
					'label'               => 'Test ' . $name,
					'description'         => 'Controlled artist unsubscribe ability.',
					'category'            => 'extrachill-api-tests',
					'input_schema'        => $schema,
					'execute_callback'    => function ( $input = array() ) use ( $name ) {
						$this->ability_inputs[ $name ][] = $input;
						return $this->ability_results[ $name ];
					},
					'permission_callback' => '__return_true',
				)
			);
		}

		$GLOBALS['wp_rest_server'] = null;
	}

	/** Remove the controlled abilities. */
	public function tear_down() {
		foreach ( array_keys( $this->schemas ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** The signed link route is public for GET and POST and accepts only the token. */
	public function test_link_route_is_public_for_get_and_post() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/extrachill/v1/artists/subscribers/unsubscribe', $routes );

		$methods = array();
		foreach ( $routes['/extrachill/v1/artists/subscribers/unsubscribe'] as $endpoint ) {
			$methods = array_merge( $methods, array_keys( array_filter( $endpoint['methods'] ) ) );
			$this->assertSame( '__return_true', $endpoint['permission_callback'] );
			$this->assertSame( array( 'token' ), array_keys( $endpoint['args'] ) );
		}
		sort( $methods );
		$this->assertSame( array( 'GET', 'POST' ), $methods );
	}

	/** Every route runs on the artist site, where the abilities are registered. */
	public function test_routes_have_artist_affinity() {
		$this->assertSame( 'artist', extrachill_api_rest_ability_route_site( '/extrachill/v1/artists/subscribers/unsubscribe' ) );
		$this->assertSame( 'artist', extrachill_api_rest_ability_route_site( '/extrachill/v1/artists/me/subscriptions' ) );
		$this->assertSame( 'artist', extrachill_api_rest_ability_route_site( '/extrachill/v1/artists/me/subscriptions/(?P<subscriber_id>\d+)' ) );
	}

	/** GET asks for confirmation and serves the document as uncached HTML. */
	public function test_get_requests_confirmation_as_html() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/artists/subscribers/unsubscribe' );
		$request->set_param( 'token', 'signed.token' );

		$response = extrachill_api_handle_artist_unsubscribe( $request );

		$this->assertSame(
			array(
				'confirm' => false,
				'token'   => 'signed.token',
			),
			$this->ability_inputs['extrachill/artist-subscriber-unsubscribe'][0]
		);
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $this->ability_results['extrachill/artist-subscriber-unsubscribe']['html'], $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 'text/html; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertArrayHasKey( 'Cache-Control', $headers );
	}

	/** POST confirms the unsubscribe. */
	public function test_post_confirms() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/artists/subscribers/unsubscribe' );
		$request->set_param( 'token', 'signed.token' );

		extrachill_api_handle_artist_unsubscribe( $request );

		$this->assertTrue( $this->ability_inputs['extrachill/artist-subscriber-unsubscribe'][0]['confirm'] );
	}

	/** The serve filter prints raw HTML for this route only. */
	public function test_serve_filter_prints_html_only_for_this_route() {
		$request  = new WP_REST_Request( 'GET', '/extrachill/v1/artists/subscribers/unsubscribe' );
		$response = new WP_REST_Response( '<p>page</p>', 200 );

		ob_start();
		$served = extrachill_api_serve_artist_unsubscribe_html( false, $response, $request, null );
		$output = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( '<p>page</p>', $output );

		$other = new WP_REST_Request( 'GET', '/extrachill/v1/artists/me/subscriptions' );
		$this->assertFalse( extrachill_api_serve_artist_unsubscribe_html( false, $response, $other, null ) );
	}

	/** Member routes require sign-in and forward to the owner abilities. */
	public function test_member_routes_forward_to_owner_abilities() {
		$routes = rest_get_server()->get_routes();
		$this->assertSame( 'is_user_logged_in', $routes['/extrachill/v1/artists/me/subscriptions'][0]['permission_callback'] );
		$this->assertSame( 'is_user_logged_in', $routes['/extrachill/v1/artists/me/subscriptions/(?P<subscriber_id>\d+)'][0]['permission_callback'] );

		$list = extrachill_api_list_my_artist_subscriptions( new WP_REST_Request( 'GET', '/extrachill/v1/artists/me/subscriptions' ) );
		$this->assertSame( array( 'subscriptions' => array() ), $list->get_data() );

		$request = new WP_REST_Request( 'DELETE', '/extrachill/v1/artists/me/subscriptions/9' );
		$request->set_param( 'subscriber_id', 9 );
		$removed = extrachill_api_remove_my_artist_subscription( $request );
		$this->assertSame( array( 'subscriber_id' => 9 ), $this->ability_inputs['extrachill/remove-my-artist-subscription'][0] );
		$this->assertTrue( $removed->get_data()['removed'] );
	}

	/** Owner errors (e.g. someone else's subscription) pass through unchanged. */
	public function test_remove_preserves_owner_error() {
		$error = new WP_Error( 'artist_subscription_not_found', 'Not found.', array( 'status' => 404 ) );
		$this->ability_results['extrachill/remove-my-artist-subscription'] = $error;
		$request = new WP_REST_Request( 'DELETE', '/extrachill/v1/artists/me/subscriptions/3' );
		$request->set_param( 'subscriber_id', 3 );

		$this->assertSame( $error, extrachill_api_remove_my_artist_subscription( $request ) );
	}

	/** Every adapter is in schema parity with its owner ability apart from documented exceptions. */
	public function test_adapters_are_in_schema_parity() {
		$manifest = array_values(
			array_filter(
				extrachill_api_rest_ability_adapter_manifest( rest_get_server()->get_routes( 'extrachill/v1' ) ),
				fn( $adapter ) => isset( $this->schemas[ $adapter['contract']['ability'] ] )
			)
		);

		$this->assertCount( 4, $manifest );
		foreach ( $manifest as $adapter ) {
			$this->assertSame( 'artist', $adapter['contract']['site'] );
			$findings = extrachill_api_rest_ability_schema_findings( $adapter['endpoint'], wp_get_ability( $adapter['contract']['ability'] ), $adapter['contract'] );
			$this->assertSame( array(), $findings, $adapter['contract']['route'] . ' drifted from ' . $adapter['contract']['ability'] );
		}
	}
}
