<?php
/**
 * Tests for the public analytics write boundary.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-platform-contract-fixture.php';

/**
 * Verify public writes carry consistent first-party evidence and stay bounded.
 */
final class PublicWriteIntegrityTest extends Extrachill_Analytics_TestCase {
	/**
	 * Callback answering the pageview public-host filter, unwound in tear_down.
	 *
	 * @var callable|null
	 */
	private $custom_host_filter;

	/**
	 * Establish a normal browser request.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'GET' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
		$_COOKIE['ec_vid']          = '123e4567-e89b-42d3-a456-426614174000';
		$GLOBALS['extrachill_analytics_test_blog_slugs'] = array(
			1 => 'main',
			4 => 'artist',
			7 => 'events',
		);

		extrachill_analytics_link_page_create_table();
	}

	/**
	 * Unwind the host-filter fixture and reset the daily views table.
	 */
	public function tear_down(): void {
		if ( null !== $this->custom_host_filter ) {
			remove_filter( 'extrachill_analytics_pageview_origin_host_allowed', $this->custom_host_filter );
			$this->custom_host_filter = null;
		}

		global $wpdb;
		$views = extrachill_analytics_link_page_views_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views ) ) === $views ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table existence probe before plugin-owned cleanup.
			$wpdb->query( "DELETE FROM {$views}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		}

		parent::tear_down();
	}

	/**
	 * Simulate a custom-public-host runtime answering the Analytics-owned filter.
	 *
	 * Mirrors what a link-page runtime does with its public-host check: answer
	 * for the hosts it owns, post-backed views only.
	 *
	 * @param array<int,string> $hosts Public hosts the fixture runtime owns.
	 */
	private function answer_custom_public_host( array $hosts ): void {
		$callback                 = static function ( $allowed, $host, $post_id ) use ( $hosts ) {
			if ( $allowed || $post_id <= 0 ) {
				return $allowed;
			}
			return in_array( $host, $hosts, true );
		};
		$this->custom_host_filter = $callback;
		add_filter( 'extrachill_analytics_pageview_origin_host_allowed', $callback, 10, 3 );
	}

	/**
	 * Create a published artist link page.
	 *
	 * @param string $slug Link page slug.
	 * @return int Post ID.
	 */
	private function create_link_page( string $slug ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_name'   => $slug,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * A custom-public-host link page view is accepted and recorded end to end.
	 */
	public function test_custom_domain_link_page_view_is_accepted(): void {
		$this->answer_custom_public_host( array( 'extrachill.link' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://extrachill.link';
		$post_id                = $this->create_link_page( 'sarah-summer' );
		$proof                  = extrachill_analytics_pageview_proof( $post_id, '/sarah-summer/', 'singular', 'extrachill.link' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/sarah-summer/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( 1, (int) get_post_meta( $post_id, 'ec_post_views', true ) );

		global $wpdb;
		$this->assertSame(
			1,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned daily table assertion.
				$wpdb->prepare(
					'SELECT view_count FROM ' . extrachill_analytics_link_page_views_table() . ' WHERE link_page_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is code-defined; values bound via prepare().
					$post_id
				)
			)
		);
	}

	/**
	 * A forged proof is still refused once the custom public host is accepted.
	 */
	public function test_custom_domain_view_rejects_forged_proof(): void {
		$this->answer_custom_public_host( array( 'extrachill.link' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://extrachill.link';
		$post_id                = $this->create_link_page( 'sarah-summer' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/sarah-summer/',
				'route_family' => 'singular',
				'proof'        => str_repeat( 'a', 64 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_proof', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data( 'invalid_pageview_proof' )['status'] );
		$this->assertSame( 0, (int) get_post_meta( $post_id, 'ec_post_views', true ) );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * A proof bound to a different host or path cannot ride a widened origin gate.
	 */
	public function test_custom_domain_view_rejects_mismatched_proof(): void {
		$this->answer_custom_public_host( array( 'extrachill.link' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://extrachill.link';
		$post_id                = $this->create_link_page( 'sarah-summer' );
		$proof                  = extrachill_analytics_pageview_proof( $post_id, '/sarah-summer/', 'singular', 'artist.extrachill.com' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/sarah-summer/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_proof', $result->get_error_code() );
		$this->assertSame( 0, (int) get_post_meta( $post_id, 'ec_post_views', true ) );
	}

	/**
	 * A genuinely unrelated host is refused even with a well-formed proof.
	 */
	public function test_unrelated_custom_host_is_rejected(): void {
		$this->answer_custom_public_host( array( 'extrachill.link' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
		$post_id                = $this->create_link_page( 'sarah-summer' );
		$proof                  = extrachill_analytics_pageview_proof( $post_id, '/sarah-summer/', 'singular', 'attacker.example' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/sarah-summer/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_origin', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data( 'invalid_pageview_origin' )['status'] );
		$this->assertSame( 0, (int) get_post_meta( $post_id, 'ec_post_views', true ) );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * Current-site post-backed views are unaffected by the widened gate.
	 */
	public function test_current_site_post_view_remains_accepted(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'band',
				'post_status' => 'publish',
			)
		);
		$proof   = extrachill_analytics_pageview_proof( $post_id, '/band/', 'singular', 'localhost' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/band/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( 1, (int) get_post_meta( $post_id, 'ec_post_views', true ) );
	}

	/**
	 * A mapped custom-domain render can submit its exact signed post tuple.
	 */
	public function test_mapped_custom_domain_post_view_is_accepted(): void {
		$GLOBALS['extrachill_analytics_test_domain_map'] = array( 'extrachill.link' => 1 );
		$_SERVER['HTTP_ORIGIN']                          = 'https://extrachill.link';
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'band',
				'post_status' => 'publish',
			)
		);
		$proof   = extrachill_analytics_pageview_proof( $post_id, '/band/', 'singular', 'extrachill.link' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => $post_id,
				'source_path'  => '/band/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( 1, (int) get_post_meta( $post_id, 'ec_post_views', true ) );
		$this->assertSame( 1, $this->event_count() );
		// Current behavior: extrachill_track_analytics_event() falls back to the
		// ec_vid cookie for the legacy counter path, so the mapped-domain view is
		// stitched to the first-party cookie id despite the anonymous intent.
		// Tracked upstream: extrachill-analytics#264.
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174000', $this->event_rows()[0]->visitor_id );
	}

	/**
	 * The custom template lifecycle enqueues the signed tracker it persists.
	 */
	public function test_custom_domain_template_uses_signed_tracker(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$assets = file_get_contents( dirname( __DIR__ ) . '/inc/core/assets.php' );

		$this->assertNotFalse( $assets );
		$this->assertStringContainsString(
			"add_action( 'extrachill_artist_link_page_minimal_head', 'extrachill_analytics_enqueue_view_tracking', 20 )",
			$assets
		);
	}

	/**
	 * The unreliable cross-origin legacy adapter is explicitly rejected.
	 */
	public function test_legacy_mapped_domain_view_without_source_proof_is_rejected(): void {
		$GLOBALS['extrachill_analytics_test_domain_map'] = array( 'extrachill.link' => 1 );
		$_SERVER['HTTP_ORIGIN']                          = 'https://extrachill.link';
		$_SERVER['HTTP_REFERER']                         = 'https://extrachill.link/';

		$result = extrachill_analytics_ability_track_page_view( array( 'post_id' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_route', $result->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * Cached pre-v0.36.2 route payloads retain their zero post sentinel.
	 */
	public function test_cached_route_view_zero_post_sentinel_is_accepted(): void {
		$ability = wp_get_ability( 'extrachill/track-page-view' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$schema = $ability->get_input_schema();
		$this->assertSame( 0, $schema['properties']['post_id']['minimum'] );

		$proof = extrachill_analytics_pageview_proof( 0, '/', 'home', 'localhost' );
		$this->assertSame(
			array( 'recorded' => true ),
			extrachill_analytics_ability_track_page_view(
				array(
					'post_id'      => '0',
					'source_path'  => '/',
					'route_family' => 'home',
					'proof'        => $proof,
				)
			)
		);
	}

	/**
	 * A proof cannot be moved to another post, path, host, or route family.
	 */
	public function test_pageview_proof_rejects_changed_source_tuple(): void {
		$proof  = extrachill_analytics_pageview_proof( 0, '/events/', 'directory', 'localhost' );
		$result = extrachill_analytics_validate_pageview_write( 99, '/fake/', 'singular', $proof );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_proof', $result->get_error_code() );
	}

	/**
	 * Headerless clients cannot present public browser events as first-party.
	 */
	public function test_public_event_requires_current_site_browser_origin(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		$result = extrachill_analytics_validate_public_event_write( 'outbound_click', array(), '/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_origin', $result->get_error_code() );
	}

	/**
	 * Absolute source claims must agree with the browser origin.
	 */
	public function test_public_event_rejects_source_host_mismatch(): void {
		$result = extrachill_analytics_validate_public_event_write( 'bridge_click', array(), 'https://attacker.example/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_source', $result->get_error_code() );
	}

	/**
	 * Public event dimensions are checked and source URLs become query-free paths.
	 */
	public function test_public_event_normalizes_consistent_network_dimensions(): void {
		$GLOBALS['extrachill_analytics_test_blog_slugs'] = array(
			1 => 'main',
			7 => 'events',
		);
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_impression',
			array(
				'source_site' => 'main',
				'dest_site'   => 'events',
			),
			'https://localhost/story/?email=fixture%40example.test#form'
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/story/', $result['source_url'] );
	}

	/**
	 * The public event boundary minimizes source and destination URLs.
	 */
	public function test_outbound_event_urls_are_minimized_server_side(): void {
		$result = extrachill_analytics_ability_track_event(
			array(
				'event_type' => EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
				'event_data' => array(
					'dest_host' => 'tickets.example',
					'dest_url'  => 'https://tickets.example/show/?token=fixture#checkout',
					'category'  => 'ticketing',
				),
				'source_url' => 'https://localhost/login/?redirect_to=%2Faccount%2F#form',
			)
		);

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 1, $this->event_count() );
		$row  = $this->event_rows()[0];
		$data = $this->event_data( $row );
		$this->assertSame( '/login/', $row->source_url );
		$this->assertSame( 'https://tickets.example/show/', $data['dest_url'] );
	}

	/**
	 * URL user information is never retained by canonicalization.
	 */
	public function test_tracked_url_canonicalization_removes_userinfo(): void {
		$this->assertSame(
			'https://localhost/account/',
			extrachill_analytics_canonicalize_tracked_url( 'https://fixture:fixture@localhost/account/' )
		);
	}

	/**
	 * Malformed and non-HTTP event URLs fail closed.
	 *
	 * @dataProvider invalid_tracked_url_provider
	 *
	 * @param string $url Invalid URL fixture.
	 */
	public function test_public_event_rejects_invalid_tracked_urls( $url ): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
			array( 'dest_url' => $url ),
			'/story/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
	}

	/**
	 * Invalid tracked URLs.
	 *
	 * @return array<string,array{string}>
	 */
	public function invalid_tracked_url_provider() {
		return array(
			'non-http scheme' => array( 'javascript:alert(1)' ),
			'malformed value' => array( 'not a URL' ),
		);
	}

	/**
	 * Optional adapter fields retain their existing null compatibility.
	 */
	public function test_public_event_accepts_null_optional_field(): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
			array(
				'dest_host' => 'tickets.example',
				'dest_url'  => null,
				'category'  => 'ticketing',
			),
			'/story/'
		);

		$this->assertIsArray( $result );
		$this->assertNull( $result['event_data']['dest_url'] );
	}

	/**
	 * A source post cannot be attached to a different page path.
	 */
	public function test_public_event_rejects_source_post_mismatch(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'real-story',
				'post_status' => 'publish',
			)
		);
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_click',
			array( 'source_post' => $post_id ),
			'/other-story/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_post', $result->get_error_code() );
	}

	/**
	 * Privacy signals stay anonymous and non-canonical protected names are rejected.
	 *
	 * @dataProvider privacy_header_provider
	 *
	 * @param string $header     Privacy header.
	 * @param string $event_type Non-canonical protected event name.
	 */
	public function test_privacy_signal_and_noncanonical_event_admission( $header, $event_type ): void {
		$_SERVER[ $header ] = '1';
		$result             = extrachill_analytics_ability_track_event(
			array(
				'event_type' => 'outbound_click',
				'event_data' => array( 'dest_host' => 'tickets.example' ),
				'source_url' => '/story/',
				'visitor_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			)
		);

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 1, $this->event_count() );
		$this->assertNull( $this->event_rows()[0]->visitor_id );

		$rejected = extrachill_analytics_ability_track_event(
			array(
				'event_type' => $event_type,
				'event_data' => array( 'dest_host' => 'tickets.example' ),
				'source_url' => '/story/',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'invalid_event_type', $rejected->get_error_code() );
		$this->assertSame( 1, $this->event_count() );
	}

	/**
	 * Privacy headers.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function privacy_header_provider() {
		return array(
			'gpc with uppercase event' => array( 'HTTP_SEC_GPC', 'OUTBOUND_CLICK' ),
			'dnt with padded event'    => array( 'HTTP_DNT', ' outbound_click ' ),
			'dnt with spaced event'    => array( 'HTTP_DNT', 'outbound click' ),
		);
	}

	/**
	 * Server-owned event types do not inherit browser admission requirements.
	 */
	public function test_internal_event_contract_remains_unchanged(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		$result = extrachill_analytics_validate_public_event_write( 'user_registration', array( 'method' => 'form' ), '/register/' );

		$this->assertSame( '/register/', $result['source_url'] );
	}

	/**
	 * Public adapters reject fields that could create unbounded dimensions.
	 *
	 * @dataProvider unbounded_public_payload_provider
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Public dimensions.
	 */
	public function test_public_event_rejects_unbounded_or_unknown_values( $event_type, $event_data ): void {
		$result = extrachill_analytics_validate_public_event_write( $event_type, $event_data, '/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
	}

	/**
	 * Invalid public dimensions.
	 *
	 * @return array<string,array{string,array}>
	 */
	public function unbounded_public_payload_provider() {
		return array(
			'unbounded bridge term' => array( EC_ANALYTICS_EVENT_BRIDGE_CLICK, array( 'term' => str_repeat( 'x', 201 ) ) ),
			'unknown browser field' => array(
				EC_ANALYTICS_EVENT_SHARE_CLICK,
				array(
					'destination' => 'facebook',
					'free_form'   => 'nope',
				),
			),
		);
	}

	/**
	 * The Analytics-owned limiter rejects writes at its documented ceiling.
	 */
	public function test_public_write_rate_limit_is_bounded(): void {
		$key   = 'write_' . substr( hash_hmac( 'sha256', '203.0.113.10', wp_salt( 'nonce' ) ), 0, 32 );
		$group = 'extrachill-analytics-admission';
		wp_cache_set( $key, 240, $group, MINUTE_IN_SECONDS );

		$result = extrachill_analytics_check_public_write_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'analytics_write_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data( 'analytics_write_rate_limited' )['status'] );
		$this->assertSame( 241, (int) wp_cache_get( $key, $group ) );
	}

	/**
	 * Missing atomic storage fails closed instead of becoming unbounded.
	 */
	public function test_public_write_limiter_fails_closed_without_atomic_cache(): void {
		$this->set_ext_object_cache( false );

		$result = extrachill_analytics_check_public_write_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'analytics_write_limiter_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data( 'analytics_write_limiter_unavailable' )['status'] );
	}
}
