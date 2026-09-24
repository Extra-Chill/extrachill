<?php
/**
 * Seed the Chris Gardner event-RSVP persona world.
 *
 * Gardner is the canonical nontechnical power user described by
 * `extra-chill-users/chris-gardner` v1.0.0. This scenario places him where
 * he actually is on the night in question: an attendee deciding whether to
 * go to a real, currently-live Extra Chill event.
 *
 * Ownership boundary: Extra Chill Users owns Gardner's identity, traits, and
 * oracle vocabulary. This file owns only the Events-side RSVP scenario --
 * the event, its venue/location/promoter terms, and the two other test
 * attendees needed to exercise the attendance-visibility door-list tension.
 * Identity is consumed, never redefined.
 *
 * The event reproduces PUBLIC production content only (title, description,
 * dates, venue, ticket URL) read from events.extrachill.com post 486727 --
 * blog 7, slug wordpress-meetup-charleston-october-2026 -- on 2026-09-23.
 * No production user account, email, or personal data is copied. Everything
 * is created through registered abilities wherever an ability owns the
 * write, so the fixture cannot drift from the product contract it tests.
 *
 * @package ExtraChillEvents
 */

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function gardner_seed_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'gardner_seed_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Describe an ability result for fixture evidence.
 *
 * @param mixed $result Ability result.
 * @return array
 */
function gardner_seed_outcome( $result ): array {
	if ( is_wp_error( $result ) ) {
		return array(
			'ok'   => false,
			'code' => $result->get_error_code(),
			'note' => $result->get_error_message(),
		);
	}
	return array( 'ok' => true );
}

/**
 * Force-create (or reuse) a fixture user at an exact, deterministic ID.
 *
 * Browser-actions steps in the recipe JSON are static and must reference a
 * user ID before this script runs, so the ID cannot be left to WordPress's
 * auto-increment. Matches the pattern already established by
 * extrachill-studio/tests/wp-codebox/chris-gardner-persona-seed.php.
 *
 * @param int    $user_id      Forced user ID.
 * @param string $login        user_login.
 * @param string $email        user_email.
 * @param string $display_name display_name.
 * @return WP_User
 */
function gardner_seed_force_user( int $user_id, string $login, string $email, string $display_name ): WP_User {
	global $wpdb;

	$existing = get_user_by( 'id', $user_id );
	if ( ! $existing ) {
		$inserted = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $login,
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $login,
				'user_email'      => $email,
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $display_name,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( esc_html( 'Unable to force-create fixture user ' . $login . ' at ID ' . $user_id . '.' ) );
		}
		clean_user_cache( $user_id );
	}

	return new WP_User( $user_id );
}

$evidence = array(
	'schema'  => 'extrachill-events/gardner-event-rsvp-fixture/v1',
	'persona' => 'network-nontechnical-power-user',
	'steps'   => array(
		// See tests/wp-codebox/gardner-event-rsvp-journey-mu-shim.php for why
		// this single-site fixture needs a switch_to_blog()/restore_current_blog()
		// compatibility shim, and the real extrachill-network defect it stands in for.
		'switch_to_blog_shim_active' => function_exists( 'switch_to_blog' ) && ! ( function_exists( 'is_multisite' ) && is_multisite() ),
	),
);

/*
 * The concert-tracking table is normally created by
 * `extrachill_users_run_activation()` (register_activation_hook), with an
 * `admin_init`-only fallback -- neither fires for a mounted, not
 * zip-installed plugin in a front-end-only browser journey. Confirmed by
 * running this exact journey first without this call: every RSVP mark
 * silently no-opped (0 DB rows, `marked: false`) with no error anywhere,
 * because the ability's own writes were failing against a table that did
 * not exist. Same class of gap as the event-dates table above -- not a
 * product bug (`extrachill_users_run_activation()` also hard-requires
 * multisite, which this single-site fixture deliberately is not), but a
 * real setup requirement this rig must satisfy to exercise a real RSVP.
 */
require_once WP_PLUGIN_DIR . '/extrachill-users/inc/concert-tracking/db.php';
if ( ! function_exists( 'extrachill_users_install_concert_tracking_table' ) ) {
	throw new RuntimeException( 'extrachill_users_install_concert_tracking_table() is unavailable; RSVPs cannot be seeded.' );
}
extrachill_users_install_concert_tracking_table();

/*
 * ---------------------------------------------------------------------------
 * Gardner's identity, consumed from the canonical Users contract.
 *
 * Forced to ID 201 so the recipe's browser-actions steps can authenticate
 * him with a static `auth-user-id=201`. Fields match
 * extrachill-users/tests/personas/gardner.v1.json fixture_identity exactly.
 * ---------------------------------------------------------------------------
 */
const GARDNER_USER_ID              = 201;
const RETURNING_SUBSCRIBER_USER_ID = 202;

$gardner = gardner_seed_force_user( GARDNER_USER_ID, 'gardner_persona_fixture', 'gardner-persona@example.invalid', 'Chris Gardner (Test Persona)' );

/*
 * Real role registration, not a hand-rolled capability list. Consuming the
 * actual `extra_chill_team` role (owned by Extra Chill Users) keeps this
 * fixture honest to the canonical contract's `network_access.team_role`
 * instead of approximating it with `administrator` + loose caps.
 */
if ( function_exists( 'ec_users_register_team_role' ) ) {
	ec_users_register_team_role();
}
if ( get_role( 'extra_chill_team' ) ) {
	$gardner->set_role( 'extra_chill_team' );
} else {
	// Defensive fallback if Users' role registration did not run in this
	// runtime. Recorded as evidence rather than silently substituted.
	$gardner->set_role( 'administrator' );
	$evidence['steps']['team_role_fallback'] = true;
}
foreach ( array( 'access_events_admin', 'access_admin_bar', 'submit_for_review' ) as $capability ) {
	$gardner->add_cap( $capability );
}
// Explicit per-user grant from the canonical contract's explicit_user_grants.
$gardner->add_cap( 'manage_brand_socials' );

/*
 * Scenario-owned decision (Events, not Users): Gardner has already opted
 * into public event-attendance visibility, matching the settings checkbox
 * shipped by extrachill-community and the resolution in
 * extrachill-users#414/#415. This is not part of the canonical identity
 * contract -- it is this scenario's own setup, exactly as
 * gardner-venue-booking-seed.php adds scenario-specific state on top of the
 * shared identity.
 */
if ( function_exists( 'extrachill_users_set_visibility' ) ) {
	extrachill_users_set_visibility( GARDNER_USER_ID, 'event_attendance', '_extrachill_event_attendance_visibility', 'public' );
} else {
	update_user_meta( GARDNER_USER_ID, '_extrachill_event_attendance_visibility', 'public' );
}

/*
 * A second, ordinary attendee: an existing subscriber who never touched the
 * visibility setting. Left deliberately untouched (no meta write) so the
 * journey exercises the real, current default -- absent meta resolves
 * private per extrachill-users#415 -- rather than a fixture-forced value.
 */
$returning_subscriber = gardner_seed_force_user( RETURNING_SUBSCRIBER_USER_ID, 'event_persona_returning_subscriber', 'returning-subscriber@example.invalid', 'Returning Community Member (Test Persona)' );
$returning_subscriber->set_role( 'subscriber' );

$evidence['gardner_id']              = GARDNER_USER_ID;
$evidence['returning_subscriber_id'] = RETURNING_SUBSCRIBER_USER_ID;

/*
 * ---------------------------------------------------------------------------
 * The event. Reproduced from PUBLIC production content on events.extrachill.com
 * post 486727, read-only, 2026-09-23 -- title, description, dates, venue,
 * and ticket URL are verbatim.
 *
 * NOT seeded through `data-machine-events/upsert-event`. That ability
 * serializes on a MySQL-only `GET_LOCK` primitive
 * (EventUpsert.php:902, error code `event_upsert_lock_unavailable`) that
 * this WordPress runtime's database layer does not provide -- confirmed by
 * running it here first and hitting the exact error. This is the same,
 * already-documented runtime limitation `gardner-venue-booking-seed.php`
 * worked around for `create-booking-inquiry`; not a new gap, not re-filed.
 * Following that same precedent, the event is written directly (post +
 * taxonomy + the real `EventDatesTable::upsert()` public method for the
 * dates row) rather than through the lock-gated public ability. Every
 * action the RSVP journey itself then takes -- the surface this journey
 * evaluates -- runs through the real registered attendance abilities.
 * ---------------------------------------------------------------------------
 */
wp_set_current_user( 1 );

/*
 * The event-dates table is normally created on plugin activation. This
 * mounted, not zip-installed, runtime does not reliably run that
 * activation hook, so the table would otherwise be silently absent --
 * confirmed by running without this call first and hitting
 * "Base table or view not found: wp_datamachine_event_dates". Create it
 * explicitly and fail loudly if it still isn't ready, the same guard style
 * `gardner-venue-booking-seed.php` uses for `BookingSchema::install()`.
 */
if ( ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
	throw new RuntimeException( 'EventDatesTable is unavailable; the event cannot be seeded.' );
}
\DataMachineEvents\Core\EventDatesTable::create_table();
if ( ! \DataMachineEvents\Core\EventDatesTable::table_exists() ) {
	throw new RuntimeException( 'The event-dates table did not install; the persona cannot be seeded.' );
}

$description = "Join us at Lo-Fi Brewing on Wednesday, October 21st from 6:30 to 9pm for a free gathering of the creative community focused on building your online presence in the AI era. This is an official WordPress meetup, hosted by Chris Huber, the founder of Extra Chill, who now works as an engineer at Automattic. However, you don't have to use WordPress or even know what it is to find value in this event.\n\nMusicians, writers, photographers, developers, small business owners, whether you have a website or just an Instagram. All experience levels are welcome.\n\nWe'll go behind the scenes of Extra Chill, showcasing our fully automated international concert calendar, artist platform, and community, all built on open source software. Other creatives will also be invited to share what they are building. At this event we will discuss AI, including both the challenges it presents to the creative community, and how it can be used to empower your own process. Bring your objections and your ideas, that's what this event is all about.\n\nMark yourself as Going on this page or the Meetup.com event and your first beer is on Extra Chill.";

$paragraphs   = explode( "\n\n", $description );
$block_inner  = implode(
	"\n\n",
	array_map(
		static function ( $paragraph ) {
			return "<!-- wp:paragraph -->\n<p>" . $paragraph . "</p>\n<!-- /wp:paragraph -->";
		},
		$paragraphs
	)
);
$block_attrs  = wp_json_encode(
	array(
		'startDate'    => '2026-10-21',
		'endDate'      => '2026-10-21',
		'startTime'    => '18:30',
		'endTime'      => '21:00',
		'venue'        => 'Lo-Fi Brewing',
		'ticketUrl'    => 'https://www.meetup.com/wordpress-charleston/events/316649382/',
		'organizer'    => 'Extra Chill',
		'organizerUrl' => 'https://extrachill.com',
	)
);
$post_content = "<!-- wp:data-machine-events/event-details {$block_attrs} -->\n"
	. '<div class="wp-block-data-machine-events-event-details">' . $block_inner . "</div>\n"
	. '<!-- /wp:data-machine-events/event-details -->';

$event_id                        = wp_insert_post(
	array(
		'post_type'    => 'data_machine_events',
		'post_status'  => 'publish',
		'post_author'  => 1,
		'post_title'   => 'Extra Chill & WordPress Meetup: Building Your Online Presence in the AI Era',
		'post_name'    => 'wordpress-meetup-charleston-october-2026',
		'post_content' => $post_content,
	),
	true
);
$evidence['steps']['event_post'] = gardner_seed_outcome( $event_id );
if ( is_wp_error( $event_id ) ) {
	throw new RuntimeException( esc_html( 'Could not seed the event post: ' . $event_id->get_error_message() ) );
}
$event_id = (int) $event_id;

/*
 * Venue term, matching production's Lo-Fi Brewing meta exactly (read-only,
 * 2026-09-23) -- same pattern gardner-venue-booking-seed.php already uses
 * for the same real venue.
 */
$venue = wp_insert_term( 'Lo-Fi Brewing', 'venue' );
if ( is_wp_error( $venue ) ) {
	throw new RuntimeException( esc_html( 'Could not create the Lo-Fi Brewing venue term: ' . $venue->get_error_message() ) );
}
$venue_id = (int) $venue['term_id'];
update_term_meta( $venue_id, '_venue_address', '2038 Meeting Street Road' );
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_zip', '29405' );
update_term_meta( $venue_id, '_venue_country', 'US' );
update_term_meta( $venue_id, '_venue_coordinates', '32.8337927,-79.9536861' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );
update_term_meta( $venue_id, '_venue_website', 'https://lofibrewing.com' );

/*
 * Location term hierarchy, matching production exactly (read-only,
 * 2026-09-23): United States (usa) > South Carolina (south-carolina) >
 * Charleston (charleston) -- the same three-level structure the real
 * `/location/usa/south-carolina/charleston` archive URL reflects.
 */
$usa        = wp_insert_term( 'United States', 'location', array( 'slug' => 'usa' ) );
$usa_id     = is_wp_error( $usa ) ? (int) get_term_by( 'slug', 'usa', 'location' )->term_id : (int) $usa['term_id'];
$sc         = wp_insert_term( 'South Carolina', 'location', array(
	'slug'   => 'south-carolina',
	'parent' => $usa_id,
) );
$sc_id      = is_wp_error( $sc ) ? (int) get_term_by( 'slug', 'south-carolina', 'location' )->term_id : (int) $sc['term_id'];
$charleston = wp_insert_term( 'Charleston', 'location', array(
	'slug'   => 'charleston',
	'parent' => $sc_id,
) );
if ( is_wp_error( $charleston ) ) {
	throw new RuntimeException( esc_html( 'Could not create the Charleston location term: ' . $charleston->get_error_message() ) );
}
$charleston_id = (int) $charleston['term_id'];

wp_set_object_terms( $event_id, array( $venue_id ), 'venue', false );
wp_set_object_terms( $event_id, array( $charleston_id ), 'location', false );
wp_set_object_terms( $event_id, 'Extra Chill', 'promoter', false );
wp_set_object_terms( $event_id, 'Other', 'event_type', false );

/*
 * Priority-event flag, through its own ability -- production has
 * _extrachill_priority_event = 1, which drives the promoted-event callout on
 * the Charleston location archive.
 */
$priority                            = gardner_seed_execute(
	'extrachill/set-priority-event',
	array(
		'event'    => (string) $event_id,
		'priority' => true,
	)
);
$evidence['steps']['priority_event'] = gardner_seed_outcome( $priority );
if ( is_wp_error( $priority ) ) {
	// Fall back to the same meta production actually carries, but only as a
	// last resort -- record that the ability path did not work.
	update_post_meta( $event_id, '_extrachill_priority_event', 1 );
	$evidence['steps']['priority_event']['fallback'] = true;
}

$location_terms   = wp_get_post_terms( $event_id, 'location' );
$venue_terms      = wp_get_post_terms( $event_id, 'venue' );
$promoter_terms   = wp_get_post_terms( $event_id, 'promoter' );
$event_type_terms = wp_get_post_terms( $event_id, 'event_type' );

$evidence['steps']['taxonomy'] = array(
	'location'   => wp_list_pluck( $location_terms, 'name' ),
	'venue'      => wp_list_pluck( $venue_terms, 'name' ),
	'promoter'   => wp_list_pluck( $promoter_terms, 'name' ),
	'event_type' => wp_list_pluck( $event_type_terms, 'name' ),
);

/*
 * The event-dates table row itself is written automatically by
 * `data_machine_events_sync_datetime_meta()` on the `save_post` hook that
 * already fired inside `wp_insert_post()` above (it reads the block's
 * startDate/startTime/endDate/endTime attributes) -- confirmed live in this
 * run. No separate write needed; just verify it actually landed.
 */
$dates_row                        = \DataMachineEvents\Core\EventDatesTable::get( $event_id );
$evidence['steps']['event_dates'] = array(
	'ok'    => null !== $dates_row,
	'start' => $dates_row->start_datetime ?? null,
	'end'   => $dates_row->end_datetime ?? null,
);
if ( null === $dates_row ) {
	throw new RuntimeException( 'The event-dates row did not sync from the block content; the RSVP/calendar surfaces will not find the event.' );
}

$evidence['event_id']  = $event_id;
$evidence['event_url'] = get_permalink( $event_id );

update_option( 'gardner_event_rsvp_fixture', $evidence, false );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "GARDNER_FIXTURE_RESULT:%s\n", base64_encode( wp_json_encode( $evidence ) ) );
