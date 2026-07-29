<?php
/** Tests for the weekly Local Scene digest. */

use PHPUnit\Framework\TestCase;

/** Load global WordPress doubles only inside this class's isolated process. */
function extrachill_events_load_local_scene_digest_test_environment(): void {
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $slug;
		public $name;
		public function __construct( object $term ) {
			foreach ( get_object_vars( $term ) as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_status;
		public $post_title;
		public function __construct( array $data ) {
			foreach ( $data as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $user_email;
		public $display_name;
		public function __construct( string $email, string $name = 'Listener' ) {
			$this->user_email  = $email;
			$this->display_name = $name;
		}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params;
		public function __construct( array $params ) {
			$this->params = $params;
		}
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $value ) { return $value; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) { return esc_html( $value ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) { return (string) $value; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $GLOBALS['local_scene_filters'][ $hook ] ?? $value; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() { return 7; }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) { return $post->post_title; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		return isset( $GLOBALS['festival_notification_meta'] ) ? 'https://events.example/events/' . $post->ID . '/' : 'https://events.example.com/event/' . $post->ID . '/';
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) { return 'https://events.example.com/location/' . $term->slug . '/'; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path ) { return 'https://events.example.com/wp-json/' . $path; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() { return 'https://events.example.com/'; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt() { return 'test-secret'; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ) { return $value; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'data_machine_events_query_events' ) ) {
	function data_machine_events_query_events( array $params ) {
		$GLOBALS['local_scene_query_params'][] = $params;
		if ( array_key_exists( 'local_scene_query_result', $GLOBALS ) ) {
			return $GLOBALS['local_scene_query_result'];
		}
		$posts = $GLOBALS['local_scene_posts'] ?? array();
		return array( 'posts' => $posts, 'total' => $GLOBALS['local_scene_query_total'] ?? count( $posts ) );
	}
}
if ( ! function_exists( 'data_machine_events_parse_event_data' ) ) {
	function data_machine_events_parse_event_data( WP_Post $post ): ?array { return $GLOBALS['local_scene_event_data'][ $post->ID ] ?? null; }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		if ( isset( $GLOBALS['festival_notification_terms'] ) ) {
			return $GLOBALS['festival_notification_terms'][ $taxonomy ] ?? array();
		}
		return $GLOBALS['local_scene_terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}
if ( ! function_exists( 'extrachill_get_priority_event_ids' ) ) {
	function extrachill_get_priority_event_ids() { return $GLOBALS['local_scene_priority_events'] ?? array(); }
}
if ( ! function_exists( 'ec_get_priority_venue_ids' ) ) {
	function ec_get_priority_venue_ids() { return $GLOBALS['local_scene_priority_venues'] ?? array(); }
}
if ( ! function_exists( 'ec_users_is_event_marked' ) ) {
	function ec_users_is_event_marked( int $user_id, int $event_id, int $blog_id ): bool {
		$GLOBALS['local_scene_mark_checks'][] = compact( 'user_id', 'event_id', 'blog_id' );
		return ! empty( $GLOBALS['local_scene_marks'][ $user_id ][ $event_id ] );
	}
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( string $site ): int {
		$GLOBALS['local_scene_blog_requests'][] = $site;
		return $GLOBALS['local_scene_events_blog_id'] ?? 7;
	}
}
if ( ! function_exists( 'ec_get_network_bot_user_id' ) ) {
	function ec_get_network_bot_user_id(): int { return 99; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		if ( isset( $GLOBALS['ec_test_existing_user_ids'] ) ) {
			return in_array( (int) $user_id, $GLOBALS['ec_test_existing_user_ids'], true ) ? (object) array( 'ID' => (int) $user_id ) : false;
		}
		return $GLOBALS['local_scene_users'][ $user_id ] ?? null;
	}
}
if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
	function ec_users_notify_with_receipts( $ids, array $payload ): array {
		$GLOBALS['local_scene_notifications'][] = compact( 'ids', 'payload' );
		$status          = array_shift( $GLOBALS['local_scene_receipt_statuses'] );
		$notification_id = $GLOBALS['local_scene_notification_id'] ?? 123;
		return array( 'recipients' => array( $ids[0] => array( 'status' => $status ?: 'inserted', 'notification_id' => $notification_id, 'error' => $GLOBALS['local_scene_receipt_error'] ?? null ) ) );
	}
}
if ( ! function_exists( 'ec_users_release_notification_receipt' ) ) {
	function ec_users_release_notification_receipt( int $notification_id, int $user_id, string $producer, string $idempotency_key ): bool {
		$GLOBALS['local_scene_releases'][] = compact( 'notification_id', 'user_id', 'producer', 'idempotency_key' );
		return $GLOBALS['local_scene_release_result'] ?? true;
	}
}
if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['local_scene_emails'][] = $args;
		return $GLOBALS['local_scene_queue_result'] ?? array( 'success' => true );
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms() { return $GLOBALS['local_scene_locations'] ?? array(); }
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors() { return array( 2, 1 ); }
}
if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) ) {
	function extrachill_users_entity_subscription_recipients( $producer, $type, $taxonomy, $slug, $delivery = 'notification' ) {
		if ( isset( $GLOBALS['festival_notification_recipients'] ) ) {
			$GLOBALS['festival_notification_resolutions'][] = array(
				'producer'    => $producer,
				'entity_type' => $type,
				'taxonomy'    => $taxonomy,
				'slug'        => $slug,
			);
			return $GLOBALS['festival_notification_recipients'][ $slug ] ?? array();
		}
		$GLOBALS['local_scene_recipient_resolutions'][] = compact( 'producer', 'type', 'taxonomy', 'slug', 'delivery' );
		return $GLOBALS['local_scene_recipients'][ $delivery ] ?? array();
	}
}
if ( ! function_exists( 'extrachill_users_get_local_scene' ) ) {
	function extrachill_users_get_local_scene( $user_id ) {
		if ( isset( $GLOBALS['festival_notification_scenes'] ) ) {
			return $GLOBALS['festival_notification_scenes'][ $user_id ] ?? null;
		}
		return $GLOBALS['local_scene_scenes'][ $user_id ] ?? null;
	}
}
if ( ! function_exists( 'extrachill_users_unsubscribe_from_entity' ) ) {
	function extrachill_users_unsubscribe_from_entity( $user, $type, $taxonomy, $slug ) {
		$GLOBALS['local_scene_unsubscribes'][] = compact( 'user', 'type', 'taxonomy', 'slug' );
		return array( 'subscribed' => false );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/local-scene-digest.php';
}

/**
 * Local Scene digest behavior.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LocalSceneDigestTest extends TestCase {
	private WP_Term $location;

	protected function setUp(): void {
		extrachill_events_load_local_scene_digest_test_environment();
		$this->location = $this->term( 44, 'austin', 'Austin' );
		$GLOBALS['local_scene_posts']           = array();
		$GLOBALS['local_scene_filters']         = array();
		unset( $GLOBALS['ec_test_filters']['extrachill_local_scene_digest_now'], $GLOBALS['ec_test_filters']['extrachill_local_scene_digest_candidate_cap'] );
		unset( $GLOBALS['local_scene_query_result'], $GLOBALS['local_scene_query_total'] );
		$GLOBALS['local_scene_event_data']      = array();
		$GLOBALS['local_scene_terms']           = array();
		$GLOBALS['local_scene_priority_events'] = array();
		$GLOBALS['local_scene_priority_venues'] = array();
		$GLOBALS['local_scene_marks']           = array();
		$GLOBALS['local_scene_mark_checks']     = array();
		$GLOBALS['local_scene_blog_requests']   = array();
		$GLOBALS['local_scene_events_blog_id']  = 7;
		$GLOBALS['local_scene_users']           = array( 7 => new WP_User( 'fan@example.com' ), 99 => new WP_User( 'bot@example.com' ) );
		$GLOBALS['local_scene_notifications']   = array();
		$GLOBALS['local_scene_receipt_statuses'] = array();
		$GLOBALS['local_scene_notification_id'] = 123;
		$GLOBALS['local_scene_receipt_error']   = null;
		$GLOBALS['local_scene_emails']          = array();
		$GLOBALS['local_scene_queue_result']    = array( 'success' => true );
		$GLOBALS['local_scene_releases']        = array();
		$GLOBALS['local_scene_release_result']  = true;
		$GLOBALS['local_scene_unsubscribes']    = array();
		$GLOBALS['local_scene_locations']       = array( $this->location );
		$GLOBALS['local_scene_recipients']      = array( 'notification' => array(), 'email' => array() );
		$GLOBALS['local_scene_scenes']          = array();
		$GLOBALS['local_scene_recipient_resolutions'] = array();
		$GLOBALS['local_scene_query_params']    = array();
		$GLOBALS['test_term_ancestors'] = array( 2, 1 );
	}

	public function test_specific_entity_mapping_and_producer_authorization_are_bounded(): void {
		$entities = extrachill_events_local_scene_digest_subscription_entities( array( 'location' => 'location' ) );
		$this->assertSame( 'location', $entities['local_scene_digest'] );
		$entity = array( 'entity_type' => 'local_scene_digest', 'taxonomy' => 'location' );
		$this->assertTrue( extrachill_events_authorize_local_scene_digest_producer( false, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER, $entity, 'email' ) );
		$this->assertFalse( extrachill_events_authorize_local_scene_digest_producer( false, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER, array( 'entity_type' => 'location', 'taxonomy' => 'location' ), 'email' ) );
		$this->assertFalse( extrachill_events_authorize_local_scene_digest_producer( false, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER, array( 'entity_type' => 'artist', 'taxonomy' => 'artist' ), 'email' ) );
		$this->assertFalse( extrachill_events_authorize_local_scene_digest_producer( false, 'other', $entity, 'email' ) );
	}

	public function test_candidates_enforce_venue_local_window_city_status_and_datetime_gates(): void {
		$austin = new DateTimeZone( 'America/Chicago' );
		$today  = new DateTimeImmutable( 'tomorrow', $austin );
		foreach ( range( 1, 13 ) as $id ) {
			$GLOBALS['local_scene_posts'][] = new WP_Post( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => $id < 3 ? 'Same Show!' : 'Show ' . $id ) );
			$GLOBALS['local_scene_terms'][ $id ] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10, 'club', 'Club' ) ), 'artist' => array( $id ) );
			$GLOBALS['local_scene_event_data'][ $id ] = array( 'startDate' => $today->format( 'Y-m-d' ), 'startTime' => '20:00:00', 'endTime' => '22:00:00', 'venueTimezone' => 'America/Chicago', 'performer' => 'Band' );
		}
		$GLOBALS['local_scene_priority_events']            = array( 2 );
		$GLOBALS['local_scene_terms'][3]['location']       = array( 55 );
		$GLOBALS['local_scene_event_data'][4]['eventStatus'] = 'EventCancelled';
		$GLOBALS['local_scene_event_data'][5]['eventStatus'] = 'EventPostponed';
		$GLOBALS['local_scene_event_data'][6]['startTime'] = '00:00:00';
		$GLOBALS['local_scene_terms'][7]['venue'][]        = $this->term( 11, 'other', 'Other' );
		$GLOBALS['local_scene_event_data'][8]['venueTimezone'] = 'Not/A_Timezone';
		$GLOBALS['local_scene_event_data'][9]['endTime']       = 'not-a-time';
		$GLOBALS['local_scene_event_data'][10]['endTime']      = '19:00:00';
		$GLOBALS['local_scene_event_data'][11]['eventStatus']  = 'EventUnknown';
		$GLOBALS['local_scene_event_data'][12]['startDate']    = $today->modify( '+8 days' )->format( 'Y-m-d' );
		unset( $GLOBALS['local_scene_event_data'][13]['venueTimezone'] );

		$result = extrachill_events_local_scene_digest_candidates( $this->location, 7 );

		$this->assertCount( 1, $result );
		$this->assertSame( 2, $result[0]['post_id'], 'The priority duplicate should win deterministically.' );
		$this->assertSame( array( 44 ), $GLOBALS['local_scene_query_params'][0]['tax_filters']['location'] );
		$this->assertSame( 'publish', $GLOBALS['local_scene_query_params'][0]['status'] );
		$this->assertSame( 'America/Chicago', $result[0]['datetime']->getTimezone()->getName() );
		$this->assertSame( '22:00', $result[0]['end_datetime']->format( 'H:i' ) );
		$this->assertLessThan( $today->format( 'Y-m-d' ), $GLOBALS['local_scene_query_params'][0]['date_start'] );
	}

	public function test_candidates_use_venue_local_rolling_boundaries_and_bounded_query(): void {
		$timezone = new DateTimeZone( 'America/Chicago' );
		$now      = new DateTimeImmutable( '2030-07-15 12:00:00', $timezone );
		$GLOBALS['local_scene_filters'] = array(
			'extrachill_local_scene_digest_now'           => $now->getTimestamp(),
			'extrachill_local_scene_digest_candidate_cap' => 37,
		);
		add_filter( 'extrachill_local_scene_digest_now', static fn() => $now->getTimestamp() );
		add_filter( 'extrachill_local_scene_digest_candidate_cap', static fn() => 37 );
		$dates = array(
			1 => $now->modify( '-1 second' ),
			2 => $now,
			3 => $now->modify( '+7 days -1 second' ),
			4 => $now->modify( '+7 days' ),
		);
		foreach ( $dates as $id => $date ) {
			$GLOBALS['local_scene_posts'][] = new WP_Post( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => 'Boundary ' . $id ) );
			$GLOBALS['local_scene_terms'][ $id ] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10 + $id, 'club-' . $id, 'Club ' . $id ) ), 'artist' => array( $id ) );
			$GLOBALS['local_scene_event_data'][ $id ] = array( 'startDate' => $date->format( 'Y-m-d' ), 'startTime' => $date->format( 'H:i:s' ), 'venueTimezone' => 'America/Chicago' );
		}
		$evidence = array();

		$result = extrachill_events_local_scene_digest_candidates( $this->location, 7, $evidence );

		$this->assertSame( array( 2, 3 ), array_column( $result, 'post_id' ) );
		$this->assertSame( 38, $GLOBALS['local_scene_query_params'][0]['per_page'] );
		$this->assertFalse( $evidence['truncated'] );
		$this->assertFalse( $evidence['failed'] );
	}

	public function test_candidate_truncation_uses_cap_plus_one_contract_and_slices_before_hydration(): void {
		$now = new DateTimeImmutable( '2030-07-15 12:00:00', new DateTimeZone( 'America/Chicago' ) );
		add_filter( 'extrachill_local_scene_digest_now', static fn() => $now->getTimestamp() );
		add_filter( 'extrachill_local_scene_digest_candidate_cap', static fn() => 20 );
		foreach ( range( 1, 21 ) as $id ) {
			$GLOBALS['local_scene_posts'][] = new WP_Post( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => 'Capped ' . $id ) );
			$GLOBALS['local_scene_terms'][ $id ] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 100 + $id, 'venue-' . $id, 'Venue ' . $id ) ), 'artist' => array( $id ) );
			$GLOBALS['local_scene_event_data'][ $id ] = array( 'startDate' => '2030-07-16', 'startTime' => '20:00:00', 'venueTimezone' => 'America/Chicago' );
		}
		$evidence = array();

		$result = extrachill_events_local_scene_digest_candidates( $this->location, 7, $evidence );

		$this->assertSame( 21, $GLOBALS['local_scene_query_params'][0]['per_page'] );
		$this->assertTrue( $evidence['truncated'] );
		$this->assertCount( 20, $result );
		$this->assertNotContains( 21, array_column( $result, 'post_id' ) );
	}

	public function test_candidate_query_failure_returns_privacy_safe_evidence(): void {
		$GLOBALS['local_scene_query_result'] = false;
		$evidence = array();

		$this->assertSame( array(), extrachill_events_local_scene_digest_candidates( $this->location, 7, $evidence ) );
		$this->assertSame( array( 'failed' => true, 'truncated' => false ), $evidence );

		$run = extrachill_events_run_local_scene_digest();
		$this->assertSame( 1, $run['counts']['candidate_query_failures'] );
		$this->assertSame( 1, $run['counts']['retryable_failures'] );
		$this->assertTrue( $run['retryable_failure'] );
		$this->assertSame( 1, $run['failures']['candidate_query_failed'] );
		$this->assertStringNotContainsString( 'austin', json_encode( $run ) );
	}

	public function test_priority_ordering_going_partition_and_sparse_behavior(): void {
		$base = new DateTimeImmutable( 'tomorrow 18:00', wp_timezone() );
		$events = array(
			$this->event( 1, $base, false, false, 4, true ),
			$this->event( 2, $base->modify( '+1 hour' ), true, false, 1, false ),
			$this->event( 3, $base->modify( '+2 hours' ), false, true, 4, true ),
		);
		usort( $events, 'extrachill_events_compare_local_scene_digest_events' );
		$this->assertSame( array( 2, 3, 1 ), array_column( $events, 'post_id' ) );

		$GLOBALS['local_scene_marks'][7][1] = true;
		$selected = extrachill_events_select_local_scene_digest_events( $events, 7, 8 );
		$this->assertSame( array( 1, 2, 3 ), array_column( $selected, 'post_id' ) );
		$this->assertCount( 3, $selected, 'Sparse scenes send every qualified event.' );
		$this->assertNotEmpty( $GLOBALS['local_scene_mark_checks'] );
		$this->assertSame( array( 7 ), array_values( array_unique( array_column( $GLOBALS['local_scene_mark_checks'], 'blog_id' ) ) ) );
		$this->assertContains( 'events', $GLOBALS['local_scene_blog_requests'] );
	}

	public function test_attendance_fails_closed_without_canonical_events_blog(): void {
		$GLOBALS['local_scene_events_blog_id'] = 0;
		$this->assertSame( array(), extrachill_events_select_local_scene_digest_events( array( $this->event( 1, new DateTimeImmutable( 'tomorrow' ) ) ), 7, 8 ) );
	}

	public function test_dense_selection_caps_repeated_venues_and_performers_when_alternatives_exist(): void {
		$base   = new DateTimeImmutable( 'tomorrow 18:00', wp_timezone() );
		$events = array();
		foreach ( range( 1, 10 ) as $id ) {
			$event                   = $this->event( $id, $base->modify( '+' . $id . ' hours' ) );
			$event['venue_id']       = $id <= 4 ? 1 : $id;
			$event['performer_keys'] = array( $id <= 4 ? 'term:1' : 'term:' . $id );
			$events[]                = $event;
		}

		$selected = extrachill_events_select_local_scene_digest_events( $events, 7, 8 );
		$this->assertCount( 8, $selected );
		$this->assertCount( 2, array_filter( $selected, static fn( $event ) => 1 === $event['venue_id'] ) );
		$this->assertCount( 2, array_filter( $selected, static fn( $event ) => in_array( 'term:1', $event['performer_keys'], true ) ) );
	}

	public function test_receipt_replay_never_queues_a_second_email(): void {
		$events = array( $this->event( 1, new DateTimeImmutable( 'tomorrow 20:00', wp_timezone() ) ) );
		$GLOBALS['local_scene_receipt_statuses'] = array( 'inserted', 'existing' );

		$first  = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$replay = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );

		$this->assertTrue( $first['email_queued'] );
		$this->assertSame( 'existing', $replay['status'] );
		$this->assertFalse( $replay['email_queued'] );
		$this->assertCount( 1, $GLOBALS['local_scene_emails'] );
		$this->assertStringContainsString( "More This Week", $GLOBALS['local_scene_emails'][0]['context']['body_html'] );
		$this->assertTrue( $GLOBALS['local_scene_notifications'][0]['payload']['producer_owns_email'] );
		$this->assertEmpty( $GLOBALS['local_scene_releases'], 'Successful delivery and replay must retain the receipt.' );
	}

	public function test_master_suppressed_delivery_retains_suppressed_notification_without_queueing(): void {
		$events = array( $this->event( 1, new DateTimeImmutable( 'tomorrow 20:00', wp_timezone() ) ) );

		$result = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, false );

		$this->assertSame( 'inserted', $result['status'] );
		$this->assertFalse( $result['email_queued'] );
		$this->assertFalse( $result['email_failed'] );
		$this->assertEmpty( $GLOBALS['local_scene_emails'] );
		$this->assertEmpty( $GLOBALS['local_scene_releases'] );
		$this->assertTrue( $GLOBALS['local_scene_notifications'][0]['payload']['producer_owns_email'] );
	}

	public function test_queue_errors_and_non_array_results_fail_closed(): void {
		$events = array( $this->event( 1, new DateTimeImmutable( 'tomorrow 20:00', new DateTimeZone( 'America/Chicago' ) ) ) );
		$GLOBALS['local_scene_queue_result'] = new WP_Error( 'queue_failed' );
		$failed = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$this->assertFalse( $failed['email_queued'] );
		$this->assertTrue( $failed['email_failed'] );
		$this->assertTrue( $failed['receipt_released'] );
		$this->assertTrue( $failed['retryable_failure'] );
		$this->assertSame( 'inserted', $failed['status'] );
		$this->assertSame( 'email_enqueue_failed', $failed['reason'] );
		$this->assertSame(
			array(
				'notification_id' => 123,
				'user_id'         => 7,
				'producer'        => EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER,
				'idempotency_key' => $GLOBALS['local_scene_notifications'][0]['payload']['idempotency_key'],
			),
			$GLOBALS['local_scene_releases'][0]
		);

		$GLOBALS['local_scene_receipt_statuses'] = array( 'inserted' );
		$GLOBALS['local_scene_queue_result'] = false;
		$non_array = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$this->assertFalse( $non_array['email_queued'] );
		$this->assertTrue( $non_array['email_failed'] );
		$this->assertTrue( $non_array['receipt_released'] );
		$this->assertTrue( $non_array['retryable_failure'] );
		$this->assertCount( 2, $GLOBALS['local_scene_releases'] );
		$this->assertSame( $GLOBALS['local_scene_releases'][0], $GLOBALS['local_scene_releases'][1] );
	}

	public function test_email_preflight_failures_release_exact_receipt_but_malformed_receipts_do_not_queue(): void {
		$events = array( $this->event( 1, new DateTimeImmutable( 'tomorrow 20:00', wp_timezone() ) ) );
		unset( $GLOBALS['local_scene_users'][7] );

		$invalid_user = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$this->assertTrue( $invalid_user['email_failed'] );
		$this->assertFalse( $invalid_user['retryable_failure'] );
		$this->assertSame(
			array(
				'notification_id' => 123,
				'user_id'         => 7,
				'producer'        => EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER,
				'idempotency_key' => $GLOBALS['local_scene_notifications'][0]['payload']['idempotency_key'],
			),
			$GLOBALS['local_scene_releases'][0]
		);
		$this->assertEmpty( $GLOBALS['local_scene_emails'] );

		$GLOBALS['local_scene_users'][7]         = new WP_User( 'fan@example.com' );
		$GLOBALS['local_scene_notification_id'] = 0;
		$malformed = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$this->assertSame( 'failed', $malformed['status'] );
		$this->assertSame( 'invalid_delivery_receipt', $malformed['reason'] );
		$this->assertFalse( $malformed['email_queued'] );
		$this->assertCount( 1, $GLOBALS['local_scene_releases'], 'No exact receipt exists to release.' );

		$GLOBALS['local_scene_notification_id'] = 123;
		$GLOBALS['local_scene_events_blog_id']  = 0;
		$invalid_email = extrachill_events_deliver_local_scene_digest( 7, $this->location, $events, true );
		$this->assertTrue( $invalid_email['email_failed'] );
		$this->assertFalse( $invalid_email['retryable_failure'] );
		$this->assertSame( 'email_enqueue_failed', $invalid_email['reason'] );
		$this->assertCount( 2, $GLOBALS['local_scene_releases'] );
		$this->assertSame( $GLOBALS['local_scene_releases'][0], $GLOBALS['local_scene_releases'][1] );
	}

	public function test_release_failure_keeps_notification_status_and_reports_email_failure(): void {
		$GLOBALS['local_scene_queue_result']   = new WP_Error( 'queue_failed' );
		$GLOBALS['local_scene_release_result'] = false;
		$result = extrachill_events_deliver_local_scene_digest( 7, $this->location, array( $this->event( 1, new DateTimeImmutable( 'tomorrow 20:00', wp_timezone() ) ) ), true );

		$this->assertSame( 'inserted', $result['status'] );
		$this->assertTrue( $result['email_failed'] );
		$this->assertFalse( $result['receipt_released'] );
		$this->assertFalse( $result['retryable_failure'] );
		$this->assertSame( 'email_receipt_release_failed', $result['reason'] );
	}

	public function test_receipt_errors_are_propagated_as_stable_privacy_safe_reasons(): void {
		$GLOBALS['local_scene_receipt_statuses'] = array( 'failed', 'failed' );
		$GLOBALS['local_scene_receipt_error']    = 'insert_failed';
		$known = extrachill_events_deliver_local_scene_digest( 7, $this->location, array( $this->event( 1, new DateTimeImmutable( 'tomorrow' ) ) ), true );
		$this->assertSame( 'notification_insert_failed', $known['reason'] );

		$GLOBALS['local_scene_receipt_error'] = 'recipient@example.com';
		$unknown = extrachill_events_deliver_local_scene_digest( 7, $this->location, array( $this->event( 1, new DateTimeImmutable( 'tomorrow' ) ) ), true );
		$this->assertSame( 'notification_receipt_failed', $unknown['reason'] );
		$this->assertStringNotContainsString( 'example', $unknown['reason'] );
	}

	public function test_aggregate_counts_separate_notification_success_from_email_admission_failure(): void {
		$tomorrow = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$GLOBALS['local_scene_posts'] = array( new WP_Post( array( 'ID' => 8, 'post_status' => 'publish', 'post_title' => 'Qualified Show' ) ) );
		$GLOBALS['local_scene_terms'][8] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10, 'club', 'Club' ) ), 'artist' => array( 3 ) );
		$GLOBALS['local_scene_event_data'][8] = array( 'startDate' => $tomorrow->format( 'Y-m-d' ), 'startTime' => '20:00:00', 'venueTimezone' => 'America/New_York' );
		$GLOBALS['local_scene_recipients'] = array( 'notification' => array( 7 ), 'email' => array( 7 ) );
		$GLOBALS['local_scene_scenes'][7] = array( 'slug' => 'austin' );
		$GLOBALS['local_scene_queue_result'] = new WP_Error( 'queue_failed' );
		add_filter( 'extrachill_local_scene_digest_candidate_cap', static fn() => 20 );
		foreach ( range( 9, 28 ) as $id ) {
			$GLOBALS['local_scene_posts'][] = new WP_Post( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => 'Extra Show ' . $id ) );
			$GLOBALS['local_scene_terms'][ $id ] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 100 + $id, 'club-' . $id, 'Club ' . $id ) ), 'artist' => array( $id ) );
			$GLOBALS['local_scene_event_data'][ $id ] = array( 'startDate' => $tomorrow->format( 'Y-m-d' ), 'startTime' => '21:00:00', 'venueTimezone' => 'America/New_York' );
		}

		$result = extrachill_events_run_local_scene_digest();

		$this->assertSame( 1, $result['counts']['notifications_inserted'] );
		$this->assertSame( 0, $result['counts']['notification_failures'] );
		$this->assertSame( 0, $result['counts']['emails_queued'] );
		$this->assertSame( 1, $result['counts']['email_failures'] );
		$this->assertSame( 1, $result['counts']['notifications_released'] );
		$this->assertSame( 1, $result['counts']['retryable_failures'] );
		$this->assertTrue( $result['retryable_failure'] );
		$this->assertSame( 1, $result['failures']['email_enqueue_failed'] );
		$this->assertSame( 1, $result['counts']['candidate_queries_truncated'] );
		$this->assertSame( 1, $result['failures']['candidate_query_truncated'] );
		$this->assertCount( 1, $GLOBALS['local_scene_releases'] );

		$GLOBALS['local_scene_posts']                     = array_slice( $GLOBALS['local_scene_posts'], 0, 1 );
		$GLOBALS['ec_test_filters']['extrachill_local_scene_digest_candidate_cap'] = array();
		$GLOBALS['local_scene_recipients']['email'] = array();
		$GLOBALS['local_scene_releases']            = array();
		$GLOBALS['local_scene_queue_result']        = array( 'success' => true );
		$master_suppressed = extrachill_events_run_local_scene_digest();
		$this->assertSame( 1, $master_suppressed['counts']['notifications_inserted'] );
		$this->assertSame( 0, $master_suppressed['counts']['emails_queued'] );
		$this->assertSame( 0, $master_suppressed['counts']['email_failures'] );
		$this->assertSame( 0, $master_suppressed['counts']['notifications_released'] );
		$this->assertEmpty( $master_suppressed['failures'] );
		$this->assertFalse( $master_suppressed['retryable_failure'] );
		$this->assertEmpty( $GLOBALS['local_scene_releases'] );

		$GLOBALS['local_scene_recipients']['email'] = array( 7 );
		$GLOBALS['local_scene_receipt_statuses']    = array( 'existing' );
		$replay = extrachill_events_run_local_scene_digest();
		$this->assertSame( 1, $replay['counts']['notifications_existing'] );
		$this->assertSame( 0, $replay['counts']['emails_queued'] );
		$this->assertSame( 0, $replay['counts']['email_failures'] );
		$this->assertSame( 0, $replay['counts']['notifications_released'] );
		$this->assertEmpty( $replay['failures'] );
		$this->assertFalse( $replay['retryable_failure'] );
		$this->assertEmpty( $GLOBALS['local_scene_releases'] );
	}

	public function test_dry_run_previews_qualified_recipients_without_mutation(): void {
		$tomorrow = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$GLOBALS['local_scene_posts'] = array( new WP_Post( array( 'ID' => 8, 'post_status' => 'publish', 'post_title' => 'Qualified Show' ) ) );
		$GLOBALS['local_scene_terms'][8] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10, 'club', 'Club' ) ), 'artist' => array( 3 ) );
		$GLOBALS['local_scene_event_data'][8] = array( 'startDate' => $tomorrow->format( 'Y-m-d' ), 'startTime' => '20:00:00', 'venueTimezone' => 'America/New_York' );
		$GLOBALS['local_scene_recipients'] = array( 'notification' => array( 7 ), 'email' => array( 7 ) );
		$GLOBALS['local_scene_scenes'][7] = array( 'slug' => 'austin' );

		$result = extrachill_events_run_local_scene_digest( array( 'dry_run' => true ) );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['counts']['qualified_events'] );
		$this->assertSame( 1, $result['counts']['recipients'] );
		$this->assertSame( 0, $result['counts']['notifications_inserted'] );
		$this->assertSame( 0, $result['counts']['emails_queued'] );
		$this->assertEmpty( $GLOBALS['local_scene_notifications'] );
		$this->assertEmpty( $GLOBALS['local_scene_emails'] );
		$this->assertEmpty( $GLOBALS['local_scene_releases'] );
		$this->assertEmpty( $GLOBALS['local_scene_unsubscribes'] );
	}

	public function test_runner_marks_location_and_recipient_resolution_failures_retryable(): void {
		$GLOBALS['local_scene_locations'] = new WP_Error( 'location_query_failed' );
		$location_failure = extrachill_events_run_local_scene_digest();
		$this->assertTrue( $location_failure['retryable_failure'] );
		$this->assertSame( 1, $location_failure['counts']['retryable_failures'] );
		$this->assertSame( 1, $location_failure['failures']['location_query_failed'] );

		$GLOBALS['local_scene_locations'] = array( $this->location );
		$tomorrow = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$GLOBALS['local_scene_posts'] = array( new WP_Post( array( 'ID' => 8, 'post_status' => 'publish', 'post_title' => 'Qualified Show' ) ) );
		$GLOBALS['local_scene_terms'][8] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10, 'club', 'Club' ) ), 'artist' => array( 3 ) );
		$GLOBALS['local_scene_event_data'][8] = array( 'startDate' => $tomorrow->format( 'Y-m-d' ), 'startTime' => '20:00:00', 'venueTimezone' => 'America/New_York' );
		$GLOBALS['local_scene_recipients']['notification'] = new WP_Error( 'recipient_query_failed' );
		$recipient_failure = extrachill_events_run_local_scene_digest();
		$this->assertTrue( $recipient_failure['retryable_failure'] );
		$this->assertSame( 1, $recipient_failure['counts']['retryable_failures'] );
		$this->assertSame( 1, $recipient_failure['failures']['recipient_resolution_failed'] );
	}

	public function test_austin_times_render_in_venue_timezone_with_explicit_end(): void {
		$start = new DateTimeImmutable( '2030-07-15 20:00:00', new DateTimeZone( 'America/Chicago' ) );
		$event = $this->event( 1, $start );
		$event['end_datetime'] = $start->modify( '+2 hours' );
		$html = extrachill_events_render_local_scene_digest_section( 'Austin', array( $event ) );
		$this->assertStringContainsString( 'Mon, Jul 15 at 8:00 PM - 10:00 PM', $html );
	}

	public function test_scene_mismatch_does_not_deliver_and_empty_scene_does_nothing(): void {
		$empty = extrachill_events_run_local_scene_digest();
		$this->assertSame( 0, $empty['counts']['recipients'] );
		$this->assertEmpty( $GLOBALS['local_scene_recipient_resolutions'], 'Empty scenes do not resolve or deliver to an audience.' );

		$tomorrow = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$GLOBALS['local_scene_posts'] = array( new WP_Post( array( 'ID' => 8, 'post_status' => 'publish', 'post_title' => 'Qualified Show' ) ) );
		$GLOBALS['local_scene_terms'][8] = array( 'location' => array( 44 ), 'venue' => array( $this->term( 10, 'club', 'Club' ) ), 'artist' => array( 3 ) );
		$GLOBALS['local_scene_event_data'][8] = array( 'startDate' => $tomorrow->format( 'Y-m-d' ), 'startTime' => '20:00:00', 'venueTimezone' => 'America/New_York' );
		$GLOBALS['local_scene_recipients'] = array( 'notification' => array( 7 ), 'email' => array( 7 ) );
		$GLOBALS['local_scene_scenes'][7] = array( 'slug' => 'denver' );

		$mismatch = extrachill_events_run_local_scene_digest();
		$this->assertSame( 1, $mismatch['counts']['scene_mismatches'] );
		$this->assertSame( 0, $mismatch['counts']['notifications_inserted'] );
		$this->assertEmpty( $GLOBALS['local_scene_notifications'] );
		$this->assertSame( array( 'local_scene_digest', 'local_scene_digest' ), array_column( $GLOBALS['local_scene_recipient_resolutions'], 'type' ) );
		$this->assertSame( array( 'location', 'location' ), array_column( $GLOBALS['local_scene_recipient_resolutions'], 'taxonomy' ) );
		$evidence = json_encode( $mismatch );
		$this->assertStringNotContainsString( 'austin', $evidence );
		$this->assertStringNotContainsString( 'denver', $evidence );
		$this->assertStringNotContainsString( 'fan@example.com', $evidence );
	}

	public function test_unsubscribe_get_is_scanner_safe_and_post_is_location_scoped(): void {
		$expires = time() + 60;
		$valid   = $this->unsubscribe_request( 7, 'austin', $expires );
		$confirmation = extrachill_events_local_scene_digest_unsubscribe_confirmation( $valid, false );
		$this->assertSame( false, $confirmation['confirmed'] );
		$this->assertStringContainsString( 'method="post"', $confirmation['html'] );
		$this->assertStringContainsString( 'name="location" value="austin"', $confirmation['html'] );
		$this->assertEmpty( $GLOBALS['local_scene_unsubscribes'], 'Signed GET must never mutate consent.' );

		$result  = extrachill_events_local_scene_digest_unsubscribe( $valid, false );
		$this->assertSame( array( 'unsubscribed' => true ), $result );
		$this->assertSame( array( 'user' => 7, 'type' => 'local_scene_digest', 'taxonomy' => 'location', 'slug' => 'austin' ), $GLOBALS['local_scene_unsubscribes'][0] );

		$tampered = $this->unsubscribe_request( 7, 'denver', $expires, hash_hmac( 'sha256', '7|austin|' . $expires, wp_salt( 'auth' ) ) );
		$this->assertInstanceOf( WP_Error::class, extrachill_events_local_scene_digest_unsubscribe_confirmation( $tampered, false ) );
		$this->assertInstanceOf( WP_Error::class, extrachill_events_local_scene_digest_unsubscribe( $tampered, false ) );
		$this->assertInstanceOf( WP_Error::class, extrachill_events_local_scene_digest_unsubscribe( $this->unsubscribe_request( 7, 'austin', time() - 1 ), false ) );
		$page = extrachill_events_build_local_scene_digest_unsubscribe_page( "You're unsubscribed", 'Only this scene changed.' );
		$this->assertStringContainsString( 'noindex,nofollow', $page );
		$this->assertStringContainsString( 'Only this scene changed.', $page );
	}

	private function event( int $id, DateTimeImmutable $date, bool $priority_event = false, bool $priority_venue = false, int $completeness = 0, bool $has_price = false ): array {
		return array(
			'post_id' => $id, 'title' => 'Show ' . $id, 'url' => 'https://events.example.com/event/' . $id . '/', 'datetime' => $date,
			'end_datetime' => null,
			'venue_id' => $id, 'venue_name' => 'Venue ' . $id, 'performer_keys' => array( 'term:' . $id ), 'price' => $has_price ? 'Free' : '',
			'priority_event' => $priority_event, 'priority_venue' => $priority_venue, 'completeness' => $completeness, 'has_price' => $has_price,
		);
	}

	private function unsubscribe_request( int $user, string $slug, int $expires, ?string $signature = null ): WP_REST_Request {
		$signature = $signature ?? hash_hmac( 'sha256', $user . '|' . $slug . '|' . $expires, wp_salt( 'auth' ) );
		return new WP_REST_Request( array( 'user' => $user, 'location' => $slug, 'expires' => $expires, 'signature' => $signature ) );
	}

	private function term( int $id, string $slug, string $name ): WP_Term {
		return new WP_Term( (object) array( 'term_id' => $id, 'slug' => $slug, 'name' => $name ) );
	}
}
