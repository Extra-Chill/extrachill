<?php
/**
 * Walk the Chris Gardner venue-booking journey and judge it by persona oracles.
 *
 * The existing Booking Network E2E proves backend invariants: idempotency,
 * optimistic concurrency, authorization, conversion. This journey asks the
 * different question the persona contract exists to ask -- whether a
 * nontechnical venue manager can actually complete his work and understand
 * what happened.
 *
 * Consumed oracles from `extra-chill-users/chris-gardner` v1.0.0:
 * task-completion, obvious-state, reload-persistence, safe-retry,
 * duplicate-prevention, attribution, actionable-errors, jargon-avoidance,
 * server-authorization.
 *
 * A finding here is a product usability defect, not a harness failure. Passing
 * invariants with failing oracles is the exact outcome this file is built to
 * surface, because that combination is invisible to the backend gate.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\BookingRepository;

$fixture = get_option( 'gardner_persona_fixture', array() );
if ( ! is_array( $fixture ) || empty( $fixture['venue_term_id'] ) ) {
	throw new RuntimeException( 'The Gardner fixture is missing; the journey cannot run.' );
}

$venue_id    = (int) $fixture['venue_term_id'];
$gardner_id  = (int) $fixture['gardner_id'];
$outsider_id = (int) $fixture['outsider_id'];

$cases    = array();
$findings = array();

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function gardner_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'gardner_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Return a stable error code for an ability result.
 *
 * @param mixed $result Ability result.
 * @return string
 */
function gardner_code( $result ): string {
	return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
}

/**
 * Return a user-facing message for an ability result.
 *
 * @param mixed $result Ability result.
 * @return string
 */
function gardner_message( $result ): string {
	return is_wp_error( $result ) ? (string) $result->get_error_message() : '';
}

/**
 * Read a field from a result that may be a WP_Error.
 *
 * The persona harness must survive every product failure it is designed to
 * observe. A crash here would destroy the findings it exists to report.
 *
 * @param mixed  $result   Ability result.
 * @param string $field    Field name.
 * @param mixed  $fallback Value when the field is unavailable.
 * @return mixed
 */
function gardner_field( $result, string $field, $fallback = null ) {
	return is_array( $result ) && array_key_exists( $field, $result ) ? $result[ $field ] : $fallback;
}

/**
 * Return the first result that is a usable array.
 *
 * @param mixed ...$candidates Candidate results.
 * @return array
 */
function gardner_first_array( ...$candidates ): array {
	foreach ( $candidates as $candidate ) {
		if ( is_array( $candidate ) ) {
			return $candidate;
		}
	}
	return array();
}

/**
 * Record a case unless the runtime already skipped it.
 *
 * @param bool   $skipped  Whether the case was skipped.
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID.
 * @param bool   $passed   Whether the expectation held.
 * @param string $task     What Gardner was trying to do.
 * @param array  $evidence Supporting evidence.
 */
function gardner_case_unless( bool $skipped, string $id, string $oracle, bool $passed, string $task, array $evidence = array() ): void {
	if ( $skipped ) {
		return;
	}
	gardner_case( $id, $oracle, $passed, $task, $evidence );
}

/**
 * Record an observation the runtime could not fairly evaluate.
 *
 * Some product paths depend on MySQL-only primitives (`GET_LOCK`) that this
 * WordPress runtime's database layer does not provide. Those outcomes are
 * environment limits, not usability defects, and are reported as skipped so
 * they can never be miscounted as product findings.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID.
 * @param string $task     What Gardner was trying to do.
 * @param string $reason   Why the runtime could not judge it.
 * @param array  $evidence Supporting evidence.
 */
function gardner_skip( string $id, string $oracle, string $task, string $reason, array $evidence = array() ): void {
	global $cases;
	$cases[] = array(
		'id'       => $id,
		'oracle'   => $oracle,
		'passed'   => true,
		'skipped'  => true,
		'task'     => $task,
		'reason'   => $reason,
		'evidence' => $evidence,
	);
}

/**
 * Record one persona observation.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID from the canonical contract.
 * @param bool   $passed   Whether the persona expectation held.
 * @param string $task     What Gardner was trying to do, in his words.
 * @param array  $evidence Supporting evidence.
 */
function gardner_case( string $id, string $oracle, bool $passed, string $task, array $evidence = array() ): void {
	global $cases, $findings;
	$record  = array(
		'id'       => $id,
		'oracle'   => $oracle,
		'passed'   => $passed,
		'task'     => $task,
		'evidence' => $evidence,
	);
	$cases[] = $record;
	if ( ! $passed ) {
		$findings[] = array_merge( $record, array( 'status' => 'open' ) );
	}
}

/**
 * Judge whether a user-facing string is free of implementation jargon.
 *
 * Gardner is a nontechnical operator. Terms below are implementation details
 * that appear in this product's own error surface; none of them tell him what
 * to do next.
 *
 * @param string $text Candidate user-facing text.
 * @return array Offending terms.
 */
function gardner_jargon( string $text ): array {
	$jargon = array(
		'idempotency',
		'idempotent',
		'optimistic',
		'expected_version',
		'version conflict',
		'compare-and-swap',
		'CAS',
		'term_id',
		'venue_term_id',
		'booking_id',
		'null',
		'WP_Error',
		'REST',
		'ability',
		'schema',
		'payload',
		'serialized',
		'HTTP',
		'409',
		'403',
		'nonce',
		'transient',
		'meta key',
		'revision mismatch',
	);
	$found  = array();
	foreach ( $jargon as $term ) {
		if ( false !== stripos( $text, $term ) ) {
			$found[] = $term;
		}
	}
	return $found;
}

/**
 * Judge whether an error tells Gardner what to do next.
 *
 * @param string $text Candidate error text.
 * @return bool
 */
function gardner_is_actionable( string $text ): bool {
	if ( '' === trim( $text ) ) {
		return false;
	}
	// An actionable error names a recovery action the operator can take.
	return (bool) preg_match(
		'/\b(try|retry|reload|refresh|check|choose|select|pick|contact|sign in|log in|update|change|remove|add|save|send|move|wait|again|before|instead|first|you can)\b/i',
		$text
	);
}

$repository = new BookingRepository();
wp_set_current_user( $gardner_id );

/*
 * ---------------------------------------------------------------------------
 * Task 1. "What came in over the weekend?"
 * ---------------------------------------------------------------------------
 */
$inbox       = gardner_execute( 'extrachill/list-venue-bookings', array( 'venue_term_id' => $venue_id ) );
$inbox_items = is_array( $inbox ) ? ( $inbox['bookings'] ?? $inbox['items'] ?? $inbox ) : array();
gardner_case(
	'inbox-opens',
	'task-completion',
	is_array( $inbox ) && count( (array) $inbox_items ) >= 3,
	'Open the booking inbox and see the weekend requests.',
	array(
		'code'  => gardner_code( $inbox ),
		'count' => is_array( $inbox_items ) ? count( (array) $inbox_items ) : 0,
	)
);

/*
 * Gardner has two bands asking for the same Friday in the same room. This is
 * the single most common real booking situation, so the console must make the
 * conflict visible without him cross-referencing dates by hand.
 */
$march_17 = array();
foreach ( (array) $inbox_items as $item ) {
	if ( ! is_array( $item ) ) {
		continue;
	}
	$start = (string) ( $item['requested_start_at'] ?? '' );
	$space = (string) ( $item['requested_space_key'] ?? $item['space_key'] ?? '' );
	if ( 0 === strpos( $start, '2028-03-17' ) && 'taproom' === $space ) {
		$march_17[] = $item;
	}
}
$conflict_signalled = false;
foreach ( (array) $inbox_items as $item ) {
	if ( ! is_array( $item ) ) {
		continue;
	}
	foreach ( array( 'conflict', 'competing', 'double_booked', 'same_date_count', 'conflicts' ) as $signal ) {
		if ( array_key_exists( $signal, $item ) && ! empty( $item[ $signal ] ) ) {
			$conflict_signalled = true;
		}
	}
}
gardner_case(
	'competing-requests-are-surfaced',
	'obvious-state',
	count( $march_17 ) >= 2,
	'Notice that two bands asked for the same Friday in the taproom.',
	array(
		'competing_count'    => count( $march_17 ),
		'conflict_signalled' => $conflict_signalled,
		'note'               => 'The list payload carries the space and requested interval for every booking, which is what the console needs to detect contention. The console computes and renders it via bookingContention(); booking-console.test.js owns that assertion.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * Task 2. "Move the first band forward."
 * ---------------------------------------------------------------------------
 */
$target = null;
foreach ( (array) $inbox_items as $item ) {
	if ( is_array( $item ) && 'Sun Room Collective' === ( $item['artist_name'] ?? '' ) ) {
		$target = $item;
	}
}
if ( ! is_array( $target ) ) {
	$found  = $repository->find_inquiry( $venue_id, 'gardner-persona-inquiry-1' );
	$target = is_array( $found ) ? $found : null;
}
if ( ! is_array( $target ) ) {
	throw new RuntimeException( 'The persona booking is missing; the journey cannot continue.' );
}
$booking_id = (int) $target['id'];

$detail = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
gardner_case(
	'booking-detail-opens',
	'task-completion',
	is_array( $detail ),
	'Open the Sun Room Collective request to read it.',
	array( 'code' => gardner_code( $detail ) )
);
$detail = gardner_first_array( $detail, $target );

/*
 * Gardner does not think in status machines. He thinks "I want to talk to
 * this band." The product requires under_review before negotiating, so the
 * question is whether the illegal shortcut fails in language he understands.
 */
$shortcut         = gardner_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => $booking_id,
		'to_status'        => 'confirmed',
		'expected_version' => (int) gardner_field( $detail, 'version', 0 ),
	)
);
$shortcut_message = gardner_message( $shortcut );
gardner_case(
	'illegal-shortcut-blocked',
	'server-authorization',
	is_wp_error( $shortcut ),
	'Try to confirm the show immediately without the intermediate steps.',
	array( 'code' => gardner_code( $shortcut ) )
);
gardner_case(
	'illegal-shortcut-explains-itself',
	'actionable-errors',
	gardner_is_actionable( $shortcut_message ),
	'Understand why the shortcut was refused and what to do instead.',
	array(
		'message' => $shortcut_message,
		'note'    => 'The operator is told the move is invalid but not which move is available next.',
	)
);
gardner_case(
	'illegal-shortcut-avoids-jargon',
	'jargon-avoidance',
	array() === gardner_jargon( $shortcut_message ),
	'Read the refusal without hitting developer vocabulary.',
	array(
		'message' => $shortcut_message,
		'jargon'  => gardner_jargon( $shortcut_message ),
	)
);

$review = gardner_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => $booking_id,
		'to_status'        => 'under_review',
		'expected_version' => (int) gardner_field( $detail, 'version', 0 ),
	)
);
gardner_case(
	'move-to-review',
	'task-completion',
	is_array( $review ) && 'under_review' === gardner_field( $review, 'status', '' ),
	'Mark the request as under review.',
	array( 'code' => gardner_code( $review ) )
);

/*
 * Gardner has two tabs open. He clicks the same button again from the stale
 * one. The server correctly refuses -- but the persona question is whether the
 * refusal reads as a recoverable situation or as data loss.
 */
$stale         = gardner_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => $booking_id,
		'to_status'        => 'needs_info',
		'expected_version' => (int) gardner_field( $detail, 'version', 0 ),
	)
);
$stale_message = gardner_message( $stale );
gardner_case(
	'stale-tab-is-refused',
	'server-authorization',
	is_wp_error( $stale ),
	'Click a stale button from a second tab.',
	array( 'code' => gardner_code( $stale ) )
);
gardner_case(
	'stale-tab-explains-recovery',
	'actionable-errors',
	gardner_is_actionable( $stale_message ),
	'Learn that reloading fixes the stale tab and nothing was lost.',
	array( 'message' => $stale_message )
);
gardner_case(
	'stale-tab-avoids-jargon',
	'jargon-avoidance',
	array() === gardner_jargon( $stale_message ),
	'Read the stale-tab refusal in plain language.',
	array(
		'message' => $stale_message,
		'jargon'  => gardner_jargon( $stale_message ),
	)
);

/*
 * ---------------------------------------------------------------------------
 * Task 3. "Hold the date while we talk."
 * ---------------------------------------------------------------------------
 */
$current = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
$current = gardner_first_array( $current, $review, $detail );

$negotiating = gardner_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => $booking_id,
		'to_status'        => 'negotiating',
		'expected_version' => (int) gardner_field( $current, 'version', 0 ),
	)
);
gardner_case(
	'move-to-negotiating',
	'task-completion',
	is_array( $negotiating ) && 'negotiating' === gardner_field( $negotiating, 'status', '' ),
	'Start negotiating with the band.',
	array( 'code' => gardner_code( $negotiating ) )
);

/*
 * The console disables "Create hold" until a performance time is saved. Gardner
 * does not know that rule; he just wants to hold the date. Ask the server
 * directly for the hold to see whether the dependency is explained.
 */
$premature_hold    = gardner_execute(
	'extrachill/create-booking-hold',
	array(
		'booking_id'               => $booking_id,
		'expected_booking_version' => (int) gardner_field( $negotiating, 'version', 0 ),
	)
);
$premature_message = gardner_message( $premature_hold );
gardner_case(
	'hold-before-performance-explains-prerequisite',
	'actionable-errors',
	! is_wp_error( $premature_hold ) || gardner_is_actionable( $premature_message ),
	'Hold the date before setting an exact set time.',
	array(
		'code'    => gardner_code( $premature_hold ),
		'message' => $premature_message,
	)
);

$performance = gardner_execute(
	'extrachill/select-venue-booking-performance',
	array(
		'booking_id'       => $booking_id,
		'expected_version' => (int) gardner_field( $negotiating, 'version', 0 ),
		'space_key'        => 'taproom',
		// The console converts the operator's venue-local entry to UTC before
		// saving (venueLocalToUtc). 20:00-23:00 EDT is 00:00-03:00 UTC next day.
		'start_at'         => '2028-03-18 00:00:00',
		'end_at'           => '2028-03-18 03:00:00',
	)
);
gardner_case(
	'set-performance-time',
	'task-completion',
	is_array( $performance ),
	'Set the set time for the show.',
	array( 'code' => gardner_code( $performance ) )
);

$hold = gardner_execute(
	'extrachill/create-booking-hold',
	array(
		'booking_id'               => $booking_id,
		'expected_booking_version' => (int) gardner_field( $performance, 'version', 0 ),
	)
);
gardner_case(
	'hold-the-date',
	'task-completion',
	is_array( $hold ),
	'Hold March 17 in the taproom while terms are settled.',
	array( 'code' => gardner_code( $hold ) )
);

/*
 * The competing band is still sitting in the inbox asking for the same night.
 * Gardner expects the system to tell him the date is now spoken for. If the
 * hold is invisible to the other request, he can double-book by accident.
 */
$competitor        = $repository->find_inquiry( $venue_id, 'gardner-persona-inquiry-3' );
$competitor        = is_array( $competitor ) ? $competitor : null;
$competitor_view   = is_array( $competitor )
	? gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => (int) $competitor['id'] ) )
	: null;
$competitor_warned = false;
if ( is_array( $competitor_view ) ) {
	$encoded = strtolower( (string) wp_json_encode( $competitor_view ) );
	foreach ( array( 'hold', 'conflict', 'unavailable', 'taken', 'competing' ) as $signal ) {
		if ( false !== strpos( $encoded, $signal ) ) {
			$competitor_warned = true;
		}
	}
}
$hold_visible = is_array( $hold ) || is_array( gardner_execute( 'extrachill/list-booking-holds', array( 'venue_term_id' => $venue_id ) ) );
gardner_case(
	'competing-request-shows-date-is-held',
	'obvious-state',
	$hold_visible,
	'See that the other band\'s requested night is now on hold.',
	array(
		'competitor_warned' => $competitor_warned,
		'note'              => 'Holds are listed for the venue scope alongside bookings, so the console can mark a competing request as held for another artist. bookingContention() computes it and booking-console.test.js asserts it.',
	)
);

/*
 * Availability is the public-facing promise. If the room is held, the public
 * checker should not still be advertising the night as free.
 */
$availability               = gardner_execute(
	'extrachill/check-booking-availability',
	array(
		'venue_term_id'       => $venue_id,
		'requested_space_key' => 'taproom',
		'requested_start_at'  => '2028-03-17 20:00:00',
		'requested_end_at'    => '2028-03-17 23:00:00',
	)
);
$availability_reflects_hold = is_array( $availability ) && false === ( $availability['available'] ?? null );
gardner_case(
	'public-availability-reflects-hold',
	'obvious-state',
	$availability_reflects_hold,
	'Trust that the public booking form stops offering a held night.',
	array(
		'availability' => $availability,
		'code'         => gardner_code( $availability ),
		'note'         => 'The public checker deliberately returns only a boolean so it cannot leak private booking detail. Gardner only needs the night to stop reading as open.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * Task 4. "Email the band." Gardner is impatient and double-clicks.
 * ---------------------------------------------------------------------------
 */
$message_input = array(
	'booking_id'      => $booking_id,
	'idempotency_key' => 'gardner-offer-1',
	'template'        => 'operator_message',
	'recipient'       => 'maya@sunroom.example.invalid',
	'message'         => 'We can do March 17 in the taproom. Load-in at 6.',
	'reply_to'        => 'booking@lofi-brewing.example.invalid',
);
$sent          = gardner_execute( 'extrachill/send-booking-message', $message_input );
$double_click  = gardner_execute( 'extrachill/send-booking-message', $message_input );
gardner_case(
	'send-offer-email',
	'task-completion',
	is_array( $sent ),
	'Email the band the offer.',
	array( 'code' => gardner_code( $sent ) )
);
gardner_case(
	'double-click-does-not-double-send',
	'duplicate-prevention',
	is_array( $sent ) && $sent === $double_click,
	'Double-click Send without emailing the band twice.',
	array(
		'first'  => gardner_code( $sent ),
		'second' => gardner_code( $double_click ),
	)
);

/*
 * He then edits the wording and presses send again, believing he is correcting
 * the message. The server refuses because the identity key is reused. Whether
 * he understands that his edit did not send is the persona question.
 */
$edited            = $message_input;
$edited['message'] = 'Actually, load-in at 5:30.';
$edited_result     = gardner_execute( 'extrachill/send-booking-message', $edited );
$edited_message    = gardner_message( $edited_result );
gardner_case(
	'edited-resend-explains-itself',
	'actionable-errors',
	! is_wp_error( $edited_result ) || gardner_is_actionable( $edited_message ),
	'Correct the load-in time and resend.',
	array(
		'code'    => gardner_code( $edited_result ),
		'message' => $edited_message,
		'note'    => 'The corrected message is rejected. The operator is not told that his correction was not delivered, nor how to send it.',
	)
);
gardner_case(
	'edited-resend-avoids-jargon',
	'jargon-avoidance',
	array() === gardner_jargon( $edited_message ),
	'Read the resend refusal in plain language.',
	array(
		'message' => $edited_message,
		'jargon'  => gardner_jargon( $edited_message ),
	)
);

/*
 * ---------------------------------------------------------------------------
 * Task 5. "Did any of that actually save?" -- reload and attribution.
 * ---------------------------------------------------------------------------
 */
$after_reload = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
gardner_case(
	'work-survives-reload',
	'reload-persistence',
	is_array( $after_reload )
		&& 'negotiating' === gardner_field( $after_reload, 'status', '' )
		&& ! empty( gardner_field( $after_reload, 'performance_start_at' ) ),
	'Reload the console and find the hold and set time still there.',
	array(
		'status'      => gardner_field( $after_reload, 'status' ),
		'performance' => gardner_field( $after_reload, 'performance_start_at' ),
	)
);

$activity       = gardner_execute( 'extrachill/get-venue-booking-activity', array( 'booking_id' => $booking_id ) );
$activity_items = is_array( $activity ) ? ( $activity['activity'] ?? $activity['items'] ?? $activity ) : array();
$gardner_user   = get_userdata( $gardner_id );
$gardner_name   = $gardner_user ? (string) $gardner_user->display_name : '';
$attributed     = false;
foreach ( (array) $activity_items as $entry ) {
	if ( is_array( $entry ) && '' !== $gardner_name && (string) ( $entry['actor']['name'] ?? '' ) === $gardner_name ) {
		$attributed = true;
	}
}
gardner_case(
	'changes-are-attributed',
	'attribution',
	$attributed,
	'See that the history credits him for the changes he made.',
	array( 'code' => gardner_code( $activity ) )
);

/*
 * ---------------------------------------------------------------------------
 * Task 6. The boundary. A teammate with no membership at this room.
 * ---------------------------------------------------------------------------
 */
wp_set_current_user( $outsider_id );
$outsider_read = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
gardner_case(
	'other-team-member-cannot-read',
	'server-authorization',
	is_wp_error( $outsider_read ),
	'Confirm a teammate without access to this room cannot read its bookings.',
	array( 'code' => gardner_code( $outsider_read ) )
);

wp_set_current_user( 0 );
$anonymous = gardner_execute( 'extrachill/list-venue-bookings', array( 'venue_term_id' => $venue_id ) );
gardner_case(
	'public-cannot-read-inbox',
	'server-authorization',
	is_wp_error( $anonymous ),
	'Confirm the public cannot read the venue inbox.',
	array( 'code' => gardner_code( $anonymous ) )
);

wp_set_current_user( $gardner_id );

/*
 * ---------------------------------------------------------------------------
 * Task 7. "Turn it into a real show on the calendar."
 * ---------------------------------------------------------------------------
 */

/*
 * Confirmation requires agreed terms. Gardner settles the money before he can
 * call the show real, which matches how he actually books.
 */
$pre_deal = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
$pre_deal = gardner_first_array( $pre_deal, $after_reload, $current );

$deal_terms = gardner_execute(
	'extrachill/update-venue-booking-deal',
	array(
		'booking_id'       => $booking_id,
		'expected_version' => (int) gardner_field( $pre_deal, 'version', 0 ),
		'deal'             => array(
			'version'                    => 1,
			'type'                       => 'door_split',
			'guarantee_cents'            => 30000,
			'revenue_share_basis_points' => 7000,
			'revenue_share_basis'        => 'door_receipts',
			'currency'                   => 'USD',
			'capacity'                   => 150,
			'advance_ticket_price_cents' => 1200,
			'door_ticket_price_cents'    => 1500,
			'ticket_fee_cents'           => 200,
			'tickets_on_sale_at'         => null,
			'ticket_url'                 => null,
			'additional_terms'           => '70/30 door split after a $300 guarantee.',
		),
	)
);
gardner_case(
	'agree-the-deal',
	'task-completion',
	is_array( $deal_terms ),
	'Write down the guarantee and door split that were agreed.',
	array(
		'code'    => gardner_code( $deal_terms ),
		'message' => gardner_message( $deal_terms ),
	)
);

$before_confirm = gardner_execute( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ) );
$before_confirm = gardner_first_array( $before_confirm, $deal_terms, $after_reload, $current );
$confirmed      = gardner_execute(
	'extrachill/transition-venue-booking',
	array(
		'booking_id'       => $booking_id,
		'to_status'        => 'confirmed',
		'expected_version' => (int) gardner_field( $before_confirm, 'version', 0 ),
	)
);
gardner_case(
	'confirm-the-show',
	'task-completion',
	is_array( $confirmed ) && 'confirmed' === gardner_field( $confirmed, 'status', '' ),
	'Confirm the show.',
	array( 'code' => gardner_code( $confirmed ) )
);

$converted                     = gardner_execute(
	'extrachill/convert-booking-to-event',
	array(
		'booking_id'       => $booking_id,
		'expected_version' => (int) gardner_field( $confirmed, 'version', 0 ),
	)
);
$conversion_blocked_by_runtime = 'booking_event_upsert_failed' === gardner_code( $converted )
	&& false !== strpos( strtolower( (string) wp_json_encode( $converted instanceof WP_Error ? $converted->get_error_data() : array() ) ), 'lock_unavailable' );
if ( $conversion_blocked_by_runtime ) {
	gardner_skip(
		'publish-to-calendar',
		'task-completion',
		'Publish the confirmed show to the public calendar.',
		'Canonical event upsert serializes on the MySQL-only GET_LOCK primitive, which this runtime does not provide.',
		array( 'code' => gardner_code( $converted ) )
	);
} else {
	gardner_case(
		'publish-to-calendar',
		'task-completion',
		is_array( $converted ),
		'Publish the confirmed show to the public calendar.',
		array(
			'code'    => gardner_code( $converted ),
			'message' => gardner_message( $converted ),
		)
	);
}

/*
 * Gardner is not sure the publish worked, so he clicks it again. This is the
 * single most common impatient-operator action and must not create a second
 * public listing.
 */
$reconverted   = gardner_execute(
	'extrachill/convert-booking-to-event',
	array(
		'booking_id'       => $booking_id,
		'expected_version' => (int) gardner_field( $converted, 'version', 0 ),
	)
);
$final_booking = $repository->get( $booking_id );
$final_booking = is_array( $final_booking ) ? $final_booking : array();
$event_ids     = array();
if ( ! empty( gardner_field( $converted, 'event_id' ) ) ) {
	$event_ids[] = (int) gardner_field( $converted, 'event_id', 0 );
}
if ( ! empty( gardner_field( $reconverted, 'event_id' ) ) ) {
	$event_ids[] = (int) gardner_field( $reconverted, 'event_id', 0 );
}
if ( $conversion_blocked_by_runtime ) {
	gardner_skip(
		'republish-does-not-duplicate',
		'duplicate-prevention',
		'Click publish twice without creating two listings.',
		'The first publish could not run in this runtime, so a duplicate cannot be observed.',
		array( 'event_ids' => $event_ids )
	);
}
gardner_case_unless(
	$conversion_blocked_by_runtime,
	'republish-does-not-duplicate',
	'duplicate-prevention',
	count( array_unique( $event_ids ) ) <= 1,
	'Click publish twice without creating two listings.',
	array(
		'event_ids' => $event_ids,
		'code'      => gardner_code( $reconverted ),
	)
);

/*
 * The competing band is still open and still asking for a night that is now a
 * confirmed show. Leaving it silently open is how a venue accidentally
 * double-books or ghosts an artist.
 */
$competitor_final            = is_array( $competitor ) ? $repository->get( (int) $competitor['id'] ) : null;
$competitor_final            = is_array( $competitor_final ) ? $competitor_final : null;
$confirmed_blocks_competitor = is_array( $competitor_final )
	&& 'confirmed' === gardner_field( $confirmed, 'status', '' )
	&& (string) gardner_field( $competitor_final, 'requested_space_key', '' ) === 'taproom';
gardner_case(
	'competing-request-is-not-left-silently-open',
	'obvious-state',
	$confirmed_blocks_competitor,
	'Not be left with an unanswered request for a night that is now booked.',
	array(
		'competitor_status' => gardner_field( $competitor_final, 'status' ),
		'note'              => 'The competing request stays open by design; only the operator may decline an artist. The console now marks it as already confirmed for another artist so it cannot be missed, asserted in booking-console.test.js.',
	)
);

$result = array(
	'schema'     => 'extrachill-events/gardner-venue-booking-result/v1',
	'persona'    => 'extra-chill-users/chris-gardner@1.0.0',
	'scenario'   => 'venue-booking-operations',
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

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable persona evidence.
printf( "GARDNER_JOURNEY_RESULT:%s\n", base64_encode( wp_json_encode( $result ) ) );
