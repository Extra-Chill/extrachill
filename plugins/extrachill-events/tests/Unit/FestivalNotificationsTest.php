<?php
/**
 * Tests for festival event notifications.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_author;
		public $post_type;
		public $post_title;

		public function __construct( array $values ) {
			foreach ( $values as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post ) {
		return $post->post_type;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$value = $GLOBALS['festival_notification_meta'][ $post_id ][ $key ] ?? '';

		return $single ? $value : ( '' === $value ? array() : array( $value ) );
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $post_id, $key, $value, $unique = false ) {
		if ( ! empty( $GLOBALS['festival_notification_claim_failure'] ) || ( $unique && isset( $GLOBALS['festival_notification_meta'][ $post_id ][ $key ] ) ) ) {
			return false;
		}

		$GLOBALS['festival_notification_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		if ( ! isset( $GLOBALS['festival_notification_meta'][ $post_id ][ $key ] ) ) {
			return false;
		}

		unset( $GLOBALS['festival_notification_meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['festival_notification_terms'][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) ) {
	function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug ) {
		$GLOBALS['festival_notification_resolutions'][] = compact( 'producer', 'entity_type', 'taxonomy', 'slug' );
		return $GLOBALS['festival_notification_recipients'][ $slug ] ?? array();
	}
}

if ( ! function_exists( 'ec_users_notify' ) ) {
	function ec_users_notify( $user_ids, array $data ) {
		$GLOBALS['festival_notification_calls'][] = array(
			'user_ids' => $user_ids,
			'data'     => $data,
		);

		return array_shift( $GLOBALS['festival_notification_delivery_results'] ) ?? count( $user_ids );
	}
}

if ( ! function_exists( 'extrachill_users_get_local_scene' ) ) {
	function extrachill_users_get_local_scene( $user_id ) {
		return $GLOBALS['festival_notification_scenes'][ $user_id ] ?? null;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( trim( (string) $value ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) {
		return $post->post_title;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		return 'https://events.example/events/' . $post->ID . '/';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
	define( 'DATA_MACHINE_EVENTS_POST_TYPE', 'data_machine_events' );
}

require_once dirname( __DIR__, 2 ) . '/inc/core/data-machine-events/festival-notifications.php';

class FestivalNotificationsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['festival_notification_terms']            = array(
			'artist'   => array(),
			'festival' => array(),
		);
		$GLOBALS['festival_notification_recipients']       = array();
		$GLOBALS['festival_notification_resolutions']      = array();
		$GLOBALS['festival_notification_calls']            = array();
		$GLOBALS['festival_notification_scenes']           = array();
		$GLOBALS['festival_notification_meta']             = array();
		$GLOBALS['festival_notification_delivery_results'] = array();
		$GLOBALS['festival_notification_claim_failure']    = false;
	}

	public function test_authorizes_only_event_entity_notification_resolution(): void {
		$festival = array(
			'entity_type' => 'festival',
			'taxonomy'    => 'festival',
		);
		$artist   = array(
			'entity_type' => 'artist',
			'taxonomy'    => 'artist',
		);

		$this->assertTrue( extrachill_events_authorize_festival_notification_producer( false, EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER, $festival, 'notification' ) );
		$this->assertTrue( extrachill_events_authorize_festival_notification_producer( false, EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER, $artist, 'notification' ) );
		$this->assertFalse( extrachill_events_authorize_festival_notification_producer( false, EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER, $artist, 'email' ) );
		$this->assertFalse( extrachill_events_authorize_festival_notification_producer( false, 'untrusted', $artist, 'notification' ) );
	}

	public function test_notifies_deduplicated_recipients_for_every_festival(): void {
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam', 'river-fest' );
		$GLOBALS['festival_notification_recipients']        = array(
			'summer-jam' => array( 7, 9 ),
			'river-fest' => array( 9, 11 ),
		);
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 2, $GLOBALS['festival_notification_resolutions'] );
		$this->assertSame( array( 7, 9, 11 ), $GLOBALS['festival_notification_calls'][0]['user_ids'] );
		$this->assertSame( 'New event: The Big Show', $GLOBALS['festival_notification_calls'][0]['data']['title'] );
		$this->assertSame( 'https://events.example/events/42/', $GLOBALS['festival_notification_calls'][0]['data']['link'] );
	}

	public function test_notifies_artist_subscribers_and_deduplicates_across_entity_terms(): void {
		$GLOBALS['festival_notification_terms']['artist']   = array( 'the-headliners', 'support-act' );
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam' );
		$GLOBALS['festival_notification_recipients']        = array(
			'the-headliners' => array( 7, 9 ),
			'support-act'    => array( 9, 11 ),
			'summer-jam'     => array( 11, 13 ),
		);
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertSame(
			array(
				array(
					'producer'    => EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER,
					'entity_type' => 'artist',
					'taxonomy'    => 'artist',
					'slug'        => 'the-headliners',
				),
				array(
					'producer'    => EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER,
					'entity_type' => 'artist',
					'taxonomy'    => 'artist',
					'slug'        => 'support-act',
				),
				array(
					'producer'    => EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER,
					'entity_type' => 'festival',
					'taxonomy'    => 'festival',
					'slug'        => 'summer-jam',
				),
			),
			$GLOBALS['festival_notification_resolutions']
		);
		$this->assertSame( array( 7, 9, 11, 13 ), $GLOBALS['festival_notification_calls'][0]['user_ids'] );
	}

	public function test_notifies_nearby_artist_subscribers_with_a_distinct_notification(): void {
		$GLOBALS['festival_notification_terms']      = array(
			'artist'   => array( 'the-headliners' ),
			'festival' => array( 'summer-jam' ),
			'location' => array( 'charleston-sc' ),
		);
		$GLOBALS['festival_notification_recipients'] = array(
			'the-headliners' => array( 7, 9, 11 ),
			'summer-jam'     => array( 11, 13 ),
		);
		$GLOBALS['festival_notification_scenes']     = array(
			7  => array( 'slug' => 'charleston-sc' ),
			9  => array( 'slug' => 'austin-tx' ),
			11 => null,
		);
		$post                                        = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 2, $GLOBALS['festival_notification_calls'] );
		$this->assertSame( array( 9, 11, 13 ), $GLOBALS['festival_notification_calls'][0]['user_ids'] );
		$this->assertSame( 'festival_event_published', $GLOBALS['festival_notification_calls'][0]['data']['type'] );
		$this->assertSame( 'New event: The Big Show', $GLOBALS['festival_notification_calls'][0]['data']['title'] );
		$this->assertSame( array( 7 ), $GLOBALS['festival_notification_calls'][1]['user_ids'] );
		$this->assertSame( EXTRACHILL_EVENTS_NEARBY_ARTIST_EVENT_NOTIFICATION, $GLOBALS['festival_notification_calls'][1]['data']['type'] );
		$this->assertSame( 'Nearby show: The Big Show', $GLOBALS['festival_notification_calls'][1]['data']['title'] );
	}

	public function test_does_not_notify_for_published_post_updates(): void {
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'publish', $post );

		$this->assertSame( array(), $GLOBALS['festival_notification_resolutions'] );
		$this->assertSame( array(), $GLOBALS['festival_notification_calls'] );
	}

	public function test_repeated_invocation_delivers_once_after_claiming_notification(): void {
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam' );
		$GLOBALS['festival_notification_recipients']        = array(
			'summer-jam' => array( 7 ),
		);
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );
		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 1, $GLOBALS['festival_notification_calls'] );
		$this->assertCount( 1, $GLOBALS['festival_notification_resolutions'] );
		$this->assertNotEmpty( $GLOBALS['festival_notification_meta'][42][ EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_SENT_META ] );
	}

	public function test_existing_claim_skips_concurrent_invocation_before_recipient_resolution(): void {
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam' );
		$GLOBALS['festival_notification_recipients']        = array(
			'summer-jam' => array( 7 ),
		);
		$GLOBALS['festival_notification_meta'][42][ EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_SENT_META ] = '2026-07-13 18:00:00';
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertSame( array(), $GLOBALS['festival_notification_resolutions'] );
		$this->assertSame( array(), $GLOBALS['festival_notification_calls'] );
	}

	public function test_losing_atomic_claim_race_skips_delivery(): void {
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam' );
		$GLOBALS['festival_notification_recipients']        = array(
			'summer-jam' => array( 7 ),
		);
		$GLOBALS['festival_notification_claim_failure']     = true;
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 1, $GLOBALS['festival_notification_resolutions'] );
		$this->assertSame( array(), $GLOBALS['festival_notification_calls'] );
	}

	public function test_failed_delivery_clears_claim_for_a_later_retry(): void {
		$GLOBALS['festival_notification_terms']['festival'] = array( 'summer-jam' );
		$GLOBALS['festival_notification_recipients']        = array(
			'summer-jam' => array( 7 ),
		);
		$GLOBALS['festival_notification_delivery_results']  = array( 0, 1 );
		$post = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 3,
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_title'  => 'The Big Show',
			)
		);

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_SENT_META, $GLOBALS['festival_notification_meta'][42] );

		extrachill_events_notify_festival_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 2, $GLOBALS['festival_notification_calls'] );
		$this->assertNotEmpty( $GLOBALS['festival_notification_meta'][42][ EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_SENT_META ] );
	}
}
