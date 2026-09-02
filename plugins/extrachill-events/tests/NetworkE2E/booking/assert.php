<?php
/**
 * Exercise the booking vertical slice inside the disposable network runtime.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Core\VenueMembershipRepository;

$cases            = array();
$findings         = array();
$booking_e2e_logs = array();
$sites            = get_site_option( 'extrachill_booking_network_e2e_sites', array() );
$seed             = isset( $booking_network_e2e_seed ) ? (string) $booking_network_e2e_seed : 'booking-network-e2e-001';
$profiles         = array(
	array(
		'timezone'  => 'America/New_York',
		'date'      => '2028-01-05',
		'next_date' => '2028-01-06',
		'start'     => '20:00:00',
		'end'       => '23:00:00',
		'capacity'  => 300,
		'price'     => 1500,
	),
	array(
		'timezone'  => 'America/Los_Angeles',
		'date'      => '2028-06-15',
		'next_date' => '2028-06-16',
		'start'     => '21:00:00',
		'end'       => '23:30:00',
		'capacity'  => 125,
		'price'     => 2200,
	),
	array(
		'timezone'  => 'America/Chicago',
		'date'      => '2028-10-12',
		'next_date' => '2028-10-13',
		'start'     => '19:30:00',
		'end'       => '22:15:00',
		'capacity'  => 475,
		'price'     => 1800,
	),
);
$profile_index    = abs( crc32( $seed ) ) % count( $profiles );
$profile          = $profiles[ $profile_index ];
$seed_key         = substr( hash( 'sha256', $seed ), 0, 12 );

add_action(
	'datamachine_log',
	static function ( $level, $message, $context = array() ) use ( &$booking_e2e_logs ): void {
		$booking_e2e_logs[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	},
	10,
	3
);

/**
 * Record one independent product invariant assertion.
 *
 * @param string $id       Stable case ID.
 * @param bool   $passed   Whether the invariant held.
 * @param array  $evidence Case evidence.
 */
function booking_network_e2e_case( string $id, bool $passed, array $evidence = array() ): void {
	global $cases, $findings;
	$cases[] = array(
		'id'       => $id,
		'passed'   => $passed,
		'evidence' => $evidence,
	);
	if ( ! $passed ) {
		$findings[] = array(
			'id'       => $id,
			'status'   => 'open',
			'evidence' => $evidence,
		);
	}
}

/**
 * Stop after emitting a partial product campaign for a failed prerequisite.
 *
 * @param string $id       Stable prerequisite case ID.
 * @param array  $evidence Failure evidence.
 * @throws RuntimeException Always stops the dependent scenario.
 */
function booking_network_e2e_abort( string $id, array $evidence = array() ): void {
	global $cases, $findings, $seed;
	booking_network_e2e_case( $id, false, $evidence );
	$result = array(
		'schema'     => 'extrachill-events/booking-network-e2e-result/v1',
		'seed'       => $seed,
		'assertions' => count( $cases ),
		'passed'     => count(
			array_filter(
				$cases,
				static function ( $assertion_case ) {
					return $assertion_case['passed']; }
			)
		),
		'findings'   => $findings,
		'cases'      => $cases,
		'operations' => array(),
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable E2E evidence.
	printf( "BOOKING_NETWORK_E2E_RESULT:%s\n", base64_encode( wp_json_encode( $result ) ) );
	throw new RuntimeException( 'Booking network E2E prerequisite failed.' );
}

/**
 * Return a stable error code for an ability result.
 *
 * @param mixed $result Ability result.
 * @return string
 */
function booking_network_e2e_code( $result ): string {
	return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
}

/**
 * Execute one registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function booking_network_e2e_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'booking_network_e2e_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Build a deterministic enabled venue configuration.
 *
 * @param int $revision Configuration revision.
 * @return array
 */
function booking_network_e2e_config( int $revision = 1 ): array {
	$config               = ( new VenueBookingConfig() )->defaults();
	$config['enabled']    = true;
	$config['revision']   = $revision;
	$config['spaces']     = array(
		array(
			'key'        => 'main-room',
			'name'       => 'Main Room',
			'is_default' => true,
		),
	);
	$config['intake']     = array(
		'version' => 1,
		'fields'  => array(),
	);
	$config['updated_at'] = gmdate( 'Y-m-d H:i:s' );
	return $config;
}

/**
 * Build a deterministic public inquiry payload.
 *
 * @param int    $revision Configuration revision.
 * @param string $message  Public inquiry message.
 * @return array
 */
function booking_network_e2e_intake( int $revision, string $message ): array {
	return array(
		'config_revision' => $revision,
		'message'         => $message,
		'fields'          => array(),
		'consent'         => array(
			'id'       => 'booking-privacy',
			'version'  => 1,
			'accepted' => true,
		),
	);
}

switch_to_blog( (int) $sites['events'] );

$venue_a = wp_insert_term( 'E2E Room A', 'venue' );
$venue_b = wp_insert_term( 'E2E Room B', 'venue' );
if ( is_wp_error( $venue_a ) || is_wp_error( $venue_b ) ) {
	throw new RuntimeException( 'Could not create E2E venues.' );
}
$venue_a = (int) $venue_a['term_id'];
$venue_b = (int) $venue_b['term_id'];
foreach ( array( $venue_a, $venue_b ) as $venue_id ) {
	update_term_meta( $venue_id, VenueBookingConfig::META_KEY, booking_network_e2e_config() );
	update_term_meta( $venue_id, '_venue_address', '42 E2E Street' );
	update_term_meta( $venue_id, '_venue_city', 'Charleston' );
	update_term_meta( $venue_id, '_venue_state', 'SC' );
	update_term_meta( $venue_id, '_venue_zip', '29403' );
	update_term_meta( $venue_id, '_venue_country', 'US' );
	update_term_meta( $venue_id, '_venue_timezone', $profile['timezone'] );
}

$operator_id = wp_create_user( 'booking_e2e_operator', 'StrongPass-Booking-1', 'booking-operator@example.test' );
$outsider_id = wp_create_user( 'booking_e2e_outsider', 'StrongPass-Booking-2', 'booking-outsider@example.test' );
if ( is_wp_error( $operator_id ) || is_wp_error( $outsider_id ) ) {
	throw new RuntimeException( 'Could not create E2E users.' );
}
$operator_id = (int) $operator_id;
$outsider_id = (int) $outsider_id;
add_user_to_blog( (int) $sites['events'], $operator_id, 'administrator' );
add_user_to_blog( (int) $sites['events'], $outsider_id, 'administrator' );
get_userdata( $operator_id )->add_cap( 'access_events_admin' );
get_userdata( $outsider_id )->add_cap( 'access_events_admin' );

wp_set_current_user( 1 );
$membership = booking_network_e2e_execute(
	'extrachill/create-venue-membership',
	array(
		'venue_term_id' => $venue_a,
		'user_id'       => $operator_id,
		'is_owner'      => true,
	)
);
booking_network_e2e_case( 'setup-owner-membership', is_array( $membership ), array( 'code' => booking_network_e2e_code( $membership ) ) );
if ( ! is_array( $membership ) ) {
	$membership = ( new VenueMembershipRepository() )->create(
		array(
			'venue_term_id'      => $venue_a,
			'user_id'            => $operator_id,
			'is_owner'           => true,
			'status'             => 'active',
			'created_by_user_id' => 1,
		)
	);
}

$render_results = array();
restore_current_blog();
foreach ( array( 'main', 'studio', 'events' ) as $site_key ) {
	switch_to_blog( (int) $sites[ $site_key ] );
	$before                      = get_current_blog_id();
	$html                        = do_blocks( '<!-- wp:extrachill/venue-booking-inquiry {"venueId":' . $venue_a . '} /-->' );
	$after                       = get_current_blog_id();
	$render_results[ $site_key ] = array(
		'rendered' => '' !== $html,
		'context'  => $before === $after,
		'private'  => false !== strpos( $html, 'private-provider' ) || false !== strpos( $html, 'booking_address' ),
		'endpoint' => false !== strpos( $html, 'booking-inquiries' ),
		'no_cache' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
	);
	restore_current_blog();
}
foreach ( $render_results as $site_key => $render ) {
	booking_network_e2e_case( 'render-' . $site_key, $render['rendered'] && $render['context'] && ! $render['private'] && $render['endpoint'] && $render['no_cache'], $render );
}

switch_to_blog( (int) $sites['events'] );
wp_set_current_user( 0 );
$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/' . $venue_a . '/booking-inquiries' );
$request->set_param( 'venue', $venue_a );
$request->set_param( 'idempotency_key', 'missing-turnstile' );
$request->set_param( 'intake', booking_network_e2e_intake( 1, 'Missing security token.' ) );
$request->set_param( 'turnstile_response', '' );
$security = rest_do_request( $request );
booking_network_e2e_case(
	'turnstile-before-mutation',
	403 === $security->get_status() && 'turnstile_missing_token' === ( $security->get_data()['code'] ?? '' ),
	array(
		'status' => $security->get_status(),
		'data'   => $security->get_data(),
	)
);

$base_input                   = array(
	'idempotency_key'     => 'booking-e2e-' . $seed_key,
	'venue_term_id'       => $venue_a,
	'artist_name'         => 'E2E Ensemble',
	'contact_name'        => 'E2E Contact',
	'contact_email'       => 'e2e-contact@example.test',
	'requested_space_key' => 'main-room',
	'requested_start_at'  => $profile['date'] . ' ' . $profile['start'],
	'requested_end_at'    => $profile['date'] . ' ' . $profile['end'],
	'intake'              => booking_network_e2e_intake( 1, 'Stateful booking network E2E proposal.' ),
);
$first                        = booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $base_input );
$changed                      = $base_input;
$changed['intake']['message'] = 'Changed payload under the same key.';
$conflict                     = 0 === $profile_index ? null : booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $changed );
$retry                        = booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $base_input );
$conflict                     = null === $conflict ? booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $changed ) : $conflict;
booking_network_e2e_case(
	'inquiry-exact-retry',
	is_array( $first ) && $first === $retry,
	array(
		'first'         => $first,
		'retry'         => $retry,
		'order_profile' => $profile_index,
	)
);
booking_network_e2e_case( 'inquiry-changed-retry-conflicts', 'booking_idempotency_conflict' === booking_network_e2e_code( $conflict ), array( 'code' => booking_network_e2e_code( $conflict ) ) );
if ( ! is_array( $first ) ) {
	booking_network_e2e_abort( 'inquiry-prerequisite-missing', array( 'code' => booking_network_e2e_code( $first ) ) );
}
$injected                      = $base_input;
$injected['idempotency_key']   = 'identity-injection';
$injected['submitter_user_id'] = $outsider_id;
$identity                      = booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $injected );
booking_network_e2e_case( 'inquiry-identity-injection-rejected', is_wp_error( $identity ), array( 'code' => booking_network_e2e_code( $identity ) ) );
$other_venue                  = $base_input;
$other_venue['venue_term_id'] = $venue_b;
$other                        = booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $other_venue );
booking_network_e2e_case( 'inquiry-key-scoped-by-venue', is_array( $other ) && $other['public_id'] !== $first['public_id'], array( 'other' => $other ) );

$repository = new BookingRepository();
$booking    = $repository->find_inquiry( $venue_a, 'booking-e2e-' . $seed_key );
booking_network_e2e_case( 'inquiry-one-visible-booking', is_array( $booking ) && 'submitted' === $booking['status'], array( 'booking' => $booking ) );
if ( ! is_array( $booking ) ) {
	booking_network_e2e_abort( 'booking-prerequisite-missing' );
}

$race_input                    = $base_input;
$race_input['idempotency_key'] = 'booking-race-' . $seed_key;
$race_input['artist_name']     = 'Concurrent E2E Ensemble';
$race_created                  = booking_network_e2e_execute( 'extrachill/create-booking-inquiry', $race_input );
$race_booking                  = $repository->find_inquiry( $venue_a, $race_input['idempotency_key'] );
$race_affected                 = array();
if ( is_array( $race_created ) && is_array( $race_booking ) && class_exists( 'mysqli' ) ) {
	$db_host = DB_HOST;
	$db_port = 3306;
	if ( preg_match( '/^(.+):(\d+)$/', DB_HOST, $db_match ) ) {
		$db_host = $db_match[1];
		$db_port = (int) $db_match[2];
	}
	// Two independent connections are required to prove a real database race.
	// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli
	$race_connections = array(
		new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ),
		new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ),
	);
	// phpcs:enable WordPress.DB.RestrictedClasses.mysql__mysqli
	$race_table = \ExtraChillEvents\Core\BookingSchema::bookings_table();
	$race_names = array( 'Concurrent CAS Alpha', 'Concurrent CAS Beta' );
	foreach ( $race_names as $index => $artist_name ) {
		$race_sql = sprintf(
			"UPDATE `%s` SET artist_name = '%s', version = version + 1 WHERE id = %d AND version = %d",
			str_replace( '`', '``', $race_table ),
			$race_connections[ $index ]->real_escape_string( $artist_name ),
			(int) $race_booking['id'],
			(int) $race_booking['version']
		);
		$race_connections[ $index ]->query( $race_sql, MYSQLI_ASYNC );
	}
	foreach ( $race_connections as $connection ) {
		$connection->reap_async_query();
		$race_affected[] = $connection->affected_rows;
		$connection->close();
	}
}
sort( $race_affected );
$race_final = is_array( $race_booking ) ? $repository->get( (int) $race_booking['id'] ) : null;
booking_network_e2e_case(
	'concurrent-booking-cas-single-winner',
	array( 0, 1 ) === $race_affected && is_array( $race_final ) && (int) $race_final['version'] === (int) $race_booking['version'] + 1 && in_array( $race_final['artist_name'], $race_names, true ),
	array(
		'affected_rows' => $race_affected,
		'before'        => $race_booking,
		'after'         => $race_final,
	)
);

wp_set_current_user( $outsider_id );
$denied = booking_network_e2e_execute( 'extrachill/get-venue-booking', array( 'booking_id' => (int) $booking['id'] ) );
booking_network_e2e_case( 'cross-venue-private-read-denied', is_wp_error( $denied ), array( 'code' => booking_network_e2e_code( $denied ) ) );
wp_set_current_user( 0 );
$anonymous = booking_network_e2e_execute( 'extrachill/list-venue-bookings', array( 'venue_term_id' => $venue_a ) );
booking_network_e2e_case( 'anonymous-private-list-denied', is_wp_error( $anonymous ), array( 'code' => booking_network_e2e_code( $anonymous ) ) );

wp_set_current_user( $operator_id );
$operator_get = booking_network_e2e_execute( 'extrachill/get-venue-booking', array( 'booking_id' => (int) $booking['id'] ) );
booking_network_e2e_case( 'operator-private-read-allowed', is_array( $operator_get ), array( 'code' => booking_network_e2e_code( $operator_get ) ) );
$booking = is_array( $operator_get ) ? $operator_get : $booking;
$invalid_transition = booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'confirmed',
		'expected_version' => (int) $booking['version'],
	)
);
booking_network_e2e_case( 'invalid-transition-rejected', is_wp_error( $invalid_transition ), array( 'code' => booking_network_e2e_code( $invalid_transition ) ) );
$under_review = booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'under_review',
		'expected_version' => (int) $booking['version'],
	)
);
$stale_transition = booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'needs_info',
		'expected_version' => (int) $booking['version'],
	)
);
booking_network_e2e_case( 'stale-transition-conflicts', 'booking_version_conflict' === booking_network_e2e_code( $stale_transition ), array( 'code' => booking_network_e2e_code( $stale_transition ) ) );
$negotiating  = is_array( $under_review ) ? booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'negotiating',
		'expected_version' => (int) $under_review['version'],
	)
) : $under_review;
booking_network_e2e_case( 'valid-review-negotiation-sequence', is_array( $negotiating ) && 'negotiating' === $negotiating['status'], array( 'code' => booking_network_e2e_code( $negotiating ) ) );
if ( ! is_array( $negotiating ) ) {
	booking_network_e2e_abort( 'negotiation-prerequisite-missing', array( 'code' => booking_network_e2e_code( $negotiating ) ) );
}

$message_input              = array(
	'booking_id'      => (int) $booking['id'],
	'idempotency_key' => 'booking-e2e-message',
	'template'        => 'operator_message',
	'recipient'       => 'e2e-contact@example.test',
	'message'         => 'E2E booking update.',
	'reply_to'        => 'booking@example.test',
);
$message                    = booking_network_e2e_execute( 'extrachill/send-booking-message', $message_input );
$message_retry              = booking_network_e2e_execute( 'extrachill/send-booking-message', $message_input );
$changed_message            = $message_input;
$changed_message['message'] = 'Changed E2E booking update.';
$message_conflict           = booking_network_e2e_execute( 'extrachill/send-booking-message', $changed_message );
booking_network_e2e_case(
	'message-exact-retry',
	is_array( $message ) && $message === $message_retry,
	array(
		'message' => $message,
		'retry'   => $message_retry,
	)
);
booking_network_e2e_case( 'message-changed-retry-conflicts', 'booking_message_idempotency_conflict' === booking_network_e2e_code( $message_conflict ), array( 'code' => booking_network_e2e_code( $message_conflict ) ) );

$performance = booking_network_e2e_execute(
	'extrachill/select-venue-booking-performance',
	array(
		'booking_id'       => (int) $booking['id'],
		'expected_version' => (int) $negotiating['version'],
		'space_key'        => 'main-room',
		'start_at'         => $profile['date'] . ' ' . $profile['start'],
		'end_at'           => $profile['date'] . ' ' . $profile['end'],
	)
);
$deal        = array(
	'version'                    => 1,
	'type'                       => 'custom',
	'guarantee_cents'            => 0,
	'revenue_share_basis_points' => 2000,
	'revenue_share_basis'        => 'gross_ticket_sales',
	'currency'                   => 'USD',
	'capacity'                   => $profile['capacity'],
	'advance_ticket_price_cents' => $profile['price'],
	'door_ticket_price_cents'    => $profile['price'] + 500,
	'ticket_fee_cents'           => 200,
	'tickets_on_sale_at'         => null,
	'ticket_url'                 => 'https://tickets.example/e2e',
	'additional_terms'           => 'E2E fixture only.',
);
$deal_result = is_array( $performance ) ? booking_network_e2e_execute(
	'extrachill/update-venue-booking-deal',
	array(
		'booking_id'       => (int) $booking['id'],
		'expected_version' => (int) $performance['version'],
		'deal'             => $deal,
	)
) : $performance;
booking_network_e2e_case(
	'performance-and-deal-selected',
	is_array( $deal_result ),
	array(
		'performance_code' => booking_network_e2e_code( $performance ),
		'deal_code'        => booking_network_e2e_code( $deal_result ),
	)
);
if ( ! is_array( $deal_result ) ) {
	booking_network_e2e_abort( 'deal-prerequisite-missing', array( 'code' => booking_network_e2e_code( $deal_result ) ) );
}

$hold      = booking_network_e2e_execute(
	'extrachill/create-booking-hold',
	array(
		'booking_id'               => (int) $booking['id'],
		'expected_booking_version' => (int) $deal_result['version'],
	)
);
$hold_data = is_array( $hold ) && is_array( $hold['hold'] ?? null ) ? $hold['hold'] : $hold;
booking_network_e2e_case( 'hold-created', is_array( $hold_data ) && 'active' === $hold_data['status'], array( 'hold' => $hold ) );
$hold_booking_version = is_array( $hold ) ? (int) ( $hold['booking_version'] ?? $deal_result['version'] ) : (int) $deal_result['version'];
$held                 = booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'held',
		'expected_version' => $hold_booking_version,
	)
);
$confirmed            = is_array( $held ) ? booking_network_e2e_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'confirmed',
		'expected_version' => (int) $held['version'],
	)
) : $held;
booking_network_e2e_case(
	'hold-confirmation-sequence',
	is_array( $confirmed ) && 'confirmed' === $confirmed['status'] && is_array( $confirmed['confirmed_deal'] ),
	array(
		'held_code'      => booking_network_e2e_code( $held ),
		'confirmed_code' => booking_network_e2e_code( $confirmed ),
	)
);
if ( ! is_array( $confirmed ) ) {
	booking_network_e2e_abort( 'confirmation-prerequisite-missing', array( 'code' => booking_network_e2e_code( $confirmed ) ) );
}

if ( is_array( $confirmed ) ) {
	$converted = booking_network_e2e_execute(
		'extrachill/convert-booking-to-event',
		array(
			'booking_id'       => (int) $booking['id'],
			'expected_version' => (int) $confirmed['version'],
		)
	);
	booking_network_e2e_case(
		'event-conversion-succeeds',
		is_array( $converted ) && ! empty( $converted['event_id'] ),
		array(
			'converted' => $converted,
			'code'      => booking_network_e2e_code( $converted ),
			'logs'      => $booking_e2e_logs,
		)
	);
	$convert_retry = is_array( $converted ) ? booking_network_e2e_execute(
		'extrachill/convert-booking-to-event',
		array(
			'booking_id'       => (int) $booking['id'],
			'expected_version' => (int) $converted['booking_version'],
		)
	) : $converted;
	booking_network_e2e_case( 'event-conversion-idempotent', is_array( $convert_retry ) && ! empty( $convert_retry['already_converted'] ) && $convert_retry['event_id'] === $converted['event_id'], array( 'retry' => $convert_retry ) );
	if ( is_array( $converted ) ) {
		$rescheduled = booking_network_e2e_execute(
			'extrachill/reconcile-booking-event',
			array(
				'booking_id'       => (int) $booking['id'],
				'expected_version' => (int) $converted['booking_version'],
				'changes'          => array(
					'performance_start_at' => $profile['next_date'] . ' ' . $profile['start'],
					'performance_end_at'   => $profile['next_date'] . ' ' . $profile['end'],
				),
			)
		);
		booking_network_e2e_case(
			'event-reschedule-succeeds',
			is_array( $rescheduled ) && 'succeeded' === $rescheduled['status'],
			array(
				'rescheduled' => $rescheduled,
				'code'        => booking_network_e2e_code( $rescheduled ),
			)
		);
		if ( is_array( $rescheduled ) ) {
			$cancelled = booking_network_e2e_execute(
				'extrachill/transition-venue-booking',
				array(
					'booking_id'       => (int) $booking['id'],
					'to_status'        => 'cancelled',
					'expected_version' => (int) $rescheduled['booking_version'],
				)
			);
			booking_network_e2e_case(
				'linked-cancellation-succeeds',
				is_array( $cancelled ) && 'cancelled' === $cancelled['status'],
				array(
					'cancelled' => $cancelled,
					'code'      => booking_network_e2e_code( $cancelled ),
				)
			);
			$event = get_post( (int) $converted['event_id'] );
			$attrs = array();
			foreach ( parse_blocks( $event ? $event->post_content : '' ) as $block ) {
				if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
					$attrs = $block['attrs'];
				}
			}
			$expected_local = ( new DateTimeImmutable( $profile['next_date'] . ' ' . $profile['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $profile['timezone'] ) );
			booking_network_e2e_case(
				'cancelled-event-and-timezone-align',
				'EventCancelled' === ( $attrs['eventStatus'] ?? '' ) && $expected_local->format( 'Y-m-d' ) === ( $attrs['startDate'] ?? '' ) && $expected_local->format( 'H:i' ) === ( $attrs['startTime'] ?? '' ),
				array(
					'attrs'   => $attrs,
					'profile' => $profile,
				)
			);
			$terminal_message = booking_network_e2e_execute(
				'extrachill/send-booking-message',
				array(
					'booking_id'      => (int) $booking['id'],
					'idempotency_key' => 'terminal-message',
					'template'        => 'operator_message',
					'recipient'       => 'e2e-contact@example.test',
					'message'         => 'Must not send.',
					'reply_to'        => 'booking@example.test',
				)
			);
			booking_network_e2e_case( 'terminal-booking-message-rejected', is_wp_error( $terminal_message ), array( 'code' => booking_network_e2e_code( $terminal_message ) ) );
		}
	}
}

$activity = ( new BookingActivityRepository() )->list_for_booking( (int) $booking['id'] );
$kinds    = array_column( is_array( $activity ) ? $activity : array(), 'kind' );
booking_network_e2e_case( 'activity-ledger-terminal-markers', 1 === count( array_keys( $kinds, 'event_conversion_started', true ) ) && 1 === count( array_keys( $kinds, 'event_converted', true ) ) && in_array( 'event_sync_succeeded', $kinds, true ), array( 'kinds' => $kinds ) );

$source_count = 0;
if ( isset( $converted ) && is_array( $converted ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable uniqueness assertion.
	$source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_datamachine_event_source_id' AND meta_value = %s", $first['public_id'] ) );
}
booking_network_e2e_case( 'event-source-link-unique', 1 === $source_count, array( 'count' => $source_count ) );

$config_before = booking_network_e2e_execute( 'extrachill/get-venue-booking-config', array( 'venue_term_id' => $venue_a ) );
if ( ! is_array( $config_before ) ) {
	booking_network_e2e_abort( 'config-prerequisite-missing', array( 'code' => booking_network_e2e_code( $config_before ) ) );
}
$config_input = $config_before;
unset( $config_input['revision'], $config_input['updated_by_user_id'], $config_input['updated_at'] );
$config_input['hold_ttl_minutes'] = 720;
$config_after                        = booking_network_e2e_execute(
	'extrachill/update-venue-booking-config',
	array(
		'venue_term_id'     => $venue_a,
		'expected_revision' => (int) $config_before['revision'],
		'config'            => $config_input,
	)
);
booking_network_e2e_case(
	'config-update-increments-once',
	is_array( $config_after ) && (int) $config_after['revision'] === (int) $config_before['revision'] + 1,
	array(
		'before' => $config_before['revision'],
		'after'  => is_array( $config_after ) ? $config_after['revision'] : null,
		'code'   => booking_network_e2e_code( $config_after ),
	)
);
$stale_config = booking_network_e2e_execute(
	'extrachill/update-venue-booking-config',
	array(
		'venue_term_id'     => $venue_a,
		'expected_revision' => (int) $config_before['revision'],
		'config'            => $config_input,
	)
);
booking_network_e2e_case( 'stale-config-update-conflicts', is_wp_error( $stale_config ), array( 'code' => booking_network_e2e_code( $stale_config ) ) );

$result = array(
	'schema'     => 'extrachill-events/booking-network-e2e-result/v1',
	'seed'       => $seed,
	'assertions' => count( $cases ),
	'passed'     => count(
		array_filter(
			$cases,
			static function ( $assertion_case ) {
				return $assertion_case['passed']; }
		)
	),
	'findings'   => $findings,
	'cases'      => $cases,
	'operations' => array( 'render', 'submit', 'retry', 'concurrent-cas', 'authorize', 'assign', 'transition', 'select-performance', 'update-deal', 'create-hold', 'confirm', 'convert', 'reschedule', 'cancel', 'send-message', 'update-config' ),
);
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable E2E evidence.
printf( "BOOKING_NETWORK_E2E_RESULT:%s\n", base64_encode( wp_json_encode( $result ) ) );
printf( "Booking network E2E completed: %d/%d passed, %d findings.\n", $result['passed'], $result['assertions'], count( $findings ) );
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
restore_current_blog();

if ( $findings ) {
	throw new RuntimeException( sprintf( 'Booking network E2E found %d invariant violation(s).', count( $findings ) ) );
}
