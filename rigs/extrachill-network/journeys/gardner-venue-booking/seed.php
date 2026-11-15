<?php
/**
 * Seed the Chris Gardner venue-booking persona world on the real network boot.
 *
 * Ported from extrachill-events/tests/wp-codebox/gardner-venue-booking-seed.php
 * (extrachill-events#292) onto the full 11-site network rig. Two things
 * changed from the single-site fixture:
 *
 * - Gardner is force-created at the canonical rig persona ID (201) with the
 *   canonical extra_chill_team role and the exact capability set the pinned
 *   contract (personas/gardner.v1.json) declares, instead of a bespoke
 *   administrator account. The `venue_booking` feature ceiling is `team`
 *   (extrachill-events LifecycleProvider::register_feature_ceilings()), so
 *   the canonical team role is what actually gates this feature in
 *   production -- using it here is more faithful, not less.
 * - No unconditional table creation: the RIG's activation step fires
 *   extrachill-events' activation hook (BookingSchema::install()), so this
 *   seed only VERIFIES the tables it depends on and fails loudly if one is
 *   missing, exactly the pattern journeys/gardner-event-rsvp/seed.php
 *   already established.
 *
 * Ownership boundary unchanged: Extra Chill Users owns Gardner's identity,
 * traits, and oracle vocabulary. This file owns only the Events-side booking
 * scenario -- the venue, its configuration, its membership, and the inbound
 * inquiries already waiting for him.
 *
 * @package ExtraChillNetwork
 */

const GARDNER_VENUE_BOOKING_USER_ID = 201;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID of the site.
 */
function gardner_venue_booking_site_id( string $domain ): int {
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
 * Requiring their main files gives the classes; the registrations invoked
 * here are idempotent, matching journeys/gardner-event-rsvp/seed.php.
 */
function gardner_venue_booking_bootstrap_events_plugins(): void {
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

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function gardner_venue_booking_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'gardner_venue_booking_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Describe an ability result for fixture evidence.
 *
 * @param mixed $result Ability result.
 * @return array
 */
function gardner_venue_booking_outcome( $result ): array {
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
 * @param int    $user_id      Forced user ID.
 * @param string $login        user_login.
 * @param string $email        user_email.
 * @param string $display_name display_name.
 * @return WP_User
 */
function gardner_venue_booking_force_user( int $user_id, string $login, string $email, string $display_name ): WP_User {
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
	'schema'  => 'extrachill-network/journey-fixture/gardner-venue-booking/v1',
	'persona' => 'extra-chill-users/chris-gardner@1.0.0',
	'steps'   => array(),
);

$events_blog_id = gardner_venue_booking_site_id( 'events.extrachill.com' );

wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );

gardner_venue_booking_bootstrap_events_plugins();

if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) ) {
	throw new RuntimeException( 'BookingSchema is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueBookingConfig' ) ) {
	throw new RuntimeException( 'VenueBookingConfig is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueMembershipRepository' ) ) {
	throw new RuntimeException( 'VenueMembershipRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingRepository' ) ) {
	throw new RuntimeException( 'BookingRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\TeamCapabilities' ) ) {
	throw new RuntimeException( 'TeamCapabilities is unavailable after bootstrapping extrachill-events.' );
}
if ( ! \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
	throw new RuntimeException( 'The booking schema tables are not ready; the rig activation step failed to fire extrachill-events\' activation hook (BookingSchema::install()).' );
}

/*
 * The venue. Lo-Fi Brewing is a real Charleston room Gardner works with, used
 * here with test-only contact details. No production contact data is seeded.
 */
$venue = wp_insert_term( 'Lo-Fi Brewing', 'venue' );
if ( is_wp_error( $venue ) ) {
	$existing_venue = get_term_by( 'slug', 'lo-fi-brewing', 'venue' );
	if ( ! $existing_venue ) {
		throw new RuntimeException( esc_html( 'Could not create the persona venue: ' . $venue->get_error_message() ) );
	}
	$venue_id = (int) $existing_venue->term_id;
} else {
	$venue_id = (int) $venue['term_id'];
}

update_term_meta( $venue_id, '_venue_address', '2038 Meeting Street Rd' );
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_zip', '29405' );
update_term_meta( $venue_id, '_venue_country', 'US' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );
update_term_meta( $venue_id, '_venue_capacity', 150 );
update_term_meta( $venue_id, '_venue_website', 'https://lofi-brewing.example.invalid' );

/*
 * Booking configuration. Two rooms, because Gardner books both the taproom
 * and the patio and constantly has to keep them straight.
 */
$config               = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->defaults();
$config['enabled']    = true;
$config['revision']   = 1;
$config['spaces']     = array(
	array(
		'key'        => 'taproom',
		'name'       => 'Taproom',
		'is_default' => true,
	),
	array(
		'key'        => 'patio',
		'name'       => 'Back Patio',
		'is_default' => false,
	),
);
$config['intake']     = array(
	'version' => 1,
	'fields'  => array(
		array(
			'key'      => 'draw',
			'type'     => 'text',
			'label'    => 'How many people do you usually draw in Charleston?',
			'required' => false,
		),
		array(
			'key'      => 'links',
			'type'     => 'url',
			'label'    => 'Links to your music',
			'required' => false,
		),
	),
);
$config['updated_at'] = gmdate( 'Y-m-d H:i:s' );
update_term_meta( $venue_id, \ExtraChillEvents\Core\VenueBookingConfig::META_KEY, $config );

/*
 * Prove the venue configuration is valid as the product reads it. An invalid
 * intake field silently invalidates the whole configuration, which would
 * then surface as a cascade of unrelated journey failures rather than as
 * the fixture defect it actually is.
 */
$stored_config = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->get( $venue_id );
if ( is_wp_error( $stored_config ) ) {
	throw new RuntimeException( esc_html( 'The seeded venue configuration is invalid: ' . $stored_config->get_error_message() ) );
}
$evidence['steps']['venue_config'] = array(
	'enabled'  => ! empty( $stored_config['enabled'] ),
	'spaces'   => count( (array) ( $stored_config['spaces'] ?? array() ) ),
	'revision' => (int) ( $stored_config['revision'] ?? 0 ),
);
if ( empty( $stored_config['enabled'] ) || count( (array) ( $stored_config['spaces'] ?? array() ) ) < 2 ) {
	throw new RuntimeException( 'The seeded venue configuration did not persist its enabled state and both spaces.' );
}

/*
 * Gardner's identity: the canonical rig persona (personas/gardner.v1.json),
 * forced at the same ID journeys/gardner-event-rsvp uses, with the exact
 * baseline capabilities and extra_chill_team role the contract declares.
 */
$gardner = gardner_venue_booking_force_user( GARDNER_VENUE_BOOKING_USER_ID, 'gardner_persona_fixture', 'gardner-persona@example.invalid', 'Chris Gardner (Test Persona)' );
if ( ! is_user_member_of_blog( GARDNER_VENUE_BOOKING_USER_ID, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, GARDNER_VENUE_BOOKING_USER_ID, 'extra_chill_team' );
}
if ( function_exists( 'ec_users_register_team_role' ) ) {
	ec_users_register_team_role();
}
if ( get_role( 'extra_chill_team' ) ) {
	$gardner->set_role( 'extra_chill_team' );
} else {
	$gardner->set_role( 'administrator' );
	$evidence['steps']['team_role_fallback'] = true;
}
foreach ( array( 'read', 'upload_files', 'edit_posts', 'edit_published_posts', 'delete_posts', 'access_events_admin', 'access_admin_bar', 'submit_for_review' ) as $capability ) {
	$gardner->add_cap( $capability );
}
$gardner->add_cap( 'manage_brand_socials' );

/*
 * A second team member with no membership at this venue. Gardner should
 * never be able to see another room's private booking data, and this user
 * proves the server enforces that rather than the UI merely hiding it. A
 * single-scenario fixture, not a persona contract.
 */
$outsider_id = wp_create_user( 'gardner_venue_booking_outsider', wp_generate_password( 24, true, true ), 'gardner-vb-outsider@example.invalid' );
if ( is_wp_error( $outsider_id ) ) { // @phpstan-ignore-line function.impossibleType -- wp_create_user() can return WP_Error; the stub's inferred type is narrower than core's real contract.
	throw new RuntimeException( 'Could not create the boundary fixture identity.' );
}
$outsider_id = (int) $outsider_id;
if ( ! is_user_member_of_blog( $outsider_id, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, $outsider_id, 'subscriber' );
}
$outsider_user = get_userdata( $outsider_id );
if ( $outsider_user instanceof WP_User ) {
	$outsider_user->add_cap( 'access_events_admin' );
}

wp_set_current_user( 1 );
$membership                            = gardner_venue_booking_execute(
	'extrachill/create-venue-membership',
	array(
		'venue_term_id' => $venue_id,
		'user_id'       => GARDNER_VENUE_BOOKING_USER_ID,
		'is_owner'      => true,
	)
);
$evidence['steps']['owner_membership'] = gardner_venue_booking_outcome( $membership );
if ( is_wp_error( $membership ) ) {
	$membership                                        = ( new \ExtraChillEvents\Core\VenueMembershipRepository() )->create(
		array(
			'venue_term_id'      => $venue_id,
			'user_id'            => GARDNER_VENUE_BOOKING_USER_ID,
			'is_owner'           => true,
			'status'             => 'active',
			'created_by_user_id' => 1,
		)
	);
	$evidence['steps']['owner_membership']['fallback'] = ! is_wp_error( $membership );
}

/*
 * The inbox Gardner opens on a Monday morning.
 *
 * Inbound artist submissions are written through BookingRepository rather
 * than the public `create-booking-inquiry` ability. That ability's
 * admission saga serializes on the MySQL-only `GET_LOCK` primitive, which
 * this runtime's database layer does not provide, so the public intake path
 * is out of scope for this journey and is already covered by
 * journeys/booking-invariants. Only the arrival of the inquiries is staged;
 * every action Gardner then takes as the venue operator runs through the
 * real registered abilities with their authorization, versioning, and
 * idempotency contracts intact.
 */
$intake = static function ( string $message, array $fields = array() ): array {
	return array(
		'config_revision' => 1,
		'message'         => $message,
		'fields'          => $fields,
		'consent'         => array(
			'id'       => 'booking-privacy',
			'version'  => 1,
			'accepted' => true,
		),
	);
};

$inquiries = array(
	array(
		'idempotency_key'     => 'gardner-persona-inquiry-1',
		'venue_term_id'       => $venue_id,
		'artist_name'         => 'Sun Room Collective',
		'contact_name'        => 'Maya Ellison',
		'contact_email'       => 'maya@sunroom.example.invalid',
		'contact_phone'       => '843-555-0142',
		'requested_space_key' => 'taproom',
		'requested_start_at'  => '2028-03-17 20:00:00',
		'requested_end_at'    => '2028-03-17 23:00:00',
		'intake'              => $intake(
			'We are routing through Charleston in March and would love a Friday at Lo-Fi. Three-piece, we bring our own sound person.',
			array(
				'draw'  => 90,
				'links' => array( 'https://sunroom.example.invalid/listen' ),
			)
		),
	),
	array(
		'idempotency_key'     => 'gardner-persona-inquiry-2',
		'venue_term_id'       => $venue_id,
		'artist_name'         => 'Palmetto Static',
		'contact_name'        => 'Devon Reyes',
		'contact_email'       => 'devon@palmettostatic.example.invalid',
		'requested_space_key' => 'patio',
		'requested_start_at'  => '2028-03-24 19:00:00',
		'requested_end_at'    => '2028-03-24 22:00:00',
		'intake'              => $intake(
			'Patio show for our record release. We can guarantee a good local crowd.',
			array( 'draw' => 140 )
		),
	),
	array(
		'idempotency_key'     => 'gardner-persona-inquiry-3',
		'venue_term_id'       => $venue_id,
		'artist_name'         => 'The Winnowing',
		'contact_name'        => 'Sam Okafor',
		'contact_email'       => 'sam@winnowing.example.invalid',
		'requested_space_key' => 'taproom',
		'requested_start_at'  => '2028-03-17 20:00:00',
		'requested_end_at'    => '2028-03-17 23:00:00',
		'intake'              => $intake(
			'Also asking about March 17. We know it is a popular night.',
			array( 'draw' => 60 )
		),
	),
);

$booking_repository = new \ExtraChillEvents\Core\BookingRepository();
$created            = array();
foreach ( $inquiries as $inquiry ) {
	$inquiry['inquiry_idempotency_key'] = $inquiry['idempotency_key'];
	unset( $inquiry['idempotency_key'] );

	$existing_inquiry = $booking_repository->find_inquiry( $venue_id, $inquiry['inquiry_idempotency_key'] );
	$result           = is_array( $existing_inquiry ) ? $existing_inquiry : $booking_repository->create( $inquiry );

	$created[ $inquiry['artist_name'] ] = gardner_venue_booking_outcome( $result );
	if ( is_array( $result ) ) {
		$created[ $inquiry['artist_name'] ]['booking_id'] = (int) ( $result['id'] ?? 0 );
	}
}
$evidence['steps']['inquiries'] = $created;

foreach ( $created as $artist_name => $outcome ) {
	if ( empty( $outcome['ok'] ) ) {
		throw new RuntimeException( esc_html( 'Could not stage the inbound inquiry for ' . $artist_name . ': ' . (string) ( $outcome['note'] ?? '' ) ) );
	}
}

/*
 * The console is a front-end route, not wp-admin. Publish the page the
 * persona actually navigates to (reused, not recreated, on a rerun).
 */
$venue_settings_page = get_page_by_path( 'venue-settings' );
if ( $venue_settings_page instanceof WP_Post ) {
	$page_id = (int) $venue_settings_page->ID;
} else {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Venue Settings',
			'post_name'    => 'venue-settings',
			'post_content' => '<!-- wp:extrachill/venue-settings /-->',
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( 'Could not publish the venue settings route.' );
	}
	$page_id = (int) $page_id;
}

/*
 * Prove the persona can actually reach the feature before the journey runs.
 *
 * Venue booking sits behind a `team` feature ceiling owned by Extra Chill
 * Users. If that gate is unsatisfied, every operator ability returns a
 * permission error and the journey would report a wall of false usability
 * findings. Failing loudly here keeps environment defects from being
 * misreported as product defects.
 */
$feature_ready = ( new \ExtraChillEvents\Core\TeamAuthorization() )->has_feature_access( GARDNER_VENUE_BOOKING_USER_ID );
$can_access    = true === \ExtraChillEvents\Core\TeamCapabilities::authorize( 'ec_access_venue', $venue_id, GARDNER_VENUE_BOOKING_USER_ID );

$evidence['steps']['feature_access'] = array(
	'feature_available' => $feature_ready,
	'can_access_venue'  => $can_access,
);

if ( ! $feature_ready || ! $can_access ) {
	throw new RuntimeException(
		esc_html(
			sprintf(
				'The persona cannot reach venue booking, so the journey would report false findings. feature_available=%s can_access_venue=%s',
				$feature_ready ? 'true' : 'false',
				$can_access ? 'true' : 'false'
			)
		)
	);
}

$evidence['venue_term_id'] = $venue_id;
$evidence['gardner_id']    = GARDNER_VENUE_BOOKING_USER_ID;
$evidence['outsider_id']   = $outsider_id;
$evidence['page_id']       = $page_id;
$evidence['console_url']   = get_permalink( $page_id ) . '#tab-calendar';
$evidence['booking_ids']   = array_values(
	array_filter(
		array_map(
			static function ( $entry ) {
				return $entry['booking_id'] ?? 0;
			},
			$created
		)
	)
);

update_option( 'ec_rig_journey_fixture_gardner_venue_booking', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
