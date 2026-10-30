<?php
/**
 * Single-event Local Scene digest offer (extrachill-events#962).
 *
 * Pure-PHP with file-level WordPress doubles; runs in
 * phpunit-event-digest-offer-unit.xml.dist on the host runtime.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Core/Stubs/class-wpterm.php';

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_URL' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_URL', 'https://events.example/wp-content/plugins/extrachill-events/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_VERSION' ) ) {
	define( 'EXTRACHILL_EVENTS_VERSION', 'test' );
}
if ( ! defined( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED' ) ) {
	define( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED', 'local_scene_digest_subscribed' );
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['digest_test']['post_terms'][ $post_id ][ $taxonomy ] ?? false;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $term_id ) {
		return $GLOBALS['digest_test']['ancestors'][ $term_id ] ?? array();
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(  ) {
		return (int) ( $GLOBALS['digest_test']['user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(  ) {
		return get_current_user_id() > 0;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $v ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return $v;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt(  ) {
		return 'digest-offer-test-secret';
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example/location/' . $term->slug . '/';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return 'https://events.example/events/show-' . $id . '/';
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $keys, $url ) {
		return strtok( (string) $url, '?' );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect ) {
		return 'https://events.example/login/?redirect_to=' . rawurlencode( $redirect );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://events.example' . $path;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return 'nonce-' . $action === $nonce;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(  ) {
		return null;
	}
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site(  ) {
		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax(  ) {
		return false;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = '' ) {
		if ( is_array( $key ) ) {
			return $value . ( str_contains( (string) $value, '?' ) ? '&' : '?' ) . http_build_query( $key );
		}
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) { $GLOBALS['digest_test']['scripts'][] = $handle; }
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $v ) { echo htmlspecialchars( (string) $v, ENT_QUOTES ); }
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $v ) { echo htmlspecialchars( (string) $v, ENT_QUOTES ); }
}

require_once dirname( __DIR__, 3 ) . '/inc/core/account-market.php';
require_once dirname( __DIR__, 3 ) . '/inc/single-event/scene-digest-offer.php';

/**
 * @covers ::extrachill_events_render_event_digest_offer
 * @covers ::extrachill_events_subscribe_to_event_digest
 */
final class EventDigestOfferTest extends TestCase {

	private const EVENT_ID = 4242;

	private static int $next_user = 100;

	protected function setUp(): void {
		$_GET                  = array();
		$GLOBALS['digest_test'] = array(
			'user_id'       => 0,
			'scenes'        => array(),
			'subscriptions' => array(),
			'analytics'     => array(),
			'scripts'       => array(),
			'post_terms'    => array(
				self::EVENT_ID => array( 'location' => array( $this->term( 77, 'Charleston' ) ) ),
			),
			'ancestors'     => array(
				77 => array( 20, 1 ),
				88 => array( 20, 1 ),
				20 => array( 1 ),
			),
			'cities'        => array(
				'charleston' => $this->term( 77, 'Charleston' ),
				'columbia'   => $this->term( 88, 'Columbia' ),
			),
		);
		$GLOBALS['ec_test_ability_resolver'] = array( $this, 'ability' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_test_ability_resolver'] );
		$_GET = array();
	}

	/** Fake extrachill-users abilities backed by in-memory state. */
	public function ability( string $name ) {
		$state = &$GLOBALS['digest_test'];
		$run   = match ( $name ) {
			'extrachill/get-user-settings' => static function () use ( &$state ) {
				$slug = $state['scenes'][ $state['user_id'] ] ?? '';
				$term = $state['cities'][ $slug ] ?? null;
				return array(
					'local_scene' => $term ? array(
						'term_id' => $term->term_id,
						'slug'    => $term->slug,
						'name'    => $term->name,
					) : null,
				);
			},
			'extrachill/update-user-settings' => static function ( $input ) use ( &$state ) {
				$state['scenes'][ $state['user_id'] ] = $input['local_scene'];
				return array( 'updated' => true );
			},
			'extrachill/entity-subscription-status' => static fn( $input ) => array(
				'subscribed' => isset( $state['subscriptions'][ $state['user_id'] . ':' . $input['slug'] ] ),
			),
			'extrachill/entity-subscribe' => static function ( $input ) use ( &$state ) {
				$key = $state['user_id'] . ':' . $input['slug'];
				if ( ! isset( $state['subscriptions'][ $key ] ) ) {
					$state['subscriptions'][ $key ] = true;
					do_action( 'extrachill_users_entity_subscription_created', $state['user_id'], $input );
				}
				return $input + array( 'subscribed' => true );
			},
			'extrachill/track-analytics-event' => static function ( $input ) use ( &$state ) {
				$state['analytics'][] = $input;
				return count( $state['analytics'] );
			},
			default => null,
		};

		return $run ? new class( $run ) {
			public function __construct( private $run ) {}
			public function execute( $input = array() ) {
				return ( $this->run )( $input );
			}
		} : null;
	}

	private function term( int $id, string $name ): WP_Term {
		return new WP_Term(
			(object) array(
				'term_id' => $id,
				'name'    => $name,
				'slug'    => strtolower( $name ),
			)
		);
	}

	/** Fresh user per scenario: get_account_market() memoizes per user. */
	private function login( ?string $scene = null ): int {
		$user_id                           = ++self::$next_user;
		$GLOBALS['digest_test']['user_id'] = $user_id;
		if ( $scene ) {
			$GLOBALS['digest_test']['scenes'][ $user_id ] = $scene;
		}
		return $user_id;
	}

	private function render( int $post_id = self::EVENT_ID ): string {
		ob_start();
		extrachill_events_render_event_digest_offer( $post_id, '', 'upcoming' );
		return (string) ob_get_clean();
	}

	private function follow_login_redirect( string $html ): void {
		$this->assertSame( 1, preg_match( '/redirect_to=([^"&]+)/', $html, $m ) );
		$return = rawurldecode( html_entity_decode( $m[1] ) );
		$this->assertStringStartsWith( 'https://events.example/events/show-' . self::EVENT_ID . '/', $return );
		parse_str( (string) parse_url( $return, PHP_URL_QUERY ), $_GET );
	}

	public function test_hook_is_registered_in_action_composition(): void {
		$this->assertContains(
			'extrachill_events_render_event_digest_offer',
			array_column( $GLOBALS['ec_test_filters']['data_machine_events_action_buttons'][27] ?? array(), 0 )
		);
		$this->assertContains(
			'extrachill_events_record_digest_subscription',
			array_column( $GLOBALS['ec_test_filters']['extrachill_users_entity_subscription_created'][10] ?? array(), 0 )
		);
	}

	public function test_city_resolves_only_from_city_depth_location(): void {
		$this->assertSame( 77, extrachill_events_get_event_scene_term( self::EVENT_ID )->term_id );

		$GLOBALS['digest_test']['post_terms'][9] = array( 'location' => array( $this->term( 20, 'South Carolina' ) ) );
		$this->assertNull( extrachill_events_get_event_scene_term( 9 ) );
		$this->assertNull( extrachill_events_get_event_scene_term( 10 ) );
	}

	public function test_no_resolvable_city_renders_nothing(): void {
		$this->assertSame( '', $this->render( 10 ) );
	}

	public function test_anonymous_prompt_names_city_and_signs_a_return_to_this_event(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Get this week&#039;s shows in Charleston by email', $html );
		$this->assertStringContainsString( 'data-ec-track="event-digest-signin"', $html );
		$this->assertStringContainsString( 'data-ec-track-placement="event_digest_offer"', $html );
		$this->assertStringContainsString( 'class="notice notice-info event-digest-offer"', $html );
		$this->follow_login_redirect( $html );
		$this->assertSame( 'subscribe_digest', $_GET['ec_events_intent'] );
		$this->assertContains( 'extrachill-events-event-digest-offer', $GLOBALS['digest_test']['scripts'] );
	}

	public function test_member_without_scene_gets_one_click_set_scene_offer(): void {
		$this->login();

		$html = $this->render();

		$this->assertStringContainsString( 'data-event-digest-mode="set_scene"', $html );
		$this->assertStringContainsString( 'saves Charleston as your city', $html );
		$this->assertStringContainsString( 'value="nonce-extrachill_events_event_digest_' . self::EVENT_ID . '"', $html );
		$this->assertStringContainsString( 'data-ec-track="event-digest-subscribe"', $html );
	}

	public function test_member_in_this_city_gets_subscribe_offer(): void {
		$this->login( 'charleston' );

		$this->assertStringContainsString( 'data-event-digest-mode="subscribe"', $this->render() );
	}

	public function test_failed_status_keeps_the_offer_with_a_retry_notice(): void {
		$this->login( 'charleston' );
		$_GET = array( 'digest_status' => 'failed' );

		$html = $this->render();

		$this->assertStringContainsString( 'data-event-digest-mode="subscribe"', $html );
		$this->assertStringContainsString( 'We could not turn that on. Nothing was changed.', $html );
	}

	public function test_member_in_another_city_sees_nothing(): void {
		$this->login( 'columbia' );

		$this->assertSame( '', $this->render() );
	}

	public function test_hidden_when_already_subscribed(): void {
		$user_id = $this->login( 'charleston' );
		$GLOBALS['digest_test']['subscriptions'][ $user_id . ':charleston' ] = true;

		$this->assertSame( '', $this->render() );
	}

	public function test_one_click_sets_scene_subscribes_and_emits_once(): void {
		$user_id = $this->login();

		$this->assertSame( 'subscribed', extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'event_page', false ) );
		$this->assertSame( 'charleston', $GLOBALS['digest_test']['scenes'][ $user_id ] );
		$this->assertArrayHasKey( $user_id . ':charleston', $GLOBALS['digest_test']['subscriptions'] );
		$this->assertSame(
			array(
				array(
					'event_type' => 'local_scene_digest_subscribed',
					'event_data' => array(
						'user_id'          => $user_id,
						'surface'          => 'event_page',
						'via_continuation' => false,
						'event_id'         => self::EVENT_ID,
					),
				),
			),
			$GLOBALS['digest_test']['analytics']
		);
	}

	public function test_mismatched_scene_fails_closed_without_continuation(): void {
		$user_id = $this->login( 'columbia' );

		$this->assertSame( 'failed', extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'event_page', false ) );
		$this->assertSame( 'columbia', $GLOBALS['digest_test']['scenes'][ $user_id ] );
		$this->assertSame( array(), $GLOBALS['digest_test']['subscriptions'] );
	}

	public function test_anonymous_continuation_round_trip_subscribes_exactly_once(): void {
		$this->follow_login_redirect( $this->render() );
		$user_id = $this->login();

		$confirm = $this->render();
		$this->assertStringContainsString( 'data-event-digest-mode="confirm"', $confirm );
		$this->assertStringContainsString( 'value="1"', $confirm );
		$this->assertStringContainsString( 'autofocus', $confirm );
		$this->assertStringContainsString( 'action="https://events.example/events/show-' . self::EVENT_ID . '/#ec-event-digest"', $confirm );

		$this->assertSame( 'subscribed', extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'event_page', true ) );
		$this->assertSame( 'subscribed', extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'event_page', true ) );

		$this->assertCount( 1, $GLOBALS['digest_test']['subscriptions'] );
		$this->assertCount( 1, $GLOBALS['digest_test']['analytics'], 'A replayed confirm must not double count.' );
		$this->assertTrue( $GLOBALS['digest_test']['analytics'][0]['event_data']['via_continuation'] );

		$_GET = array( 'digest_status' => 'subscribed' );
		$this->assertStringContainsString( 'You&#039;re getting this week&#039;s shows in Charleston', $this->render() );
		$_GET = array();
		$this->assertSame( '', $this->render(), 'Hidden once subscribed.' );
		$this->assertSame( 'charleston', $GLOBALS['digest_test']['scenes'][ $user_id ] );
	}

	public function test_continuation_for_member_in_another_city_says_it_switches_city(): void {
		$this->follow_login_redirect( $this->render() );
		$user_id = $this->login( 'columbia' );

		$this->assertStringContainsString( 'switches your city from Columbia to Charleston', $this->render() );
		$this->assertSame( 'subscribed', extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'event_page', true ) );
		$this->assertSame( 'charleston', $GLOBALS['digest_test']['scenes'][ $user_id ] );
	}

	public function test_tampered_or_expired_continuation_is_ignored(): void {
		$this->follow_login_redirect( $this->render() );
		$_GET['ec_events_intent_signature'] = 'forged';
		$this->login();

		$this->assertStringContainsString( 'data-event-digest-mode="set_scene"', $this->render() );
	}

	public function test_post_mark_surface_is_recorded_and_unknown_surfaces_are_normalized(): void {
		$this->login( 'charleston' );
		extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'post_mark', false );
		$this->login( 'charleston' );
		extrachill_events_subscribe_to_event_digest( self::EVENT_ID, 'somewhere-else', false );

		$this->assertSame(
			array( 'post_mark', 'event_page' ),
			array_map( static fn( $e ) => $e['event_data']['surface'], $GLOBALS['digest_test']['analytics'] )
		);
	}

	public function test_other_entity_types_never_emit_digest_events(): void {
		$this->assertFalse( extrachill_events_record_digest_subscription( 5, array( 'entity_type' => 'artist' ) ) );
		$this->assertSame( array(), $GLOBALS['digest_test']['analytics'] );
	}
}
