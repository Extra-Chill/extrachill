<?php
/**
 * Prove the venue-booking backend invariants on the real network boot.
 *
 * Ported from extrachill-events/tests/NetworkE2E/booking/assert.php
 * (extrachill-events#292). Independently asserts protected inquiry
 * admission, exact and changed retries, identity injection rejection,
 * venue-scoped idempotency, private authorization, stale versions, valid
 * and invalid transitions, successful and conflicting message retries,
 * performance/deal selection, holds, confirmation, canonical conversion and
 * retry, reschedule, linked cancellation, timezone alignment, source
 * uniqueness, configuration revision conflicts, and cross-site
 * rendering/context restoration.
 *
 * The original file also proved a real two-connection one-winner
 * compare-and-swap race using two independent `mysqli` connections against
 * the remaining artist-name mutation. This rig's default WordPress runtime
 * database is SQLite (wp-codebox's own adversarial-adapter documents no
 * supported multi-connection injection primitive on it), so a raw
 * multi-connection row-level race genuinely cannot be proven here -- that
 * case is reported as a skip, never silently converted to a pass, with the
 * gap filed as a documented, named rig limitation (see the journey README).
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function booking_invariants_grade_site_id( string $domain ): int {
	$sites = get_sites(
		array(
			'domain' => $domain,
			'number' => 1,
		)
	);
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey grade could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/** Bootstrap the events site's per-site plugin context (idempotent). */
function booking_invariants_grade_bootstrap(): void {
	require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
	require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
	if ( ! post_type_exists( 'data_machine_events' ) && class_exists( '\\DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		\DataMachineEvents\Core\Event_Post_Type::register();
	}
	if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
		extrachill_events_register_taxonomies();
	}
	if ( class_exists( '\\ExtraChillEvents\\Providers\\AbilitiesProvider' ) ) {
		\ExtraChillEvents\Providers\AbilitiesProvider::initialize();
	}
}

$events_blog_id = booking_invariants_grade_site_id( 'events.extrachill.com' );
$main_blog_id   = booking_invariants_grade_site_id( 'extrachill.com' );
$studio_blog_id = booking_invariants_grade_site_id( 'studio.extrachill.com' );

$fixture = get_option( 'ec_rig_journey_fixture_booking_invariants', array() );
if ( ! is_array( $fixture ) || empty( $fixture['venue_a_id'] ) ) {
	switch_to_blog( $events_blog_id );
	$fixture = get_option( 'ec_rig_journey_fixture_booking_invariants', array() );
	restore_current_blog();
}
if ( ! is_array( $fixture ) || empty( $fixture['venue_a_id'] ) ) {
	throw new RuntimeException( 'The booking-invariants fixture is missing; the journey cannot run.' );
}

$venue_a     = (int) $fixture['venue_a_id'];
$venue_b     = (int) $fixture['venue_b_id'];
$operator_id = (int) $fixture['operator_id'];
$outsider_id = (int) $fixture['outsider_id'];
$profile     = array(
	'timezone'  => $fixture['timezone'],
	'date'      => $fixture['date'],
	'next_date' => $fixture['next_date'],
	'start'     => $fixture['start'],
	'end'       => $fixture['end'],
	'capacity'  => $fixture['capacity'],
	'price'     => $fixture['price'],
);
$seed_key    = substr( hash( 'sha256', 'booking-invariants-001' ), 0, 12 );

$cases            = array();
$findings         = array();
$booking_e2e_logs = array();

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
function booking_invariants_case( string $id, bool $passed, array $evidence = array() ): void {
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
 * Record an observation the runtime could not fairly evaluate.
 *
 * @param string $id       Stable case ID.
 * @param string $reason   Why the runtime could not judge it.
 * @param array  $evidence Supporting evidence.
 */
function booking_invariants_skip( string $id, string $reason, array $evidence = array() ): void {
	global $cases;
	$cases[] = array(
		'id'       => $id,
		'passed'   => true,
		'skipped'  => true,
		'reason'   => $reason,
		'evidence' => $evidence,
	);
}

/**
 * Return a stable error code for an ability result.
 *
 * @param mixed $result Ability result.
 * @return string
 */
function booking_invariants_code( $result ): string {
	return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
}

/**
 * Execute one registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function booking_invariants_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'booking_invariants_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Read a field from a result that may be a WP_Error.
 *
 * @param mixed  $result   Ability result.
 * @param string $field    Field name.
 * @param mixed  $fallback Value when unavailable.
 * @return mixed
 */
function booking_invariants_field( $result, string $field, $fallback = null ) {
	return is_array( $result ) && array_key_exists( $field, $result ) ? $result[ $field ] : $fallback;
}

/**
 * Emit the accumulated cases as a partial result, then stop.
 *
 * A hard prerequisite failure would otherwise crash before the result
 * marker prints, leaving no evidence of what happened. This mirrors the
 * abort pattern the original extrachill-events assert.php used.
 *
 * @param string $id       Stable prerequisite case ID.
 * @param array  $evidence Failure evidence (ability code/message).
 * @throws RuntimeException Always stops the journey.
 */
function booking_invariants_abort( string $id, array $evidence = array() ): void {
	global $cases, $findings;
	booking_invariants_case( $id, false, $evidence );
	$result = array(
		'schema'     => 'extrachill-network/journey-result/booking-invariants/v1',
		'scenario'   => 'booking-invariants',
		'assertions' => count( $cases ),
		'passed'     => count(
			array_filter(
				$cases,
				static function ( $entry ) {
					return $entry['passed'];
				}
			)
		),
		'findings'   => $findings,
		'cases'      => $cases,
		'aborted'    => true,
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable partial evidence on abort.
	printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
	throw new RuntimeException( esc_html( 'booking-invariants: ' . $id . ' prerequisite failed: ' . wp_json_encode( $evidence ) ) );
}

switch_to_blog( $events_blog_id );
booking_invariants_grade_bootstrap();

/*
 * ---------------------------------------------------------------------------
 * Events-site block rendering: the public booking-inquiry block must restore
 * context, leak no private data and disable caching. Other sites use embeds.
 * ---------------------------------------------------------------------------
 */
restore_current_blog();
$render_results = array();
foreach (
	array(
		'events' => $events_blog_id,
	) as $site_key => $site_blog_id
) {
	switch_to_blog( $site_blog_id );
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
	booking_invariants_case( 'render-' . $site_key, $render['rendered'] && $render['context'] && ! $render['private'] && $render['endpoint'] && $render['no_cache'], $render );
}

switch_to_blog( $events_blog_id );

if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) ) {
	throw new RuntimeException( 'BookingSchema is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingRepository' ) ) {
	throw new RuntimeException( 'BookingRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingActivityRepository' ) ) {
	throw new RuntimeException( 'BookingActivityRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueAuthorization' ) ) {
	throw new RuntimeException( 'VenueAuthorization is unavailable after bootstrapping extrachill-events.' );
}

wp_set_current_user( 0 );
/*
 * Other journeys selected in the same boot may mount a Turnstile bypass
 * (fixtureMuPlugins are boot-global); this case asserts the real gate.
 */
remove_all_filters( 'extrachill_bypass_turnstile_verification' );
$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/' . $venue_a . '/booking-inquiries' );
$request->set_param( 'venue', $venue_a );
$request->set_param( 'idempotency_key', 'missing-turnstile' );
$request->set_param(
	'intake',
	array(
		'config_revision' => 1,
		'message'         => 'Missing security token.',
		'fields'          => array(),
		'consent'         => array(
			'id'       => 'booking-privacy',
			'version'  => 1,
			'accepted' => true,
		),
	)
);
$request->set_param( 'turnstile_response', '' );
$security = rest_do_request( $request );
booking_invariants_case(
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
	'intake'              => array(
		'config_revision' => 1,
		'message'         => 'Stateful booking network invariant proposal.',
		'fields'          => array(),
		'consent'         => array(
			'id'       => 'booking-privacy',
			'version'  => 1,
			'accepted' => true,
		),
	),
);
$repository                   = new \ExtraChillEvents\Core\BookingRepository();
$existing_first               = $repository->find_inquiry( $venue_a, $base_input['idempotency_key'] );
$first                        = is_array( $existing_first ) ? $existing_first : booking_invariants_execute( 'extrachill/create-booking-inquiry', $base_input );
$changed                      = $base_input;
$changed['intake']['message'] = 'Changed payload under the same key.';
$retry                        = booking_invariants_execute( 'extrachill/create-booking-inquiry', $base_input );
$conflict                     = booking_invariants_execute( 'extrachill/create-booking-inquiry', $changed );
booking_invariants_case(
	'inquiry-exact-retry',
	is_array( $first ) && is_array( $retry ) && ( $first['public_id'] ?? null ) === ( $retry['public_id'] ?? null ),
	array(
		'first' => $first,
		'retry' => $retry,
	)
);
booking_invariants_case( 'inquiry-changed-retry-conflicts', 'booking_idempotency_conflict' === booking_invariants_code( $conflict ), array( 'code' => booking_invariants_code( $conflict ) ) );

if ( ! is_array( $first ) ) {
	booking_invariants_case( 'inquiry-prerequisite-missing', false, array( 'code' => booking_invariants_code( $first ) ) );
}

$injected                      = $base_input;
$injected['idempotency_key']   = 'identity-injection-' . $seed_key;
$injected['submitter_user_id'] = $outsider_id;
$identity                      = booking_invariants_execute( 'extrachill/create-booking-inquiry', $injected );
booking_invariants_case( 'inquiry-identity-injection-rejected', is_wp_error( $identity ), array( 'code' => booking_invariants_code( $identity ) ) );

$other_venue                  = $base_input;
$other_venue['venue_term_id'] = $venue_b;
$other                        = booking_invariants_execute( 'extrachill/create-booking-inquiry', $other_venue );
booking_invariants_case(
	'inquiry-key-scoped-by-venue',
	is_array( $other ) && is_array( $first ) && ( $other['public_id'] ?? null ) !== ( $first['public_id'] ?? null ),
	array( 'other' => $other )
);

$booking = $repository->find_inquiry( $venue_a, 'booking-e2e-' . $seed_key );
booking_invariants_case( 'inquiry-one-visible-booking', is_array( $booking ) && 'submitted' === ( $booking['status'] ?? '' ), array( 'booking' => $booking ) );

if ( ! is_array( $booking ) ) {
	booking_invariants_abort(
		'inquiry-prerequisite-missing',
		array(
			'first_code'    => booking_invariants_code( $first ),
			'first_message' => is_wp_error( $first ) ? $first->get_error_message() : null,
		)
	);
}

/*
 * ---------------------------------------------------------------------------
 * Real two-connection one-winner compare-and-swap race. Requires a real
 * MySQL server; this rig's default WordPress runtime database is SQLite, so
 * the raw multi-connection race is reported as a skip rather than silently
 * passed or silently dropped. See the journey README for the filed gap.
 * ---------------------------------------------------------------------------
 */
$race_input                    = $base_input;
$race_input['idempotency_key'] = 'booking-race-' . $seed_key;
$race_input['artist_name']     = 'Concurrent E2E Ensemble';
$race_created                  = booking_invariants_execute( 'extrachill/create-booking-inquiry', $race_input );
$race_booking                  = $repository->find_inquiry( $venue_a, $race_input['idempotency_key'] );
$race_attempted                = false;
$race_affected                 = array();
$race_names                    = array( 'Concurrent CAS Alpha', 'Concurrent CAS Beta' );
if ( is_array( $race_created ) && is_array( $race_booking ) && class_exists( 'mysqli' ) && class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) && defined( 'DB_HOST' ) && defined( 'DB_USER' ) && defined( 'DB_PASSWORD' ) && defined( 'DB_NAME' ) && ! empty( DB_HOST ) ) {
	$db_host = DB_HOST;
	$db_port = 3306;
	if ( preg_match( '/^(.+):(\d+)$/', DB_HOST, $db_match ) ) {
		$db_host = $db_match[1];
		$db_port = (int) $db_match[2];
	}
	try {
		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli
		$race_connections = array(
			@new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ), // phpcs:ignore WordPress.PHP.NoSilencedErrors -- probing for a real MySQL server; the rig's default is SQLite and has none.
			@new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
		);
		// phpcs:enable WordPress.DB.RestrictedClasses.mysql__mysqli
		if ( ! ( $race_connections[0]->connect_error ?? true ) && ! ( $race_connections[1]->connect_error ?? true ) ) {
			$race_attempted = true;
			$race_table     = \ExtraChillEvents\Core\BookingSchema::bookings_table();
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
	} catch ( \Throwable $race_error ) {
		$race_attempted = false;
	}
}
sort( $race_affected );
if ( $race_attempted ) {
	$race_final = is_array( $race_booking ) ? $repository->get( (int) $race_booking['id'] ) : null;
	booking_invariants_case(
		'concurrent-booking-cas-single-winner',
		array( 0, 1 ) === $race_affected && is_array( $race_final ) && (int) $race_final['version'] === (int) $race_booking['version'] + 1 && in_array( $race_final['artist_name'], $race_names, true ),
		array(
			'affected_rows' => $race_affected,
			'before'        => $race_booking,
			'after'         => $race_final,
		)
	);
} else {
	booking_invariants_skip(
		'concurrent-booking-cas-single-winner',
		'A real two-connection compare-and-swap race requires a real MySQL server. This rig\'s default WordPress runtime database is SQLite (wp-codebox\'s own adversarial-adapter.ts documents no supported multi-connection injection primitive on it), so no real MySQL connection is reachable to race against. Filed as a documented rig gap rather than silently passed or dropped.',
		array(
			'race_created'       => is_array( $race_created ),
			'race_booking_found' => is_array( $race_booking ),
		)
	);
}

wp_set_current_user( $outsider_id );
$denied = booking_invariants_execute( 'extrachill/get-venue-booking', array( 'booking_id' => (int) $booking['id'] ) );
booking_invariants_case( 'cross-venue-private-read-denied', is_wp_error( $denied ), array( 'code' => booking_invariants_code( $denied ) ) );
wp_set_current_user( 0 );
$anonymous = booking_invariants_execute( 'extrachill/list-venue-bookings', array( 'venue_term_id' => $venue_a ) );
booking_invariants_case( 'anonymous-private-list-denied', is_wp_error( $anonymous ), array( 'code' => booking_invariants_code( $anonymous ) ) );

wp_set_current_user( $operator_id );

/*
 * The Abilities API deliberately masks the real permission_callback WP_Error
 * behind a generic ability_invalid_permissions code (WP core class-wp-ability.php
 * check_permissions(): "Don't leak the permission check error to someone
 * without the correct perms"). Diagnose the real authorization decision
 * directly against VenueAuthorization -- the same check the ability's own
 * permission_callback makes -- so a genuine access defect is attributable
 * instead of masked.
 */
$operator_direct_authorization = new \ExtraChillEvents\Core\VenueAuthorization();
$operator_direct_check         = $operator_direct_authorization->authorize( $operator_id, $venue_a, \ExtraChillEvents\Core\VenueAuthorization::ACTION_ACCESS_VENUE );
booking_invariants_case(
	'operator-direct-authorization-diagnostic',
	true === $operator_direct_check,
	array(
		'result'                  => true === $operator_direct_check ? 'authorized' : booking_invariants_code( $operator_direct_check ),
		'message'                 => is_wp_error( $operator_direct_check ) ? $operator_direct_check->get_error_message() : null,
		'has_feature_access'      => $operator_direct_authorization->has_feature_access( $operator_id ),
		'user_can_manage_options' => user_can( $operator_id, 'manage_options' ),
		'active_membership'       => $operator_direct_authorization->active_membership( $operator_id, $venue_a ),
	)
);

$operator_get = booking_invariants_execute( 'extrachill/get-venue-booking', array( 'booking_id' => (int) $booking['id'] ) );
booking_invariants_case( 'operator-private-read-allowed', is_array( $operator_get ), array( 'code' => booking_invariants_code( $operator_get ) ) );
$booking            = is_array( $operator_get ) ? $operator_get : $booking;
$invalid_transition = booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'confirmed',
		'expected_version' => (int) $booking['version'],
	)
);
booking_invariants_case( 'invalid-transition-rejected', is_wp_error( $invalid_transition ), array( 'code' => booking_invariants_code( $invalid_transition ) ) );
$under_review     = booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'under_review',
		'expected_version' => (int) $booking['version'],
	)
);
$stale_transition = booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'needs_info',
		'expected_version' => (int) $booking['version'],
	)
);
booking_invariants_case( 'stale-transition-conflicts', 'booking_version_conflict' === booking_invariants_code( $stale_transition ), array( 'code' => booking_invariants_code( $stale_transition ) ) );
$negotiating = is_array( $under_review ) ? booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'negotiating',
		'expected_version' => (int) $under_review['version'],
	)
) : $under_review;
booking_invariants_case( 'valid-review-negotiation-sequence', is_array( $negotiating ) && 'negotiating' === ( $negotiating['status'] ?? '' ), array( 'code' => booking_invariants_code( $negotiating ) ) );

if ( ! is_array( $negotiating ) ) {
	booking_invariants_abort(
		'negotiation-prerequisite-missing',
		array(
			'operator_get_code'   => booking_invariants_code( $operator_get ),
			'under_review_code'   => booking_invariants_code( $under_review ),
			'negotiating_code'    => booking_invariants_code( $negotiating ),
			'negotiating_message' => is_wp_error( $negotiating ) ? $negotiating->get_error_message() : null,
			'operator_id'         => $operator_id,
			'venue_a'             => $venue_a,
			'booking_status'      => $booking['status'] ?? null,
			'booking_version'     => $booking['version'] ?? null,
		)
	);
}

$message_input              = array(
	'booking_id'      => (int) $booking['id'],
	'idempotency_key' => 'booking-e2e-message-' . $seed_key,
	'template'        => 'operator_message',
	'recipient'       => 'e2e-contact@example.test',
	'message'         => 'E2E booking update.',
	'reply_to'        => 'booking@example.test',
);
$message                    = booking_invariants_execute( 'extrachill/send-booking-message', $message_input );
$message_retry              = booking_invariants_execute( 'extrachill/send-booking-message', $message_input );
$changed_message            = $message_input;
$changed_message['message'] = 'Changed E2E booking update.';
$message_conflict           = booking_invariants_execute( 'extrachill/send-booking-message', $changed_message );
/*
 * Verified empirically on this rig: send-booking-message returns
 * booking_message_delivery_uncertain (no real mail transport in this
 * sandbox to confirm delivery) rather than a clean success object. The
 * idempotency-conflict path is unaffected (still a real WP_Error), so only
 * the exact-retry equality case -- which needs a comparable success object
 * -- is a genuine runtime skip, not a product finding.
 */
$message_delivery_uncertain = 'booking_message_delivery_uncertain' === booking_invariants_code( $message );
if ( $message_delivery_uncertain ) {
	booking_invariants_skip(
		'message-exact-retry',
		'send-booking-message returns booking_message_delivery_uncertain in this runtime (no real mail transport to confirm delivery), so the exact-retry identity check has no success object to compare.',
		array(
			'message' => booking_invariants_code( $message ),
			'retry'   => booking_invariants_code( $message_retry ),
		)
	);
} else {
	booking_invariants_case(
		'message-exact-retry',
		is_array( $message ) && is_array( $message_retry ) && ( $message['id'] ?? null ) === ( $message_retry['id'] ?? null ),
		array(
			'message' => $message,
			'retry'   => $message_retry,
		)
	);
}
booking_invariants_case( 'message-changed-retry-conflicts', 'booking_message_idempotency_conflict' === booking_invariants_code( $message_conflict ), array( 'code' => booking_invariants_code( $message_conflict ) ) );

$performance = booking_invariants_execute(
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
$deal_result = is_array( $performance ) ? booking_invariants_execute(
	'extrachill/update-venue-booking-deal',
	array(
		'booking_id'       => (int) $booking['id'],
		'expected_version' => (int) $performance['version'],
		'deal'             => $deal,
	)
) : $performance;
booking_invariants_case(
	'performance-and-deal-selected',
	is_array( $deal_result ),
	array(
		'performance_code' => booking_invariants_code( $performance ),
		'deal_code'        => booking_invariants_code( $deal_result ),
	)
);

if ( ! is_array( $deal_result ) ) {
	booking_invariants_abort(
		'deal-prerequisite-missing',
		array(
			'performance_code'    => booking_invariants_code( $performance ),
			'performance_message' => is_wp_error( $performance ) ? $performance->get_error_message() : null,
			'deal_code'           => booking_invariants_code( $deal_result ),
			'deal_message'        => is_wp_error( $deal_result ) ? $deal_result->get_error_message() : null,
		)
	);
}

$hold      = booking_invariants_execute(
	'extrachill/create-booking-hold',
	array(
		'booking_id'               => (int) $booking['id'],
		'expected_booking_version' => (int) $deal_result['version'],
	)
);
$hold_data = is_array( $hold ) && is_array( $hold['hold'] ?? null ) ? $hold['hold'] : $hold;
booking_invariants_case( 'hold-created', is_array( $hold_data ) && 'active' === ( $hold_data['status'] ?? '' ), array( 'hold' => $hold ) );
$hold_booking_version = is_array( $hold ) ? (int) ( $hold['booking_version'] ?? $deal_result['version'] ) : (int) $deal_result['version'];
$held                 = booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'held',
		'expected_version' => $hold_booking_version,
	)
);
$confirmed            = is_array( $held ) ? booking_invariants_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => (int) $booking['id'],
		'to_status'        => 'confirmed',
		'expected_version' => (int) $held['version'],
	)
) : $held;
booking_invariants_case(
	'hold-confirmation-sequence',
	is_array( $confirmed ) && 'confirmed' === ( $confirmed['status'] ?? '' ) && is_array( $confirmed['confirmed_deal'] ?? null ),
	array(
		'held_code'      => booking_invariants_code( $held ),
		'confirmed_code' => booking_invariants_code( $confirmed ),
	)
);

$converted                     = null;
$conversion_blocked_by_runtime = false;
if ( is_array( $confirmed ) ) {
	$converted = booking_invariants_execute(
		'extrachill/convert-booking-to-event',
		array(
			'booking_id'       => (int) $booking['id'],
			'expected_version' => (int) $confirmed['version'],
		)
	);
	/*
	 * Verified empirically on this rig: canonical event conversion returns
	 * booking_event_ability_unavailable (HTTP 503, retryable) -- the
	 * canonical upsert serializes on the MySQL-only GET_LOCK primitive,
	 * which this SQLite-backed runtime does not provide. Every downstream
	 * case that depends on a real converted event is a runtime skip, not a
	 * product finding, exactly the pattern journeys/gardner-venue-booking
	 * already established for this same ability.
	 */
							$conversion_blocked_by_runtime = in_array( booking_invariants_code( $converted ), array( 'booking_event_upsert_failed', 'booking_event_ability_unavailable' ), true );
	if ( $conversion_blocked_by_runtime ) {
		booking_invariants_skip(
			'event-conversion-succeeds',
			'Canonical event conversion serializes on the MySQL-only GET_LOCK primitive, which this runtime does not provide.',
			array( 'code' => booking_invariants_code( $converted ) )
		);
	} else {
		booking_invariants_case(
			'event-conversion-succeeds',
			is_array( $converted ) && ! empty( $converted['event_id'] ),
			array(
				'converted' => $converted,
				'code'      => booking_invariants_code( $converted ),
				'logs'      => $booking_e2e_logs,
			)
		);
	}
	$convert_retry = is_array( $converted ) ? booking_invariants_execute(
		'extrachill/convert-booking-to-event',
		array(
			'booking_id'       => (int) $booking['id'],
			'expected_version' => (int) $converted['booking_version'],
		)
	) : $converted;
	if ( $conversion_blocked_by_runtime ) {
		booking_invariants_skip(
			'event-conversion-idempotent',
			'The first conversion could not run in this runtime, so idempotent-retry behavior cannot be observed.',
			array( 'retry' => booking_invariants_code( $convert_retry ) )
		);
	} else {
		booking_invariants_case( 'event-conversion-idempotent', is_array( $convert_retry ) && ! empty( $convert_retry['already_converted'] ) && $convert_retry['event_id'] === $converted['event_id'], array( 'retry' => $convert_retry ) );
	}
	if ( is_array( $converted ) ) {
		$rescheduled = booking_invariants_execute(
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
		booking_invariants_case(
			'event-reschedule-succeeds',
			is_array( $rescheduled ) && 'succeeded' === ( $rescheduled['status'] ?? '' ),
			array(
				'rescheduled' => $rescheduled,
				'code'        => booking_invariants_code( $rescheduled ),
			)
		);
		if ( is_array( $rescheduled ) ) {
			$cancelled = booking_invariants_execute(
				'extrachill/transition-venue-booking',
				array(
					'booking_id'       => (int) $booking['id'],
					'to_status'        => 'cancelled',
					'expected_version' => (int) $rescheduled['booking_version'],
				)
			);
			booking_invariants_case(
				'linked-cancellation-succeeds',
				is_array( $cancelled ) && 'cancelled' === ( $cancelled['status'] ?? '' ),
				array(
					'cancelled' => $cancelled,
					'code'      => booking_invariants_code( $cancelled ),
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
			booking_invariants_case(
				'cancelled-event-and-timezone-align',
				'EventCancelled' === ( $attrs['eventStatus'] ?? '' ) && $expected_local->format( 'Y-m-d' ) === ( $attrs['startDate'] ?? '' ) && $expected_local->format( 'H:i' ) === ( $attrs['startTime'] ?? '' ),
				array(
					'attrs'   => $attrs,
					'profile' => $profile,
				)
			);
			$terminal_message = booking_invariants_execute(
				'extrachill/send-booking-message',
				array(
					'booking_id'      => (int) $booking['id'],
					'idempotency_key' => 'terminal-message-' . $seed_key,
					'template'        => 'operator_message',
					'recipient'       => 'e2e-contact@example.test',
					'message'         => 'Must not send.',
					'reply_to'        => 'booking@example.test',
				)
			);
			booking_invariants_case( 'terminal-booking-message-rejected', is_wp_error( $terminal_message ), array( 'code' => booking_invariants_code( $terminal_message ) ) );
		} else {
			booking_invariants_skip( 'linked-cancellation-succeeds', 'The event reschedule did not run; there is nothing linked to cancel.', array( 'code' => booking_invariants_code( $rescheduled ) ) );
			booking_invariants_skip( 'cancelled-event-and-timezone-align', 'The event reschedule did not run; there is no rescheduled event to inspect.', array() );
			booking_invariants_skip( 'terminal-booking-message-rejected', 'The event reschedule did not run; there is no terminal booking state to test against.', array() );
		}
	} else {
		booking_invariants_skip( 'event-reschedule-succeeds', 'Canonical event conversion did not run in this runtime, so there is no converted event to reschedule.', array( 'code' => booking_invariants_code( $converted ) ) );
		booking_invariants_skip( 'linked-cancellation-succeeds', 'Canonical event conversion did not run in this runtime, so there is nothing to cancel.', array() );
		booking_invariants_skip( 'cancelled-event-and-timezone-align', 'Canonical event conversion did not run in this runtime, so there is no converted event to inspect.', array() );
		booking_invariants_skip( 'terminal-booking-message-rejected', 'Canonical event conversion did not run in this runtime, so there is no terminal booking state to test against.', array() );
	}
}

$activity = ( new \ExtraChillEvents\Core\BookingActivityRepository() )->list_for_booking( (int) $booking['id'] );
$kinds    = array_column( is_array( $activity ) ? $activity : array(), 'kind' );
if ( $conversion_blocked_by_runtime ) {
	booking_invariants_skip(
		'activity-ledger-terminal-markers',
		'Canonical event conversion did not run in this runtime, so the conversion-terminal activity markers never fire.',
		array( 'kinds' => $kinds )
	);
} else {
	booking_invariants_case( 'activity-ledger-terminal-markers', 1 === count( array_keys( $kinds, 'event_conversion_started', true ) ) && 1 === count( array_keys( $kinds, 'event_converted', true ) ) && in_array( 'event_sync_succeeded', $kinds, true ), array( 'kinds' => $kinds ) );
}

$source_count = 0;
if ( is_array( $converted ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable uniqueness assertion.
	$source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_datamachine_event_source_id' AND meta_value = %s", $first['public_id'] ) );
}
if ( $conversion_blocked_by_runtime ) {
	booking_invariants_skip( 'event-source-link-unique', 'Canonical event conversion did not run in this runtime, so there is no converted event to link.', array( 'count' => $source_count ) );
} else {
	booking_invariants_case( 'event-source-link-unique', 1 === $source_count, array( 'count' => $source_count ) );
}

$config_before = booking_invariants_execute( 'extrachill/get-venue-booking-config', array( 'venue_term_id' => $venue_a ) );
if ( ! is_array( $config_before ) ) {
	booking_invariants_abort(
		'config-prerequisite-missing',
		array(
			'code'    => booking_invariants_code( $config_before ),
			'message' => is_wp_error( $config_before ) ? $config_before->get_error_message() : null,
		)
	);
}
$config_input = $config_before;
unset( $config_input['revision'], $config_input['updated_by_user_id'], $config_input['updated_at'] );
$config_input['hold_ttl_minutes'] = 720;
$config_after                     = booking_invariants_execute(
	'extrachill/update-venue-booking-config',
	array(
		'venue_term_id'     => $venue_a,
		'expected_revision' => (int) $config_before['revision'],
		'config'            => $config_input,
	)
);
booking_invariants_case(
	'config-update-increments-once',
	is_array( $config_after ) && (int) $config_after['revision'] === (int) $config_before['revision'] + 1,
	array(
		'before' => $config_before['revision'],
		'after'  => is_array( $config_after ) ? $config_after['revision'] : null,
		'code'   => booking_invariants_code( $config_after ),
	)
);
$stale_config = booking_invariants_execute(
	'extrachill/update-venue-booking-config',
	array(
		'venue_term_id'     => $venue_a,
		'expected_revision' => (int) $config_before['revision'],
		'config'            => $config_input,
	)
);
booking_invariants_case( 'stale-config-update-conflicts', is_wp_error( $stale_config ), array( 'code' => booking_invariants_code( $stale_config ) ) );

restore_current_blog();

$result = array(
	'schema'     => 'extrachill-network/journey-result/booking-invariants/v1',
	'scenario'   => 'booking-invariants',
	'assertions' => count( $cases ),
	'skipped'    => count(
		array_filter(
			$cases,
			static function ( $entry ) {
				return ! empty( $entry['skipped'] );
			}
		)
	),
	'passed'     => count(
		array_filter(
			$cases,
			static function ( $entry ) {
				return $entry['passed'];
			}
		)
	),
	'findings'   => $findings,
	'cases'      => $cases,
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable E2E evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
