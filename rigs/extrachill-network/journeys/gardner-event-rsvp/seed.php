<?php
/**
 * Seed the Chris Gardner event-RSVP journey on the real network boot.
 *
 * Ported from extrachill-events/tests/wp-codebox/gardner-event-rsvp-journey-seed.php
 * (extrachill-events#876) onto the full 11-site network rig. Every single-site
 * workaround from that file was dropped:
 *
 * - No switch_to_blog()/restore_current_blog() shim: this IS multisite.
 * - No EC_BLOG_ID_EVENTS override: the events site is resolved from
 *   network-topology.json's domain (events.extrachill.com) at runtime, and the
 *   rig's generated ec-network-domain-ids.php mu-plugin aligns the
 *   EC_BLOG_ID_* constants with the fresh install's actual blog IDs by domain.
 * - No unconditional table creation: the RIG's activation step fires every
 *   plugin's activation hooks (network-wide, then per site -- including
 *   network-activated plugins re-fired per site, matching what production's
 *   install-time activation did), so register_activation_hook callbacks run
 *   and create their own tables. This seed only VERIFIES the tables it
 *   depends on and fails loudly if one is missing: that is a rig regression,
 *   not a journey concern.
 *
 * One thing the network boot genuinely adds: WordPress.run-php steps execute
 * against the primary site, where the per-site plugins (data-machine-events,
 * extrachill-events) are NOT loaded -- switch_to_blog() swaps the DB context
 * but never loads plugin code. The seed therefore bootstraps the events
 * site's own plugin context explicitly (require + re-run the init callbacks
 * that already fired), exactly the constraint extrachill-api's own
 * upcoming-counts route documents. Every write still goes through the real
 * plugin functions -- no direct SQL, no duplicated row shapes.
 *
 * The event reproduces PUBLIC production content only (title, description,
 * dates, venue, ticket URL) from events.extrachill.com post 486727 -- slug
 * wordpress-meetup-charleston-october-2026, read 2026-09-23 and re-verified
 * 2026-09-26. No production user account, email, or personal data is copied.
 * The fixture additionally enables the RSVP perk the event's real copy
 * promises ("your first beer is on Extra Chill") -- production has not
 * configured the perk meta on the real post yet, so the perk pass surface
 * (extrachill-events#878) would otherwise be untestable against this event.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID of the site.
 */
function ec_rig_journey_site_id( string $domain ): int {
	$sites = get_sites(
		array(
			'domain' => $domain,
			'number' => 1,
		)
	);
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey seed could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/**
 * Bootstrap the events site's per-site plugin context.
 *
 * run-php executes on the primary site; per-site plugins never load there.
 * Requiring their main files gives the classes, and re-running the init
 * callbacks (idempotent: each registration guards with taxonomy_exists /
 * post_type_exists / its own static flag) reproduces the context a real
 * front-end request on the events site has.
 *
 * @return array Evidence of what the bootstrap had to do.
 */
function ec_rig_journey_bootstrap_events_plugins(): array {
	$done = array();

	require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
	$done['data_machine_events_required'] = true;

	if ( ! post_type_exists( 'data_machine_events' ) && class_exists( '\\DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		\DataMachineEvents\Core\Event_Post_Type::register();
		$done['cpt_registered'] = true;
	}
	foreach ( array( 'Venue_Taxonomy', 'Promoter_Taxonomy', 'Event_Type_Taxonomy' ) as $taxonomy_class ) {
		$fqcn = "\\DataMachineEvents\\Core\\{$taxonomy_class}";
		if ( class_exists( $fqcn ) ) {
			$fqcn::register();
		}
	}
	$done['dme_taxonomies_registered'] = true;

	require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
	$done['extrachill_events_required'] = true;

	if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
		extrachill_events_register_taxonomies();
		$done['event_taxonomies_attached'] = true;
	}

	if ( class_exists( '\\ExtraChillEvents\\Providers\\AbilitiesProvider' ) ) {
		\ExtraChillEvents\Providers\AbilitiesProvider::initialize();
		$done['events_abilities_initialized'] = true;
	}

	return $done;
}

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function ec_rig_journey_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'ec_rig_journey_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Describe an ability result for fixture evidence.
 *
 * @param mixed $result Ability result.
 * @return array
 */
function ec_rig_journey_outcome( $result ): array {
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
 * Browser-actions steps are static and must reference a user ID before this
 * script runs. Users are global in multisite; site membership is granted
 * separately per site (add_user_to_blog below).
 *
 * @param int    $user_id      Forced user ID.
 * @param string $login        user_login.
 * @param string $email        user_email.
 * @param string $display_name display_name.
 * @return WP_User
 */
function ec_rig_journey_force_user( int $user_id, string $login, string $email, string $display_name ): WP_User {
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

$events_blog_id = ec_rig_journey_site_id( 'events.extrachill.com' );
$main_blog_id   = ec_rig_journey_site_id( 'extrachill.com' );

$evidence = array(
	'schema'         => 'extrachill-network/journey-fixture/gardner-event-rsvp/v1',
	'persona'        => 'extra-chill-users/chris-gardner@1.0.0',
	'events_blog_id' => $events_blog_id,
	'main_blog_id'   => $main_blog_id,
	'steps'          => array(),
);

/*
 * ---------------------------------------------------------------------------
 * Fixture users. Forced IDs so the recipe's browser steps can authenticate
 * statically (auth-user-id). Fields for Gardner match the pinned
 * personas/gardner.v1.json fixture_identity exactly. No plugin context
 * needed: users, roles, and site membership are core multisite.
 * ---------------------------------------------------------------------------
 */
const GARDNER_USER_ID              = 201;
const RETURNING_SUBSCRIBER_USER_ID = 202;

$gardner = ec_rig_journey_force_user( GARDNER_USER_ID, 'gardner_persona_fixture', 'gardner-persona@example.invalid', 'Chris Gardner (Test Persona)' );

if ( function_exists( 'ec_users_register_team_role' ) ) {
	ec_users_register_team_role();
}
foreach ( array( $events_blog_id, $main_blog_id ) as $member_blog_id ) {
	if ( ! is_user_member_of_blog( GARDNER_USER_ID, $member_blog_id ) ) {
		add_user_to_blog( $member_blog_id, GARDNER_USER_ID, 'extra_chill_team' );
	}
}
if ( get_role( 'extra_chill_team' ) ) {
	$gardner->set_role( 'extra_chill_team' );
} else {
	// Defensive fallback if Users' role registration did not run in this
	// runtime. Recorded as evidence rather than silently substituted.
	$gardner->set_role( 'administrator' );
	$evidence['steps']['team_role_fallback'] = true;
}
foreach ( array( 'access_events_admin', 'submit_for_review' ) as $capability ) {
	$gardner->add_cap( $capability );
}
// Explicit per-user grant from the canonical contract's explicit_user_grants.
$gardner->add_cap( 'manage_brand_socials' );

// Scenario-owned decision (mirrors the events-repo seed): Gardner has opted
// into public event-attendance visibility. Absent meta would be the
// private-by-default state, which is the returning subscriber's case below.
update_user_meta( GARDNER_USER_ID, '_extrachill_event_attendance_visibility', 'public' );

// Gardner's Local Scene: Charleston, so the main-site homepage "Top Event
// Markets" card prioritizes the market this event lives in.
update_user_meta( GARDNER_USER_ID, '_extrachill_local_scene', 'charleston' );

$returning_subscriber = ec_rig_journey_force_user( RETURNING_SUBSCRIBER_USER_ID, 'event_persona_returning_subscriber', 'returning-subscriber@example.invalid', 'Returning Community Member (Test Persona)' );
if ( ! is_user_member_of_blog( RETURNING_SUBSCRIBER_USER_ID, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, RETURNING_SUBSCRIBER_USER_ID, 'subscriber' );
}
$returning_subscriber->set_role( 'subscriber' );
// Deliberately no visibility meta: the journey exercises the real
// private-by-default default (extrachill-users#415).

$evidence['gardner_id']              = GARDNER_USER_ID;
$evidence['returning_subscriber_id'] = RETURNING_SUBSCRIBER_USER_ID;

/*
 * Allow the live registration path (the new-creative registers for real).
 * A fresh multisite install defaults to disabled registration.
 */
if ( ! in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) ) {
	update_site_option( 'registration', 'user' );
	$evidence['steps']['registration_enabled_by_seed'] = true;
}

/*
 * ---------------------------------------------------------------------------
 * The event, on the events site by domain.
 * ---------------------------------------------------------------------------
 */
wp_set_current_user( 1 );

switch_to_blog( $events_blog_id );

$evidence['steps']['bootstrap'] = ec_rig_journey_bootstrap_events_plugins();

/*
 * ---------------------------------------------------------------------------
 * Table verification (not creation): the RIG's activation step is responsible
 * for firing every plugin's activation hooks (network-wide, then per site),
 * which is what creates these tables in production. This seed only verifies
 * and fails loudly -- a missing table here is a rig regression, never
 * something the journey should paper over.
 * ---------------------------------------------------------------------------
 */
if ( ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
	throw new RuntimeException( 'EventDatesTable is unavailable after bootstrapping data-machine-events.' );
}
if ( ! \DataMachineEvents\Core\EventDatesTable::table_exists() ) {
	throw new RuntimeException( 'The event-dates table does not exist on the events site; the rig activation step failed to fire data-machine-events\' activation hook.' );
}

if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
	throw new RuntimeException( 'RsvpPassesTable is unavailable after bootstrapping extrachill-events.' );
}
if ( ! \ExtraChillEvents\Core\RsvpPassesTable::table_exists() ) {
	throw new RuntimeException( 'The RSVP pass table does not exist on the events site; the rig activation step failed to fire extrachill-events\' activation hook.' );
}

global $wpdb;
// Mirrors extrachill_users_concert_tracking_table_name() (extrachill-users inc/concert-tracking/db.php).
$concert_table = $wpdb->base_prefix . 'ec_concert_tracking';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $concert_table ) ) !== $concert_table ) {
	throw new RuntimeException( 'The concert-tracking table does not exist; every RSVP would silently no-op. The rig activation step failed to fire extrachill-users\' activation hook.' );
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

$existing = get_page_by_path( 'wordpress-meetup-charleston-october-2026', OBJECT, 'data_machine_events' );
if ( $existing instanceof WP_Post ) {
	$event_id                        = (int) $existing->ID;
	$evidence['steps']['event_post'] = array(
		'ok'     => true,
		'reused' => true,
	);
} else {
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
	$evidence['steps']['event_post'] = ec_rig_journey_outcome( $event_id );
	if ( is_wp_error( $event_id ) ) {
		throw new RuntimeException( esc_html( 'Could not seed the event post: ' . $event_id->get_error_message() ) );
	}
	$event_id = (int) $event_id;

	// The event-dates row is written by the real save_post hook
	// (data_machine_events_sync_datetime_meta). This request loaded the
	// plugin AFTER init, so the hook is not registered here -- invoke the
	// exact same function the hook would invoke, never a reimplementation.
	if ( function_exists( 'data_machine_events_sync_datetime_meta' ) ) {
		data_machine_events_sync_datetime_meta( $event_id, get_post( $event_id ), false );
		$evidence['steps']['event_dates_sync'] = 'invoked directly (post-init bootstrap)';
	}
}

// The RSVP perk the event's real-world copy promises ("first beer is on
// Extra Chill"). Production has not configured perk meta on the real post;
// the fixture configures it so the perk-pass surface (extrachill-events#878)
// is exercised exactly as the event promises it.
update_post_meta( $event_id, '_extrachill_event_perk_enabled', 1 );
update_post_meta( $event_id, '_extrachill_event_perk_text', 'Your first beer is on Extra Chill - show your pass code at the door.' );

// Venue term, matching production's Lo-Fi Brewing meta (read-only, 2026-09-23).
$venue = wp_insert_term( 'Lo-Fi Brewing', 'venue' );
if ( is_wp_error( $venue ) ) {
	$venue_term = get_term_by( 'slug', 'lo-fi-brewing', 'venue' );
	if ( ! $venue_term ) {
		throw new RuntimeException( esc_html( 'Could not create or find the Lo-Fi Brewing venue term: ' . $venue->get_error_message() ) );
	}
	$venue_id = (int) $venue_term->term_id;
} else {
	$venue_id = (int) $venue['term_id'];
}
update_term_meta( $venue_id, '_venue_address', '2038 Meeting Street Road' );
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_zip', '29405' );
update_term_meta( $venue_id, '_venue_country', 'US' );
update_term_meta( $venue_id, '_venue_coordinates', '32.8337927,-79.9536861' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );
update_term_meta( $venue_id, '_venue_website', 'https://lofibrewing.com' );

// Location hierarchy matching production: US > SC > Charleston. The
// `location` taxonomy itself is registered network-wide by extrachill-network.
$usa        = wp_insert_term( 'United States', 'location', array( 'slug' => 'usa' ) );
$usa_term   = get_term_by( 'slug', 'usa', 'location' );
$usa_id     = is_wp_error( $usa ) ? ( $usa_term instanceof WP_Term ? (int) $usa_term->term_id : 0 ) : (int) $usa['term_id'];
$sc         = wp_insert_term( 'South Carolina', 'location', array(
	'slug'   => 'south-carolina',
	'parent' => $usa_id,
) );
$sc_term    = get_term_by( 'slug', 'south-carolina', 'location' );
$sc_id      = is_wp_error( $sc ) ? ( $sc_term instanceof WP_Term ? (int) $sc_term->term_id : 0 ) : (int) $sc['term_id'];
$charleston = wp_insert_term( 'Charleston', 'location', array(
	'slug'   => 'charleston',
	'parent' => $sc_id,
) );
if ( is_wp_error( $charleston ) ) {
	$charleston_term = get_term_by( 'slug', 'charleston', 'location' );
	if ( ! $charleston_term ) {
		throw new RuntimeException( esc_html( 'Could not create the Charleston location term: ' . $charleston->get_error_message() ) );
	}
	$charleston_id = (int) $charleston_term->term_id;
} else {
	$charleston_id = (int) $charleston['term_id'];
}

wp_set_object_terms( $event_id, array( $venue_id ), 'venue', false );
wp_set_object_terms( $event_id, array( $charleston_id ), 'location', false );
wp_set_object_terms( $event_id, 'Extra Chill', 'promoter', false );
wp_set_object_terms( $event_id, 'Other', 'event_type', false );

// Priority-event flag drives the promoted-event callout on the Charleston
// location archive, through its own ability.
$priority                            = ec_rig_journey_execute(
	'extrachill/set-priority-event',
	array(
		'event'    => (string) $event_id,
		'priority' => true,
	)
);
$evidence['steps']['priority_event'] = ec_rig_journey_outcome( $priority );
if ( is_wp_error( $priority ) ) {
	update_post_meta( $event_id, '_extrachill_priority_event', 1 );
	$evidence['steps']['priority_event']['fallback'] = true;
}

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

update_option( 'ec_rig_journey_fixture_gardner_event_rsvp', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
