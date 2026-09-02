<?php
/**
 * Internal booking-to-calendar alpha through public REST and Abilities contracts.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

use DataMachineEvents\Core\EventDatesTable;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingEventSyncService;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;

require_once __DIR__ . '/booking-attachment-mysql-bootstrap.php';

/** Proves the supported internal alpha without private files or finance. */
final class InternalBookingCalendarAlphaTest extends WP_UnitTestCase {
	/** @var int */
	private $main_blog_id;

	/** @var int */
	private $events_blog_id;

	/** @var array<string, mixed> */
	private $original_server = array();

	/** Install the real site-scoped schemas and controlled HTTP loopback. */
	public function set_up(): void {
		parent::set_up();

		$this->assertTrue( is_multisite(), 'The alpha proof requires WordPress multisite.' );
		$this->assertTrue( extension_loaded( 'mysqli' ), 'The alpha proof requires mysqli.' );
		$this->assertNotSame( ':memory:', DB_NAME, 'The alpha proof requires managed MySQL.' );

		$this->main_blog_id   = get_current_blog_id();
		$this->events_blog_id = $this->ensure_events_site();
		$this->assertSame( 7, $this->events_blog_id, 'The managed network must expose the canonical Events site ID.' );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_HOST' ) as $key ) {
			$this->original_server[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
		$_SERVER['HTTP_HOST']   = 'example.org';

		switch_to_blog( $this->events_blog_id );
		$this->assertTrue( BookingSchema::is_ready() );
		$this->assertTrue( EventDatesTable::table_exists() );
		restore_current_blog();

		add_filter( 'pre_http_request', array( $this, 'dispatch_affinity_loopback' ), 1, 3 );
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		add_filter( 'extrachill_api_booking_inquiry_rate_limit', '__return_zero' );
	}

	/** Remove disposable state without touching production storage. */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'dispatch_affinity_loopback' ), 1 );
		remove_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		remove_filter( 'extrachill_api_booking_inquiry_rate_limit', '__return_zero' );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		if ( get_current_blog_id() !== $this->main_blog_id ) {
			switch_to_blog( $this->main_blog_id );
		}
		foreach ( $this->original_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Execute the complete current alpha through public REST and Abilities. */
	public function test_internal_booking_to_calendar_alpha(): void {
		$this->assert_required_contracts();
		list( $operator_a, $operator_b ) = $this->create_operators();

		switch_to_blog( $this->events_blog_id );
		$venue_a = $this->create_venue( 'Alpha Room A' );
		$venue_b = $this->create_venue( 'Alpha Room B' );
		$this->grant_venue_owner( $venue_a, $operator_a );
		$this->grant_venue_owner( $venue_b, $operator_b );
		$this->configure_venue( $venue_a, $operator_a, array( 'main-room', 'side-room' ) );
		$this->configure_venue( $venue_b, $operator_b, array( 'club-room', 'patio' ) );
		restore_current_blog();

		$anonymous = $this->submit_inquiry(
			$venue_a,
			0,
			'alpha-anonymous',
			array(
				'artist_name'         => 'Anonymous Alpha',
				'contact_name'        => 'Anonymous Contact',
				'contact_email'       => 'anonymous@example.test',
				'requested_space_key' => 'main-room',
				'requested_start_at'  => '2031-06-01 00:00:00',
				'requested_end_at'    => '2031-06-01 03:00:00',
			)
		);
		$this->assertSame( $anonymous, $this->submit_inquiry( $venue_a, 0, 'alpha-anonymous', array( 'artist_name' => 'Anonymous Alpha', 'contact_name' => 'Anonymous Contact', 'contact_email' => 'anonymous@example.test', 'requested_space_key' => 'main-room', 'requested_start_at' => '2031-06-01 00:00:00', 'requested_end_at' => '2031-06-01 03:00:00' ) ), 'An exact anonymous retry changed its receipt.' );

		$authenticated = $this->submit_inquiry(
			$venue_b,
			$operator_b,
			'alpha-authenticated',
			array(
				'artist_name'         => 'Authenticated Alpha',
				'contact_email'       => 'authenticated@example.test',
				'requested_space_key' => 'club-room',
			)
		);
		switch_to_blog( $this->events_blog_id );
		$this->assertSame( $operator_b, $this->booking_by_public_id( $venue_b, $authenticated['public_id'], $operator_b )['submitter_user_id'] );

		$booking = $this->booking_by_public_id( $venue_a, $anonymous['public_id'], $operator_a );
		$this->assertSame( 'submitted', $booking['status'] );
		$this->assert_ability_denied( 'extrachill/get-venue-booking', array( 'booking_id' => $booking['id'] ), $operator_b );

		$booking = $this->transition( $booking, 'needs_info', $operator_a );
		$booking = $this->transition( $booking, 'submitted', $operator_a );
		$booking = $this->transition( $booking, 'under_review', $operator_a );
		$stale   = $this->ability_request( 'extrachill/transition-venue-booking', array( 'booking_id' => $booking['id'], 'to_status' => 'negotiating', 'expected_version' => $booking['version'] - 1 ), $operator_a );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'booking_version_conflict', $stale->get_data()['code'] );
		$booking = $this->transition( $booking, 'negotiating', $operator_a );
		$booking = $this->select_performance( $booking, 'main-room', '2031-06-01 00:00:00', '2031-06-01 03:00:00', $operator_a );
		$booking = $this->update_deal( $booking, $operator_a );
		$hold    = $this->ability_data( 'extrachill/create-booking-hold', array( 'booking_id' => $booking['id'], 'expected_booking_version' => $booking['version'] ), $operator_a );
		$this->assertSame( 'active', $hold['hold']['status'] );
		$booking['version'] = $hold['booking_version'];
		$booking = $this->transition( $booking, 'held', $operator_a );
		$booking = $this->transition( $booking, 'confirmed', $operator_a );
		$this->assertSame( 'converted', $this->ability_data( 'extrachill/list-booking-holds', array( 'venue_term_id' => $venue_a, 'booking_id' => $booking['id'] ), $operator_a )[0]['status'] );

		$fail_nested_upsert = static function (): bool {
			return false;
		};
		add_filter( 'datamachine_events_upsert_event_permission', $fail_nested_upsert, PHP_INT_MAX );
		$failed = $this->ability_request( 'extrachill/convert-booking-to-event', array( 'booking_id' => $booking['id'], 'expected_version' => $booking['version'] ), $operator_a );
		remove_filter( 'datamachine_events_upsert_event_permission', $fail_nested_upsert, PHP_INT_MAX );
		$this->assertGreaterThanOrEqual( 400, $failed->get_status() );
		$this->assertSame( 'booking_event_upsert_failed', $failed->get_data()['code'] );
		$this->assertNull( $this->ability_data( 'extrachill/get-venue-booking', array( 'booking_id' => $booking['id'] ), $operator_a )['event_id'] );

		$conversion = $this->ability_data( 'extrachill/convert-booking-to-event', array( 'booking_id' => $booking['id'], 'expected_version' => $booking['version'] ), $operator_a );
		$this->assertFalse( $conversion['already_converted'] );
		$this->assertSame( 'created', $conversion['event_action'] );
		$retry = $this->ability_data( 'extrachill/convert-booking-to-event', array( 'booking_id' => $booking['id'], 'expected_version' => $booking['version'] ), $operator_a );
		$this->assertTrue( $retry['already_converted'] );
		$this->assertSame( $conversion['event_id'], $retry['event_id'] );
		$this->assertSame( 1, $this->count_canonical_events( $anonymous['public_id'] ) );
		$this->assertSame( $this->events_blog_id, get_current_blog_id() );
		$booking['version'] = $conversion['booking_version'];

		$reminder = $this->ability_data(
			'extrachill/send-booking-message',
			array(
				'booking_id'      => $booking['id'],
				'idempotency_key' => 'alpha-cancellation-cleanup',
				'template'        => 'hold_expiring',
				'recipient'       => 'anonymous@example.test',
				'message'         => 'This reminder must be suppressed if the event is cancelled.',
				'reply_to'        => 'bookings@example.test',
			),
			$operator_a
		);
		$this->assertSame( 'scheduled', $reminder['status'] );

		$sync_input = array(
			'booking_id'       => $booking['id'],
			'expected_version' => $booking['version'],
			'changes'          => array(
				'venue_term_id'        => $venue_b,
				'space_key'            => 'patio',
				'performance_start_at' => '2031-06-03 00:00:00',
				'performance_end_at'   => '2031-06-03 03:00:00',
				'performer'            => 'Anonymous Alpha and Friends',
				'ticket_url'           => 'https://tickets.example.test/alpha-corrected',
			),
		);
		$this->assert_ability_denied( 'extrachill/reconcile-booking-event', $sync_input, $operator_b );
		$this->grant_venue_owner( $venue_b, $operator_a, $operator_b );

		$stale_sync = $sync_input;
		$stale_sync['expected_version'] -= 1;
		$stale = $this->ability_request( 'extrachill/reconcile-booking-event', $stale_sync, $operator_a );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'booking_version_conflict', $stale->get_data()['code'] );

		$sync = $this->ability_data( 'extrachill/reconcile-booking-event', $sync_input, $operator_a );
		$this->assertSame( 'succeeded', $sync['status'] );
		$this->assertSame( $conversion['event_id'], $sync['event_id'] );
		$this->assertSame( $sync, $this->ability_data( 'extrachill/reconcile-booking-event', $sync_input, $operator_a ) );
		$booking = $this->ability_data( 'extrachill/get-venue-booking', array( 'booking_id' => $booking['id'] ), $operator_a );
		$this->assertSame( $venue_b, $booking['venue_term_id'] );
		$this->assertSame( 'patio', $booking['space_key'] );
		$this->assertSame( '2031-06-03 00:00:00', $booking['performance_start_at'] );
		$this->assertSame( '2031-06-03 03:00:00', $booking['performance_end_at'] );
		$this->assertSame( 'Anonymous Alpha and Friends', $booking['artist_name'] );
		$this->assertSame( 'https://tickets.example.test/alpha-corrected', $booking['confirmed_deal']['data']['ticket_url'] );
		$corrected_hold = $this->ability_data( 'extrachill/list-booking-holds', array( 'venue_term_id' => $venue_b, 'booking_id' => $booking['id'] ), $operator_a )[0];
		$this->assertSame( 'converted', $corrected_hold['status'] );
		$this->assertSame( 'patio', $corrected_hold['space_key'] );
		$this->assertSame( '2031-06-03 00:00:00', $corrected_hold['start_at'] );
		$this->assertSame( '2031-06-03 03:00:00', $corrected_hold['end_at'] );

		$authority = $this->event_authority( $sync['event_id'] );
		$this->assertSame( '2031-06-02', $authority['startDate'] );
		$this->assertSame( '20:00', $authority['startTime'] );
		$this->assertSame( '23:00', $authority['endTime'] );
		$this->assertSame( 'EventRescheduled', $authority['eventStatus'] );
		$this->assertSame( 'Anonymous Alpha and Friends', $authority['performer'] );
		$this->assertSame( 'https://tickets.example.test/alpha-corrected', $authority['ticketUrl'] );
		$this->assertSame( $venue_b, $authority['venue_id'] );
		$this->assertSame( 1, $this->count_canonical_events( $anonymous['public_id'] ) );

		$cancel_input = array(
			'booking_id'       => $booking['id'],
			'expected_version' => $sync['booking_version'],
			'changes'          => array( 'cancelled' => true ),
		);
		$cancel = $this->ability_data( 'extrachill/reconcile-booking-event', $cancel_input, $operator_a );
		$this->assertSame( 'succeeded', $cancel['status'] );
		$this->assertSame( $cancel, $this->ability_data( 'extrachill/reconcile-booking-event', $cancel_input, $operator_a ) );
		$this->assertSame( 'cancelled', $this->ability_data( 'extrachill/get-venue-booking', array( 'booking_id' => $booking['id'] ), $operator_a )['status'] );
		$this->assertSame( 'EventCancelled', $this->event_authority( $cancel['event_id'] )['eventStatus'] );
		$communications        = $this->ability_data( 'extrachill/list-booking-communications', array( 'booking_id' => $booking['id'] ), $operator_a );
		$reminder_suppressions = array_values(
			array_filter(
				$communications,
				static fn( array $communication ): bool => (int) ( $communication['state']['intent_id'] ?? 0 ) === (int) $reminder['intent_id'] && 'suppressed' === ( $communication['state']['status'] ?? null )
			)
		);
		$this->assertCount( 1, $reminder_suppressions );
		$this->assertSame( 'booking_status_changed', $reminder_suppressions[0]['state']['reason'] );
		$this->assertSame( 1, $this->count_canonical_events( $anonymous['public_id'] ) );

		$this->assert_main_site_has_no_booking_writes();
		$this->assert_bounded_recovery_activity( $booking['id'] );
	}

	/** Assert all dependencies are public and loaded before creating fixtures. */
	private function assert_required_contracts(): void {
		$this->assertTrue( function_exists( 'extrachill_api_route_affinity_dispatch' ), 'Extra Chill API must be an active validation dependency.' );
		$this->assertTrue( function_exists( 'ec_cross_site_rest_request' ), 'Extra Chill Network must be an active validation dependency.' );
		foreach ( array( 'extrachill/create-booking-inquiry', 'extrachill/create-venue-membership', 'extrachill/update-venue-booking-config', 'extrachill/transition-venue-booking', 'extrachill/select-venue-booking-performance', 'extrachill/update-venue-booking-deal', 'extrachill/create-booking-hold', 'extrachill/convert-booking-to-event', 'extrachill/reconcile-booking-event', 'extrachill/send-booking-message', 'extrachill/list-booking-communications', 'data-machine-events/upsert-event', 'data-machine-events/update-source-event' ) as $name ) {
			$this->assertInstanceOf( WP_Ability::class, wp_get_ability( $name ), $name . ' is unavailable.' );
		}
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries', $routes );
	}

	/** Create two explicit internal operators. */
	private function create_operators(): array {
		$operator_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$operator_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( add_user_to_blog( $this->events_blog_id, $operator_a, 'administrator' ) );
		$this->assertTrue( add_user_to_blog( $this->events_blog_id, $operator_b, 'administrator' ) );
		switch_to_blog( $this->events_blog_id );
		get_user_by( 'id', $operator_a )->add_cap( VenueAuthorization::ACCESS_CAPABILITY );
		get_user_by( 'id', $operator_b )->add_cap( VenueAuthorization::ACCESS_CAPABILITY );
		restore_current_blog();
		return array( $operator_a, $operator_b );
	}

	/** Create one canonical venue with conversion metadata. */
	private function create_venue( string $name ): int {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'venue', 'name' => $name . ' ' . wp_generate_uuid4() ) );
		$this->assertNotWPError( $term );
		$meta = array(
			'_venue_address'  => '1 Alpha Way',
			'_venue_city'     => 'Charleston',
			'_venue_state'    => 'SC',
			'_venue_country'  => 'US',
			'_venue_timezone' => 'America/New_York',
		);
		foreach ( $meta as $key => $value ) {
			$this->assertNotFalse( update_term_meta( $term->term_id, $key, $value ) );
		}
		return (int) $term->term_id;
	}

	/** Grant the first explicit owner through the public membership ability. */
	private function grant_venue_owner( int $venue_id, int $operator_id, ?int $actor_id = null ): void {
		$membership = $this->ability_data( 'extrachill/create-venue-membership', array( 'venue_term_id' => $venue_id, 'user_id' => $operator_id, 'is_owner' => true ), $actor_id ?? $operator_id );
		$this->assertSame( $venue_id, $membership['venue_term_id'] );
		$this->assertTrue( $membership['is_owner'] );
	}

	/** Configure admission and multiple spaces through the public config ability. */
	private function configure_venue( int $venue_id, int $operator_id, array $space_keys ): void {
		$config = $this->ability_data( 'extrachill/get-venue-booking-config', array( 'venue_term_id' => $venue_id ), $operator_id );
		$this->assertArrayNotHasKey( 'presentation', $config['intake'] );
		$revision = $config['revision'];
		unset( $config['revision'], $config['updated_by_user_id'], $config['updated_at'] );
		$config['enabled'] = true;
		$config['spaces']  = array();
		$config['intake']['fields'] = array(
			array( 'key' => 'event_type', 'label' => 'Event type', 'type' => 'select', 'required' => false, 'options' => array( 'Concert', 'Market', 'Other' ), 'visible_when' => null ),
			array( 'key' => 'other_event', 'label' => 'Other event details', 'type' => 'text', 'required' => false, 'options' => array(), 'visible_when' => array( 'field' => 'event_type', 'value' => 'Other' ) ),
			array( 'key' => 'press_links', 'label' => 'Press links', 'type' => 'url', 'required' => false, 'options' => array(), 'visible_when' => null ),
		);
		$config['correspondence']['reminder_policies']['hold_expiring']['version']          += 1;
		$config['correspondence']['reminder_policies']['hold_expiring']['enabled']           = true;
		$config['correspondence']['reminder_policies']['hold_expiring']['delay_minutes']     = 60;
		$config['correspondence']['reminder_policies']['hold_expiring']['expected_statuses'] = array( 'confirmed' );
		foreach ( $space_keys as $index => $key ) {
			$config['spaces'][] = array( 'key' => $key, 'name' => ucwords( str_replace( '-', ' ', $key ) ), 'is_default' => 0 === $index );
		}
		$updated = $this->ability_data( 'extrachill/update-venue-booking-config', array( 'venue_term_id' => $venue_id, 'expected_revision' => $revision, 'config' => $config ), $operator_id );
		$this->assertTrue( $updated['enabled'] );
		$this->assertCount( count( $space_keys ), $updated['spaces'] );
		$this->assertSame( 'url', $updated['intake']['fields'][2]['type'] );
		$this->assertSame( array( 'field' => 'event_type', 'value' => 'Other' ), $updated['intake']['fields'][1]['visible_when'] );
	}

	/** Submit through the protected REST route from the main site. */
	private function submit_inquiry( int $venue_id, int $actor_id, string $key, array $fields ): array {
		$this->assertSame( $this->main_blog_id, get_current_blog_id() );
		wp_set_current_user( $actor_id );
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/' . $venue_id . '/booking-inquiries' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( array( 'venue' => $venue_id, 'idempotency_key' => $key, 'intake' => array( 'message' => 'Internal alpha inquiry.' ), 'turnstile_response' => 'managed-alpha' ), $fields ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $venue_id, $response->get_data()['venue_term_id'] );
		return $response->get_data();
	}

	/** Resolve one private booking through its authorized public list contract. */
	private function booking_by_public_id( int $venue_id, string $public_id, int $operator_id ): array {
		$bookings = $this->ability_data( 'extrachill/list-venue-bookings', array( 'venue_term_id' => $venue_id ), $operator_id );
		foreach ( $bookings as $booking ) {
			if ( $public_id === $booking['public_id'] ) {
				return $booking;
			}
		}
		$this->fail( 'The admitted booking was not visible to its venue operator.' );
	}

	/** Execute one optimistic lifecycle transition. */
	private function transition( array $booking, string $status, int $actor_id ): array {
		$result = $this->ability_data( 'extrachill/transition-venue-booking', array( 'booking_id' => $booking['id'], 'to_status' => $status, 'expected_version' => $booking['version'], 'note' => 'Alpha proof transition.' ), $actor_id );
		$this->assertSame( $status, $result['status'] );
		return $result;
	}

	/** Select a configured performance through its public contract. */
	private function select_performance( array $booking, string $space, string $start, string $end, int $actor_id ): array {
		return $this->ability_data( 'extrachill/select-venue-booking-performance', array( 'booking_id' => $booking['id'], 'expected_version' => $booking['version'], 'space_key' => $space, 'start_at' => $start, 'end_at' => $end ), $actor_id );
	}

	/** Persist one complete 20 percent deal without exercising settlement. */
	private function update_deal( array $booking, int $actor_id ): array {
		$deal = array(
			'version' => 1, 'type' => 'door_split', 'guarantee_cents' => 0,
			'revenue_share_basis_points' => 2000, 'revenue_share_basis' => 'gross_ticket_sales', 'currency' => 'USD',
			'capacity' => 300, 'advance_ticket_price_cents' => 2000, 'door_ticket_price_cents' => 2500,
			'ticket_fee_cents' => 300, 'tickets_on_sale_at' => '2031-01-01 15:00:00',
			'ticket_url' => 'https://tickets.example.test/alpha', 'additional_terms' => null,
		);
		return $this->ability_data( 'extrachill/update-venue-booking-deal', array( 'booking_id' => $booking['id'], 'expected_version' => $booking['version'], 'deal' => $deal ), $actor_id );
	}

	/** Execute one public Ability through Core's generic REST controller. */
	private function ability_request( string $name, array $input, int $actor_id ): WP_REST_Response {
		wp_set_current_user( $actor_id );
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$annotations = (array) $ability->get_meta_item( 'annotations' );
		$method = ! empty( $annotations['readonly'] ) ? 'GET' : ( ! empty( $annotations['destructive'] ) && ! empty( $annotations['idempotent'] ) ? 'DELETE' : 'POST' );
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );
		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		} else {
			$request->set_query_params( array( 'input' => $input ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** Return successful public Ability output. */
	private function ability_data( string $name, array $input, int $actor_id ): array {
		$response = $this->ability_request( $name, $input, $actor_id );
		$this->assertSame( 200, $response->get_status(), $name . ': ' . wp_json_encode( $response->get_data() ) );
		$this->assertIsArray( $response->get_data() );
		return $response->get_data();
	}

	/** Assert an exact venue permission denial through public REST. */
	private function assert_ability_denied( string $name, array $input, int $actor_id ): void {
		$response = $this->ability_request( $name, $input, $actor_id );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'venue_action_forbidden', $response->get_data()['code'] );
	}

	/** Count DME events with the immutable booking source identity. */
	private function count_canonical_events( string $booking_public_id ): int {
		$query = new WP_Query(
			array(
				'post_type' => DATA_MACHINE_EVENTS_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
				'meta_query' => array( array( 'key' => '_datamachine_event_source', 'value' => 'extrachill-events-booking' ), array( 'key' => '_datamachine_event_source_id', 'value' => $booking_public_id ) ),
			)
		);
		return count( $query->posts );
	}

	/** Read the public event authority after reconciliation. */
	private function event_authority( int $event_id ): array {
		$post = get_post( $event_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$attrs = array();
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				$attrs = (array) ( $block['attrs'] ?? array() );
				break;
			}
		}
		$this->assertNotEmpty( $attrs );
		$venues = wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) );
		$this->assertNotWPError( $venues );
		$this->assertCount( 1, $venues );
		return BookingEventSyncService::authority_from_event( $attrs, (int) reset( $venues ) );
	}

	/** Assert route affinity never wrote booking state under the main prefix. */
	private function assert_main_site_has_no_booking_writes(): void {
		global $wpdb;
		$events_table = BookingSchema::bookings_table();
		$this->assertStringStartsWith( $wpdb->get_blog_prefix( $this->events_blog_id ), $events_table );
		restore_current_blog();
		$main_table = BookingSchema::bookings_table();
		$this->assertStringStartsWith( $wpdb->get_blog_prefix( $this->main_blog_id ), $main_table );
		$this->assertNotSame( $events_table, $main_table );
		$main_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $main_table ) );
		$this->assertTrue( null === $main_exists || 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$main_table}" ), 'The protected route wrote a main-site booking row.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable integration proof.
		switch_to_blog( $this->events_blog_id );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable integration proof.
	}

	/** Assert the injected failure converged to one bounded failed and completed attempt. */
	private function assert_bounded_recovery_activity( int $booking_id ): void {
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking_id );
		$counts   = array_count_values( array_column( $activity, 'kind' ) );
		$this->assertSame( 2, $counts['event_conversion_started'] ?? 0 );
		$this->assertSame( 1, $counts['event_conversion_failed'] ?? 0 );
		$this->assertSame( 1, $counts['event_converted'] ?? 0 );
	}

	/** Materialize canonical blog ID 7 in the disposable managed network. */
	private function ensure_events_site(): int {
		$site = get_site( 7 );
		while ( ! $site ) {
			$id = self::factory()->blog->create( array( 'domain' => 'site-' . wp_generate_uuid4() . '.example.org', 'path' => '/' ) );
			if ( $id > 7 ) {
				$this->fail( 'The disposable network skipped canonical Events blog ID 7.' );
			}
			$site = get_site( 7 );
		}
		return (int) $site->blog_id;
	}

	/** Execute the real signed API HTTP hop inside the managed PHPUnit process. */
	public function dispatch_affinity_loopback( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, '127.0.0.1/wp-json/' ) ) {
			return $preempt;
		}
		$route = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$route = preg_replace( '#^/wp-json#', '', $route );
		$request = new WP_REST_Request( (string) $args['method'], $route );
		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( ! empty( $args['body'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) $args['body'] );
		}
		$server_keys = array( 'REMOTE_ADDR', 'HTTP_HOST', 'HTTP_X_EC_INTERNAL_USER', 'HTTP_X_EC_INTERNAL_TIMESTAMP', 'HTTP_X_EC_INTERNAL_SIGNATURE' );
		$server      = array();
		foreach ( $server_keys as $key ) {
			$server[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$source_user = get_current_user_id();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_HOST']   = (string) ( $args['headers']['Host'] ?? 'events.extrachill.com' );
		foreach ( array( 'User', 'Timestamp', 'Signature' ) as $suffix ) {
			$key = 'HTTP_X_EC_INTERNAL_' . strtoupper( $suffix );
			$header = 'X-EC-Internal-' . $suffix;
			if ( isset( $args['headers'][ $header ] ) ) {
				$_SERVER[ $key ] = $args['headers'][ $header ];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}
		wp_set_current_user( 0 );
		switch_to_blog( $this->events_blog_id );
		try {
			$authentication = apply_filters( 'rest_authentication_errors', null );
			$response       = is_wp_error( $authentication ) ? rest_convert_error_to_response( $authentication ) : rest_get_server()->dispatch( $request );
		} finally {
			restore_current_blog();
			wp_set_current_user( $source_user );
			foreach ( $server as $key => $value ) {
				if ( null === $value ) {
					unset( $_SERVER[ $key ] );
				} else {
					$_SERVER[ $key ] = $value;
				}
			}
		}
		return array(
			'headers' => $response->get_headers(), 'body' => wp_json_encode( $response->get_data() ),
			'response' => array( 'code' => $response->get_status(), 'message' => '' ), 'cookies' => array(), 'filename' => null,
		);
	}
}
