<?php
/**
 * Email-only Local Scene digest signup (extrachill-events#989).
 *
 * Covers: unknown email gets an unclaimed account and one confirmation
 * email with the claim link but no subscription; confirming subscribes and
 * saves the city only when the account has none; a claimed account gets no
 * claim link; tampered, cross-purpose and expired links are rejected; repeat
 * requests are throttled; the response never leaks anything.
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
		unset( $GLOBALS['ec_test_ability_resolver'] );
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
	}
}
