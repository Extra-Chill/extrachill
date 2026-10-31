<?php
/**
 * Email-only Local Scene digest signup (extrachill-events#989).
 *
 * Covers: unknown email gets an unclaimed account and one confirmation
 * email with the claim link but no subscription; confirming subscribes and
 * saves the city only when the account has none; a claimed account gets no
 * claim link; tampered, cross-purpose and expired links are rejected; repeat
 * requests are throttled; the response never leaks anything; every request
 * records its city on the account as pending until confirmed (#465).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

require_once dirname( __DIR__ ) . '/Core/Stubs/class-wpterm.php';

$GLOBALS['guest_digest_test'] = array();

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		unset( $field, $taxonomy );
		return $GLOBALS['guest_digest_test']['terms'][ $value ] ?? false;
	}
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $id, $object_type = '', $resource_type = '' ) {
		unset( $object_type, $resource_type );
		return $GLOBALS['guest_digest_test']['ancestors'][ $id ] ?? array();
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example/location/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://events.example' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return isset( $GLOBALS['guest_digest_test']['users'][ $user_id ] ) ? (object) array( 'ID' => $user_id ) : false;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		unset( $single );
		return $GLOBALS['guest_digest_test']['user_meta'][ $user_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['guest_digest_test']['user_meta'][ $user_id ][ $key ] = $value;
		++$GLOBALS['guest_digest_test']['meta_writes'];
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		$existed = isset( $GLOBALS['guest_digest_test']['user_meta'][ $user_id ][ $key ] );
		unset( $GLOBALS['guest_digest_test']['user_meta'][ $user_id ][ $key ] );
		return $existed;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		unset( $field );
		$id = $GLOBALS['guest_digest_test']['emails'][ $value ] ?? 0;
		return $id ? (object) array( 'ID' => $id ) : false;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['guest_digest_test']['transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['guest_digest_test']['transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		unset( $domain );
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_print_scripts' ) ) {
	/** Prints the test script queue the way WordPress prints enqueued handles. */
	function wp_print_scripts() {
		foreach ( $GLOBALS['guest_digest_test']['script_queue'] ?? array() as $handle ) {
			echo '<script id="' . htmlspecialchars( (string) $handle, ENT_QUOTES, 'UTF-8' ) . '-js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test double of wp_print_scripts().
		}
		$GLOBALS['guest_digest_test']['script_queue'] = array();
	}
}
if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['guest_digest_test']['mail'][] = $args;
		return array( 'success' => true );
	}
}
if ( ! function_exists( 'extrachill_users_subscribe_to_entity' ) ) {
	function extrachill_users_subscribe_to_entity( $user_id, $entity_type, $taxonomy, $slug ) {
		$key = $user_id . '|' . $entity_type . '|' . $taxonomy . '|' . $slug;
		if ( empty( $GLOBALS['guest_digest_test']['subscriptions'][ $key ] ) ) {
			$GLOBALS['guest_digest_test']['subscriptions'][ $key ] = true;
			do_action( 'extrachill_users_entity_subscription_created', $user_id, compact( 'entity_type', 'taxonomy', 'slug' ) );
		}
		return array( 'subscribed' => true );
	}
}
if ( ! function_exists( 'extrachill_users_get_local_scene' ) ) {
	function extrachill_users_get_local_scene( int $user_id ) {
		$slug = $GLOBALS['guest_digest_test']['scenes'][ $user_id ] ?? '';
		return '' === $slug ? null : array( 'slug' => $slug );
	}
}
if ( ! function_exists( 'extrachill_users_set_local_scene' ) ) {
	function extrachill_users_set_local_scene( int $user_id, string $value ) {
		$GLOBALS['guest_digest_test']['scenes'][ $user_id ] = $value;
		return true;
	}
}

if ( ! defined( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED' ) ) {
	define( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED', 'local_scene_digest_subscribed' );
}

require_once dirname( __DIR__, 3 ) . '/inc/core/local-scene-digest.php';
require_once dirname( __DIR__, 3 ) . '/inc/single-event/scene-digest-offer.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/local-scene-digest-guest.php';

/** Records the resolve-account-by-email calls and returns a scripted account. */
final class GuestDigestResolveAbility {
	public array $calls = array();
	public array $account;

	public function __construct( array $account ) {
		$this->account = $account;
	}

	public function execute( array $input ) {
		$this->calls[] = $input;
		return $this->account;
	}
}

/** Records analytics events. */
final class GuestDigestTrackAbility {
	public array $events = array();

	public function execute( array $input ) {
		$this->events[] = $input;
		return true;
	}
}

final class LocalSceneDigestGuestTest extends PHPUnit\Framework\TestCase {

	private GuestDigestResolveAbility $resolve;
	private GuestDigestTrackAbility $track;

	protected function setUp(): void {
		$GLOBALS['guest_digest_test'] = array(
			'terms'         => array( 'charleston' => new WP_Term( (object) array( 'term_id' => 1618, 'name' => 'Charleston', 'slug' => 'charleston', 'taxonomy' => 'location' ) ) ),
			'ancestors'     => array( 1618 => array( 22, 1 ) ),
			'users'         => array( 42 => true ),
			'mail'          => array(),
			'transients'    => array(),
			'subscriptions' => array(),
			'scenes'        => array(),
			'script_queue'  => array(),
			'user_meta'     => array(),
			'meta_writes'   => 0,
			'emails'        => array( 'new@example.com' => 42 ),
		);
		$this->resolve = new GuestDigestResolveAbility(
			array(
				'user_id'   => 42,
				'claim_url' => 'https://extrachill.com/claim?key=abc',
			)
		);
		$this->track   = new GuestDigestTrackAbility();
		$resolve       = $this->resolve;
		$track         = $this->track;
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $resolve, $track ) {
			if ( 'extrachill/resolve-account-by-email' === $name ) {
				return $resolve;
			}
			return 'extrachill/track-analytics-event' === $name ? $track : null;
		};
		extrachill_events_digest_subscription_context( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_test_ability_resolver'], $GLOBALS['ec_test_filters']['extrachill_events_standalone_page_assets'] );
	}

	/** Stand in for Extra Chill Analytics: its listener enqueues the visitor-minting tracker. */
	private function hook_analytics_view_tracker(): void {
		add_action(
			'extrachill_events_standalone_page_assets',
			static function () {
				$GLOBALS['guest_digest_test']['script_queue'][] = 'extrachill-view-tracking';
			},
			20
		);
	}

	private function confirm_params(): array {
		$this->assertCount( 1, $GLOBALS['guest_digest_test']['mail'] );
		$url = $GLOBALS['guest_digest_test']['mail'][0]['context']['cta_url'];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		return $params;
	}

	public function test_unknown_email_gets_one_confirm_email_with_claim_link_and_no_subscription(): void {
		$result = extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( array( 'status' => 'check_inbox' ), $result );
		$this->assertSame( 'local_scene_digest', $this->resolve->calls[0]['registration_source'] );
		$this->assertCount( 1, $GLOBALS['guest_digest_test']['mail'] );
		$mail = $GLOBALS['guest_digest_test']['mail'][0];
		$this->assertSame( 'new@example.com', $mail['to'] );
		$this->assertStringContainsString( "this week's shows in Charleston", $mail['subject'] );
		$this->assertStringContainsString( 'https://extrachill.com/claim?key=abc', $mail['context']['body_html'] );
		$this->assertStringStartsWith( 'https://events.example/location/charleston/?ec_digest_confirm=1', $mail['context']['cta_url'] );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['subscriptions'] );
	}

	public function test_confirm_subscribes_saves_city_and_tracks_via_guest(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$result = extrachill_events_confirm_guest_digest( $this->confirm_params() );

		$this->assertTrue( $result['subscribed'] );
		$this->assertArrayHasKey( '42|local_scene_digest|location|charleston', $GLOBALS['guest_digest_test']['subscriptions'] );
		$this->assertSame( 'charleston', $GLOBALS['guest_digest_test']['scenes'][42] );
		$this->assertCount( 1, $this->track->events );
		$this->assertSame( 'guest', $this->track->events[0]['event_data']['via'] );
		$this->assertSame( 'email_confirm', $this->track->events[0]['event_data']['surface'] );
	}

	public function test_confirm_keeps_an_existing_local_scene(): void {
		$GLOBALS['guest_digest_test']['scenes'][42] = 'austin';
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		extrachill_events_confirm_guest_digest( $this->confirm_params() );

		$this->assertSame( 'austin', $GLOBALS['guest_digest_test']['scenes'][42] );
	}

	public function test_claimed_account_gets_no_claim_link(): void {
		$this->resolve->account = array( 'user_id' => 42 );

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'known@example.com' );

		$this->assertStringNotContainsString( 'Set your password', $GLOBALS['guest_digest_test']['mail'][0]['context']['body_html'] );
	}

	public function test_tampered_cross_purpose_and_expired_links_are_rejected(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$params = $this->confirm_params();

		$other_user         = $params;
		$other_user['user'] = '43';
		$this->assertInstanceOf( WP_Error::class, extrachill_events_confirm_guest_digest( $other_user ) );

		$bad_sig              = $params;
		$bad_sig['signature'] = str_repeat( '0', 64 );
		$this->assertInstanceOf( WP_Error::class, extrachill_events_confirm_guest_digest( $bad_sig ) );

		// An unsubscribe signature over the same user/city/expiry is not a confirm.
		$unsub              = $params;
		$unsub['signature'] = hash_hmac( 'sha256', '42|charleston|' . $params['expires'], wp_salt( 'auth' ) );
		$this->assertInstanceOf( WP_Error::class, extrachill_events_confirm_guest_digest( $unsub ) );

		$expires = time() - 1;
		$expired = array(
			'user'      => '42',
			'location'  => 'charleston',
			'expires'   => (string) $expires,
			'signature' => extrachill_events_guest_digest_signature( 42, 'charleston', $expires ),
		);
		$this->assertInstanceOf( WP_Error::class, extrachill_events_confirm_guest_digest( $expired ) );

		$this->assertSame( array(), $GLOBALS['guest_digest_test']['subscriptions'] );
	}

	public function test_repeat_request_is_throttled_but_answers_the_same(): void {
		$first  = extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$second = extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $GLOBALS['guest_digest_test']['mail'] );
	}

	public function test_response_leaks_nothing_about_the_account(): void {
		$result = extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( array( 'status' => 'check_inbox' ), $result );
		$this->assertStringNotContainsString( '42', (string) wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'claim', (string) wp_json_encode( $result ) );
	}

	public function test_non_city_location_and_bad_email_are_rejected_without_mail(): void {
		$GLOBALS['guest_digest_test']['ancestors'][1618] = array( 1 );

		$this->assertSame( 'city_not_found', extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' )->get_error_code() );

		$GLOBALS['guest_digest_test']['ancestors'][1618] = array( 22, 1 );
		$this->assertSame( 'invalid_email', extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'not-an-email' )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['mail'] );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['user_meta'] );
	}

	private function add_city( string $slug, int $term_id ): void {
		$GLOBALS['guest_digest_test']['terms'][ $slug ]     = new WP_Term( (object) array( 'term_id' => $term_id, 'name' => ucfirst( $slug ), 'slug' => $slug, 'taxonomy' => 'location' ) );
		$GLOBALS['guest_digest_test']['ancestors'][ $term_id ] = array( 22, 1 );
	}

	public function test_request_records_pending_city_without_subscribing_or_setting_scene(): void {
		$before = time();
		$result = extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( array( 'status' => 'check_inbox' ), $result );
		$requests = extrachill_events_get_guest_digest_requests( 42 );
		$this->assertSame( array( 'charleston' ), array_keys( $requests ) );
		$this->assertGreaterThanOrEqual( $before, $requests['charleston']['requested_at'] );
		$this->assertNull( $requests['charleston']['confirmed_at'] );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['subscriptions'] );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['scenes'] );
		$this->assertSame( array(), $this->track->events );
	}

	public function test_confirm_marks_the_request_confirmed_and_keeps_requested_at(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$requested_at = extrachill_events_get_guest_digest_requests( 42 )['charleston']['requested_at'];

		extrachill_events_confirm_guest_digest( $this->confirm_params() );

		$entry = extrachill_events_get_guest_digest_requests( 42 )['charleston'];
		$this->assertSame( $requested_at, $entry['requested_at'] );
		$this->assertIsInt( $entry['confirmed_at'] );
		$this->assertGreaterThanOrEqual( $requested_at, $entry['confirmed_at'] );
	}

	public function test_failed_confirm_leaves_the_request_pending(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$params              = $this->confirm_params();
		$params['signature'] = str_repeat( '0', 64 );

		$this->assertInstanceOf( WP_Error::class, extrachill_events_confirm_guest_digest( $params ) );
		$this->assertNull( extrachill_events_get_guest_digest_requests( 42 )['charleston']['confirmed_at'] );
	}

	public function test_repeat_request_inside_throttle_is_recorded_idempotently(): void {
		// Seed an earlier request so a reset of requested_at would be visible.
		$GLOBALS['guest_digest_test']['user_meta'][42]['_ec_local_scene_digest_requests'] = array(
			'charleston' => array(
				'requested_at' => 1000,
				'confirmed_at' => null,
			),
		);
		$GLOBALS['guest_digest_test']['transients'][ extrachill_events_guest_digest_resend_key( 42, 'charleston' ) ] = 1;

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( array(), $GLOBALS['guest_digest_test']['mail'] );
		$this->assertSame( 0, $GLOBALS['guest_digest_test']['meta_writes'] );
		$this->assertSame(
			array(
				'charleston' => array(
					'requested_at' => 1000,
					'confirmed_at' => null,
				),
			),
			extrachill_events_get_guest_digest_requests( 42 )
		);
	}

	public function test_throttled_first_request_is_still_recorded(): void {
		$GLOBALS['guest_digest_test']['transients'][ extrachill_events_guest_digest_resend_key( 42, 'charleston' ) ] = 1;

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$this->assertSame( array(), $GLOBALS['guest_digest_test']['mail'] );
		$this->assertArrayHasKey( 'charleston', extrachill_events_get_guest_digest_requests( 42 ) );
	}

	public function test_multiple_cities_are_tracked_independently(): void {
		$this->add_city( 'austin', 2000 );

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		extrachill_events_guest_subscribe_local_scene_digest( 'austin', 'new@example.com' );
		$this->assertCount( 2, $GLOBALS['guest_digest_test']['mail'] );

		$url = $GLOBALS['guest_digest_test']['mail'][1]['context']['cta_url'];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		extrachill_events_confirm_guest_digest( $params );

		$requests = extrachill_events_get_guest_digest_requests( 42 );
		$this->assertSame( array( 'charleston', 'austin' ), array_keys( $requests ) );
		$this->assertNull( $requests['charleston']['confirmed_at'] );
		$this->assertIsInt( $requests['austin']['confirmed_at'] );
		$this->assertSame( 'austin', $GLOBALS['guest_digest_test']['scenes'][42] );
	}

	public function test_requests_are_bounded_dropping_the_oldest(): void {
		$seed = array();
		for ( $i = 0; $i < EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX; $i++ ) {
			$seed[ 'city-' . $i ] = array(
				'requested_at' => 1000 + $i,
				'confirmed_at' => null,
			);
		}
		$GLOBALS['guest_digest_test']['user_meta'][42]['_ec_local_scene_digest_requests'] = $seed;

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$requests = extrachill_events_get_guest_digest_requests( 42 );
		$this->assertCount( EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX, $requests );
		$this->assertArrayHasKey( 'charleston', $requests );
		$this->assertArrayNotHasKey( 'city-0', $requests );
		$this->assertArrayHasKey( 'city-1', $requests );
	}

	public function test_analytics_never_carries_the_city(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		extrachill_events_confirm_guest_digest( $this->confirm_params() );

		$this->assertStringNotContainsString( 'charleston', (string) wp_json_encode( $this->track->events ) );
	}

	public function test_privacy_exporter_and_eraser_cover_the_requests(): void {
		$this->assertArrayHasKey( 'extrachill-events-guest-digest-requests', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'extrachill-events-guest-digest-requests', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$export = extrachill_events_export_guest_digest_requests( 'new@example.com' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'charleston', $export['data'][0]['data'][0]['value'] );

		$erase = extrachill_events_erase_guest_digest_requests( 'new@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( array(), extrachill_events_get_guest_digest_requests( 42 ) );
		$this->assertSame( array(), extrachill_events_export_guest_digest_requests( 'nobody@example.com' )['data'] );
	}

	public function test_get_confirm_page_loads_visitor_cookie_mint_without_subscribing(): void {
		$this->hook_analytics_view_tracker();
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$params = $this->confirm_params();

		$page = extrachill_events_guest_digest_confirm_page( $params, false );

		$this->assertTrue( $page['page_assets'] );
		$this->assertStringContainsString( '<form method="post"', $page['action_html'] );
		$this->assertStringContainsString( 'name="signature" value="' . $params['signature'] . '"', $page['action_html'] );

		$html = extrachill_events_build_local_scene_digest_unsubscribe_page( $page['title'], $page['message'], $page['action_html'], extrachill_events_standalone_page_scripts_html() );
		$this->assertStringContainsString( '<script id="extrachill-view-tracking-js"></script></body>', $html );

		// GET alone (a link scanner) never subscribes or tracks anything.
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['subscriptions'] );
		$this->assertSame( array(), $this->track->events );
	}

	public function test_post_confirm_subscribes_and_skips_page_assets(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );

		$page = extrachill_events_guest_digest_confirm_page( $this->confirm_params(), true );

		$this->assertSame( "You're in", $page['title'] );
		$this->assertFalse( $page['page_assets'] );
		$this->assertArrayHasKey( '42|local_scene_digest|location|charleston', $GLOBALS['guest_digest_test']['subscriptions'] );
		$this->assertCount( 1, $this->track->events );
	}

	public function test_invalid_confirm_link_gets_no_form_assets_or_subscription(): void {
		extrachill_events_guest_subscribe_local_scene_digest( 'charleston', 'new@example.com' );
		$params              = $this->confirm_params();
		$params['signature'] = str_repeat( '0', 64 );

		foreach ( array( false, true ) as $is_post ) {
			$page = extrachill_events_guest_digest_confirm_page( $params, $is_post );
			$this->assertSame( 'Confirmation link invalid', $page['title'] );
			$this->assertSame( '', $page['action_html'] );
			$this->assertFalse( $page['page_assets'] );
		}
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['subscriptions'] );
	}

	public function test_standalone_page_without_assets_prints_no_scripts(): void {
		$this->hook_analytics_view_tracker();

		$html = extrachill_events_build_local_scene_digest_unsubscribe_page( 'Unsubscribe?', 'Message', '<form></form>' );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringEndsWith( '</div></body></html>', $html );
		$this->assertSame( array(), $GLOBALS['guest_digest_test']['script_queue'] );
	}
}
