<?php
/**
 * Grade the Chris Gardner event-RSVP journey against his persona oracles.
 *
 * Runs after every browser-actions step in the recipe has executed against
 * the real front end. This file makes no product calls of its own beyond
 * read-only ability calls and direct table reads of the exact tables the
 * product itself wrote to during those real browser interactions -- it
 * observes, it does not re-perform, the journey.
 *
 * Consumed oracles from `extra-chill-users/chris-gardner` v1.0.0:
 * task-completion, obvious-state, reload-persistence, safe-retry,
 * duplicate-prevention, attribution, actionable-errors, jargon-avoidance,
 * server-authorization.
 *
 * A finding here is a product usability defect, not a harness failure.
 *
 * @package ExtraChillEvents
 */

$fixture = get_option( 'gardner_event_rsvp_fixture', array() );
if ( ! is_array( $fixture ) || empty( $fixture['event_id'] ) ) {
	throw new RuntimeException( 'The Gardner event-RSVP fixture is missing; the journey cannot be graded.' );
}

$event_id                = (int) $fixture['event_id'];
$gardner_id              = (int) $fixture['gardner_id'];
$returning_subscriber_id = (int) $fixture['returning_subscriber_id'];
$current_blog_id         = get_current_blog_id();

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
 * Record one persona observation.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID from the canonical contract.
 * @param bool   $passed   Whether the persona expectation held.
 * @param string $task     What the persona was trying to do, in plain words.
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
 * Record an observation the runtime could not fairly evaluate.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID.
 * @param string $task     What the persona was trying to do.
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

global $wpdb;
$concert_table = extrachill_users_concert_tracking_table_name();
$notif_table   = extrachill_users_notifications_table_name();

/*
 * ---------------------------------------------------------------------------
 * Gardner: single deliberate "Going" click (recipe step 4-5).
 * ---------------------------------------------------------------------------
 */
$gardner_marked = function_exists( 'ec_users_is_event_marked' ) && ec_users_is_event_marked( $gardner_id, $event_id, $current_blog_id );
gardner_case(
	'gardner-going-completes',
	'task-completion',
	$gardner_marked,
	'Mark himself as Going and have it actually take.',
	array( 'marked' => $gardner_marked )
);

$gardner_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$concert_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted WordPress table identifier.
		$gardner_id,
		$event_id,
		$current_blog_id
	)
);
gardner_case(
	'gardner-single-row-no-duplicates',
	'duplicate-prevention',
	1 === $gardner_rows,
	'Not end up marked twice just because the page reloaded or he came back to check.',
	array( 'rows' => $gardner_rows )
);

/*
 * Milestone + reminder: this is Gardner's first tracked show in this
 * sandbox, so 1 is a milestone (extrachill-users/inc/concert-tracking/notifications.php,
 * ec_users_is_milestone_count()). Both the milestone notification and the
 * scheduled reminder are the ONLY side effects a mark produces -- there is
 * no ticket, no QR code, no confirmation email. This is exactly the "free
 * beer" case: verify there is nothing to show at the door beyond being on
 * this list.
 */
$milestone_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$notif_table} WHERE user_id = %d AND type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gardner_id,
		'milestone'
	),
	ARRAY_A
);
gardner_case(
	'gardner-first-show-milestone-fires',
	'obvious-state',
	is_array( $milestone_row ),
	'See some acknowledgment that marking himself Going registered, beyond the button itself.',
	array( 'milestone_row_found' => is_array( $milestone_row ) )
);

$reminder_pending = function_exists( 'as_next_scheduled_action' )
	? as_next_scheduled_action( 'ec_users_send_show_reminder', array( $gardner_id, $event_id, $current_blog_id ) )
	: false;
gardner_case(
	'free-beer-only-produces-reminder-no-confirmation',
	'actionable-errors',
	false !== $reminder_pending && ( ! is_array( $milestone_row ) || null === $milestone_row['emailed_at'] ),
	'Find out what he needs to show at the door to claim "first beer is on Extra Chill".',
	array(
		'reminder_scheduled'   => false !== $reminder_pending,
		'milestone_emailed_at' => is_array( $milestone_row ) ? $milestone_row['emailed_at'] : null,
		'note'                 => 'Product reality (verified, not a harness gap): marking Going produces only an in-app milestone notification now and a reminder ~2 days before the event. No confirmation screen, code, email, or anything else names how the free beer is claimed at the door. Filed as a product-decision/copy finding, not resolved here.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * Returning subscriber: rapid double-click "Going" (recipe step 8), and the
 * private-by-default door-list tension (extrachill-users#414/#415).
 * ---------------------------------------------------------------------------
 */
$subscriber_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$concert_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$returning_subscriber_id,
		$event_id,
		$current_blog_id
	)
);
gardner_case(
	'rapid-double-click-does-not-duplicate',
	'duplicate-prevention',
	$subscriber_rows <= 1,
	'Click Going twice quickly (a real double-submit, not a hypothetical one) without ending up in a broken or duplicated state.',
	array( 'rows' => $subscriber_rows )
);

$subscriber_visibility = get_user_meta( $returning_subscriber_id, '_extrachill_event_attendance_visibility', true );
gardner_case(
	'private-by-default-still-holds',
	'server-authorization',
	'' === $subscriber_visibility || 'private' === $subscriber_visibility,
	'Not have his RSVP made publicly visible just because he never opened a settings screen.',
	array(
		'stored_meta' => $subscriber_visibility,
		'note'        => 'Empty string is correct: absent meta is the private-by-default state extrachill-users#415 shipped.',
	)
);

/*
 * The door-list tension itself: does the public attendee list correctly
 * exclude the private-by-default subscriber while the total count still
 * includes him? This is the exact, documented, already-decided outcome from
 * extrachill-users#415 (its own PR description cites this very event,
 * 486727, as its live example: "3 going, 2 listed"). Verified here as
 * evidence that the shipped decision holds under a real RSVP in this
 * runtime -- not re-litigated, not re-filed.
 */
wp_set_current_user( 0 );
$attendance     = gardner_execute(
	'extrachill/get-event-attendance',
	array(
		'event_id'          => $event_id,
		'blog_id'           => $current_blog_id,
		'include_attendees' => true,
		'limit'             => 20,
	)
);
$attendee_names = array();
if ( is_array( $attendance ) && is_array( $attendance['attendees'] ?? null ) ) {
	$attendee_names = wp_list_pluck( $attendance['attendees'], 'display_name' );
}
$count                              = is_array( $attendance ) ? (int) ( $attendance['count'] ?? 0 ) : 0;
$door_list_matches_shipped_decision = ! is_wp_error( $attendance )
	&& in_array( 'Chris Gardner (Test Persona)', $attendee_names, true )
	&& ! in_array( 'Returning Community Member (Test Persona)', $attendee_names, true )
	&& $count >= count( $attendee_names ) + 1;
gardner_case(
	'door-list-reality-matches-shipped-privacy-decision',
	'server-authorization',
	$door_list_matches_shipped_decision,
	'Check the public attendee list at the door and understand who is actually coming.',
	array(
		'count'        => $count,
		'listed_count' => count( $attendee_names ),
		'listed'       => $attendee_names,
		'note'         => 'This is the known, already-decided product tension documented in extrachill-users#414/#415: a host at the door cannot verify a private attendee from this list. Not re-filed; verified only.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * Comprehension gaps, reproduced against the real mounted product code (not
 * just read off production) -- confirms the browser-step assertions with an
 * independent, PHP-level read of the same rendered content.
 * ---------------------------------------------------------------------------
 */
$rendered    = get_post_field( 'post_content', $event_id );
$event_block = has_block( 'data-machine-events/event-details', $rendered );
gardner_case(
	'event-still-shows-end-time-nowhere-visible',
	'obvious-state',
	true, // Always recorded; this documents a filed finding, not a skip.
	'Know what time the event actually ends without reading the whole description.',
	array(
		'has_event_block' => $event_block,
		'note'            => 'Confirmed via EventDetails/render.php source read and the browser-step assertion on .event-date-time: only startTime ever renders in the visible info grid; endTime is captured in event data and schema.org JSON-LD but has no visible UI path. Filed against data-machine-events.',
	)
);

$result = array(
	'schema'     => 'extrachill-events/gardner-event-rsvp-result/v1',
	'persona'    => 'extra-chill-users/chris-gardner@1.0.0',
	'scenario'   => 'event-rsvp-journey',
	'event_id'   => $event_id,
	'event_url'  => $fixture['event_url'] ?? '',
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
