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

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms() {
		return $GLOBALS['festival_notification_terms'];
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
		return count( $user_ids );
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
		$GLOBALS['festival_notification_terms']       = array();
		$GLOBALS['festival_notification_recipients']  = array();
		$GLOBALS['festival_notification_resolutions'] = array();
		$GLOBALS['festival_notification_calls']       = array();
	}

	public function test_authorizes_only_festival_notification_resolution(): void {
		$entity = array(
			'entity_type' => 'festival',
			'taxonomy'    => 'festival',
		);

		$this->assertTrue( extrachill_events_authorize_festival_notification_producer( false, EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER, $entity, 'notification' ) );
		$this->assertFalse( extrachill_events_authorize_festival_notification_producer( false, EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER, $entity, 'email' ) );
		$this->assertFalse( extrachill_events_authorize_festival_notification_producer( false, 'untrusted', $entity, 'notification' ) );
	}

	public function test_notifies_deduplicated_recipients_for_every_festival(): void {
		$GLOBALS['festival_notification_terms']      = array( 'summer-jam', 'river-fest' );
		$GLOBALS['festival_notification_recipients'] = array(
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
}
