<?php
/**
 * Seed the venue-booking backend-invariant fixtures on the real network boot.
 *
 * Ported from extrachill-events/tests/NetworkE2E/booking/topology.php
 * (extrachill-events#292). The original file built its own disposable
 * multisite topology (wpmu_create_blog() at hardcoded blog IDs) because it
 * ran in an isolated single-purpose WordPress instance; this rig already
 * boots the real 11-site topology with every plugin activated, so this seed
 * only resolves the sites it needs BY DOMAIN and stages the two venues, two
 * users, and one venue membership the invariant assertions in grade.php
 * depend on.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function booking_invariants_site_id( string $domain ): int {
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

/** Bootstrap the events site's per-site plugin context (idempotent). */
function booking_invariants_bootstrap_events_plugins(): void {
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
function booking_invariants_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'booking_invariants_ability_missing', $name );
	}
	return $ability->execute( $input );
}

/**
 * Build a deterministic enabled venue configuration.
 *
 * @return array
 */
function booking_invariants_config(): array {
	if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueBookingConfig' ) ) {
		throw new RuntimeException( 'VenueBookingConfig is unavailable after bootstrapping extrachill-events.' );
	}
	$config               = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->defaults();
	$config['enabled']    = true;
	$config['revision']   = 1;
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

$events_blog_id = booking_invariants_site_id( 'events.extrachill.com' );
$main_blog_id   = booking_invariants_site_id( 'extrachill.com' );
$studio_blog_id = booking_invariants_site_id( 'studio.extrachill.com' );

$evidence = array(
	'schema' => 'extrachill-network/journey-fixture/booking-invariants/v1',
	'sites'  => array(
		'events' => $events_blog_id,
		'main'   => $main_blog_id,
		'studio' => $studio_blog_id,
	),
	'steps'  => array(),
);

wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );
booking_invariants_bootstrap_events_plugins();

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
if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingActivityRepository' ) ) {
	throw new RuntimeException( 'BookingActivityRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
	throw new RuntimeException( 'The booking schema tables are not ready; the rig activation step failed to fire extrachill-events\' activation hook (BookingSchema::install()).' );
}

$venue_a = wp_insert_term( 'Booking Invariants Room A', 'venue' );
$venue_b = wp_insert_term( 'Booking Invariants Room B', 'venue' );
if ( is_wp_error( $venue_a ) ) {
	$existing = get_term_by( 'slug', 'booking-invariants-room-a', 'venue' );
	$venue_a  = $existing ? array( 'term_id' => $existing->term_id ) : $venue_a;
}
if ( is_wp_error( $venue_b ) ) {
	$existing = get_term_by( 'slug', 'booking-invariants-room-b', 'venue' );
	$venue_b  = $existing ? array( 'term_id' => $existing->term_id ) : $venue_b;
}
if ( is_wp_error( $venue_a ) || is_wp_error( $venue_b ) ) {
	throw new RuntimeException( 'Could not create the booking-invariants venues.' );
}
$venue_a_id = (int) $venue_a['term_id'];
$venue_b_id = (int) $venue_b['term_id'];

foreach ( array( $venue_a_id, $venue_b_id ) as $venue_id ) {
	update_term_meta( $venue_id, \ExtraChillEvents\Core\VenueBookingConfig::META_KEY, booking_invariants_config() );
	update_term_meta( $venue_id, '_venue_address', '42 Invariants Street' );
	update_term_meta( $venue_id, '_venue_city', 'Charleston' );
	update_term_meta( $venue_id, '_venue_state', 'SC' );
	update_term_meta( $venue_id, '_venue_zip', '29403' );
	update_term_meta( $venue_id, '_venue_country', 'US' );
	update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );
}

$operator    = get_user_by( 'login', 'booking_invariants_operator' );
$operator_id = $operator ? (int) $operator->ID : wp_create_user( 'booking_invariants_operator', wp_generate_password( 32, true, true ), 'booking-invariants-operator@example.invalid' );
$outsider    = get_user_by( 'login', 'booking_invariants_outsider' );
$outsider_id = $outsider ? (int) $outsider->ID : wp_create_user( 'booking_invariants_outsider', wp_generate_password( 32, true, true ), 'booking-invariants-outsider@example.invalid' );
if ( is_wp_error( $operator_id ) || is_wp_error( $outsider_id ) ) { // @phpstan-ignore-line function.impossibleType -- wp_create_user() can return WP_Error; the stub's inferred type is narrower than core's real contract.
	throw new RuntimeException( 'Could not create the booking-invariants fixture users.' );
}
$operator_id = (int) $operator_id;
$outsider_id = (int) $outsider_id;

/*
 * The `venue_booking` feature sits behind a `team` rollout ceiling
 * (extrachill-events LifecycleProvider::register_feature_ceilings()). That
 * tier's bypass is `user_can( $id, 'manage_options' )` OR
 * `ec_is_team_member( $id )` -- a per-site `administrator` role on this
 * network does NOT carry `manage_options` (verified empirically: an
 * operator seeded with the `administrator` role failed
 * TeamAuthorization::has_feature_access() with `user_can_manage_options:
 * false`), so the fixture users need the real `extra_chill_team` role, the
 * same as journeys/gardner-venue-booking's persona.
 */
if ( function_exists( 'ec_users_register_team_role' ) ) {
	ec_users_register_team_role();
}
if ( ! is_user_member_of_blog( $operator_id, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, $operator_id, get_role( 'extra_chill_team' ) ? 'extra_chill_team' : 'administrator' );
}
if ( ! is_user_member_of_blog( $outsider_id, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, $outsider_id, get_role( 'extra_chill_team' ) ? 'extra_chill_team' : 'administrator' );
}
$operator_user = get_userdata( $operator_id );
if ( $operator_user instanceof WP_User ) {
	if ( get_role( 'extra_chill_team' ) ) {
		$operator_user->set_role( 'extra_chill_team' );
	}
	$operator_user->add_cap( 'access_events_admin' );
}
$outsider_user = get_userdata( $outsider_id );
if ( $outsider_user instanceof WP_User ) {
	// Same team role and feature access as the operator, deliberately WITHOUT
	// a membership at venue_a: this proves the denial is venue-scoped
	// membership, not the feature-tier gate every team member already clears.
	if ( get_role( 'extra_chill_team' ) ) {
		$outsider_user->set_role( 'extra_chill_team' );
	}
	$outsider_user->add_cap( 'access_events_admin' );
}

$membership = booking_invariants_execute(
	'extrachill/create-venue-membership',
	array(
		'venue_term_id' => $venue_a_id,
		'user_id'       => $operator_id,
		'is_owner'      => true,
	)
);
if ( is_wp_error( $membership ) ) {
	$membership = ( new \ExtraChillEvents\Core\VenueMembershipRepository() )->create(
		array(
			'venue_term_id'      => $venue_a_id,
			'user_id'            => $operator_id,
			'is_owner'           => true,
			'status'             => 'active',
			'created_by_user_id' => 1,
		)
	);
}
$evidence['steps']['owner_membership'] = array( 'ok' => ! is_wp_error( $membership ) );

$evidence['venue_a_id']  = $venue_a_id;
$evidence['venue_b_id']  = $venue_b_id;
$evidence['operator_id'] = $operator_id;
$evidence['outsider_id'] = $outsider_id;
$evidence['timezone']    = 'America/New_York';
$evidence['date']        = '2028-01-05';
$evidence['next_date']   = '2028-01-06';
$evidence['start']       = '20:00:00';
$evidence['end']         = '23:00:00';
$evidence['capacity']    = 300;
$evidence['price']       = 1500;

update_option( 'ec_rig_journey_fixture_booking_invariants', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
