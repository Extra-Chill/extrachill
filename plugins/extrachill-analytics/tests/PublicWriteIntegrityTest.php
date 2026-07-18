<?php
/**
 * Tests for the public analytics write boundary.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/route-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/assets.php';
require_once dirname( __DIR__ ) . '/inc/core/event-types.php';
require_once dirname( __DIR__ ) . '/inc/core/write-integrity.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/track-page-view.php';

/**
 * Verify public writes carry consistent first-party evidence and stay bounded.
 */
final class PublicWriteIntegrityTest extends TestCase {
	/**
	 * Establish a normal browser request.
	 */
	protected function setUp(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://extrachill.com';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
		$_SERVER['HTTP_USER_AGENT']                              = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']                                  = '203.0.113.10';
		$GLOBALS['extrachill_analytics_test_blog_id']            = 1;
		$GLOBALS['extrachill_analytics_test_home_urls']          = array( 1 => 'https://extrachill.com' );
		$GLOBALS['extrachill_analytics_test_domain_map']         = array(
			'extrachill.com'  => 1,
			'extrachill.link' => 4,
		);
		$GLOBALS['extrachill_analytics_test_blog_slugs']         = array(
			1 => 'main',
			4 => 'artist',
			7 => 'events',
		);
		$GLOBALS['extrachill_analytics_test_cache']              = array();
		$GLOBALS['extrachill_analytics_test_ext_object_cache']   = true;
		$GLOBALS['extrachill_analytics_test_events']             = array();
		$GLOBALS['extrachill_analytics_test_tracked_post_views'] = array();
		$GLOBALS['extrachill_analytics_test_actions']            = array();
		$GLOBALS['extrachill_analytics_classifier_posts']        = array();
		$GLOBALS['extrachill_analytics_test_permalinks']         = array();
		$_COOKIE['ec_vid']                                       = '123e4567-e89b-42d3-a456-426614174000';
	}

	/**
	 * Restore request fixtures.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'], $_COOKIE['ec_vid'] );
	}

	/**
	 * A mapped custom-domain render can submit its exact signed post tuple.
	 */
	public function test_mapped_custom_domain_post_view_is_accepted(): void {
		$GLOBALS['extrachill_analytics_test_blog_id']      = 4;
		$GLOBALS['extrachill_analytics_test_home_urls'][4] = 'https://artist.extrachill.com';
		$_SERVER['HTTP_ORIGIN']                            = 'https://extrachill.link';
		$post            = new WP_Post();
		$post->ID        = 42;
		$post->post_name = 'band';
		$GLOBALS['extrachill_analytics_classifier_posts'][42] = $post;
		$proof = extrachill_analytics_pageview_proof( 42, '/band/', 'singular', 'extrachill.link' );

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'post_id'      => 42,
				'source_path'  => '/band/',
				'route_family' => 'singular',
				'proof'        => $proof,
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( array( 42 ), $GLOBALS['extrachill_analytics_test_tracked_post_views'] );
		$this->assertSame( '', $GLOBALS['extrachill_analytics_test_events'][0][3] );
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
		$GLOBALS['extrachill_analytics_test_blog_id']      = 4;
		$GLOBALS['extrachill_analytics_test_home_urls'][4] = 'https://artist.extrachill.com';
		$_SERVER['HTTP_ORIGIN']                            = 'https://extrachill.link';
		$_SERVER['HTTP_REFERER']                           = 'https://extrachill.link/';

		$result = extrachill_analytics_ability_track_page_view( array( 'post_id' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_route', $result->code );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_tracked_post_views'] );
	}

	/**
	 * A proof cannot be moved to another post, path, host, or route family.
	 */
	public function test_pageview_proof_rejects_changed_source_tuple(): void {
		$proof  = extrachill_analytics_pageview_proof( 0, '/events/', 'directory', 'extrachill.com' );
		$result = extrachill_analytics_validate_pageview_write( 99, '/fake/', 'singular', $proof );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_proof', $result->code );
	}

	/**
	 * Headerless clients cannot present public browser events as first-party.
	 */
	public function test_public_event_requires_current_site_browser_origin(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		$result = extrachill_analytics_validate_public_event_write( 'outbound_click', array(), '/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_origin', $result->code );
	}

	/**
	 * Absolute source claims must agree with the browser origin.
	 */
	public function test_public_event_rejects_source_host_mismatch(): void {
		$result = extrachill_analytics_validate_public_event_write( 'bridge_click', array(), 'https://attacker.example/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_source', $result->code );
	}

	/**
	 * Public event dimensions are checked and source URLs become query-free paths.
	 */
	public function test_public_event_normalizes_consistent_network_dimensions(): void {
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_impression',
			array(
				'source_site' => 'main',
				'dest_site'   => 'events',
			),
			'https://extrachill.com/story/?email=reader@example.test'
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/story/', $result['source_url'] );
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
		$GLOBALS['extrachill_analytics_test_permalinks'][42] = 'https://extrachill.com/real-story/';
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_click',
			array( 'source_post' => 42 ),
			'/other-story/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_post', $result->code );
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

		$this->assertSame( 1, $result );
		$this->assertSame( '', $GLOBALS['extrachill_analytics_test_events'][0][3] );

		$rejected = extrachill_analytics_ability_track_event(
			array(
				'event_type' => $event_type,
				'event_data' => array( 'dest_host' => 'tickets.example' ),
				'source_url' => '/story/',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'invalid_event_type', $rejected->code );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_events'] );
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
		$this->assertSame( 'invalid_event_field', $result->code );
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
		$GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] = 240;

		$result = extrachill_analytics_check_public_write_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'analytics_write_rate_limited', $result->code );
		$this->assertSame( 429, $result->data['status'] );
		$this->assertSame( 241, $GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] );
	}

	/**
	 * Missing atomic storage fails closed instead of becoming unbounded.
	 */
	public function test_public_write_limiter_fails_closed_without_atomic_cache(): void {
		$GLOBALS['extrachill_analytics_test_ext_object_cache'] = false;

		$result = extrachill_analytics_check_public_write_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'analytics_write_limiter_unavailable', $result->code );
		$this->assertSame( 503, $result->data['status'] );
	}
}
