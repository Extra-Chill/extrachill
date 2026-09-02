<?php
/**
 * Seed the Chris Gardner venue-booking persona world.
 *
 * Gardner is the canonical nontechnical power user described by
 * `extra-chill-users/chris-gardner` v1.0.0. This scenario places him where he
 * actually works: managing bookings for one real neighborhood venue.
 *
 * Ownership boundary: Extra Chill Users owns Gardner's identity, traits, and
 * oracle vocabulary. This file owns only the Events-side booking scenario --
 * the venue, its configuration, its membership, and the inbound inquiries
 * already waiting for him. Identity is consumed, never redefined.
 *
 * Everything is created through registered abilities wherever an ability owns
 * the write, so the fixture cannot drift from the product contract it tests.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Core\VenueMembershipRepository;

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

$evidence = array(
	'schema'  => 'extrachill-events/gardner-venue-booking-fixture/v1',
	'persona' => 'network-nontechnical-power-user',
	'steps'   => array(),
);

if ( ! BookingSchema::install() || ! BookingSchema::is_ready() ) {
	throw new RuntimeException( 'Booking schema is not ready; the persona cannot be seeded.' );
}

/*
 * The venue. Lo-Fi Brewing is a real Charleston room Gardner works with, used
 * here with test-only contact details. No production contact data is seeded.
 */
$venue = wp_insert_term( 'Lo-Fi Brewing', 'venue' );
if ( is_wp_error( $venue ) ) {
	throw new RuntimeException( esc_html( 'Could not create the persona venue: ' . $venue->get_error_message() ) );
}
$venue_id = (int) $venue['term_id'];

update_term_meta( $venue_id, '_venue_address', '2038 Meeting Street Rd' );
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_zip', '29405' );
update_term_meta( $venue_id, '_venue_country', 'US' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );
update_term_meta( $venue_id, '_venue_capacity', 150 );
update_term_meta( $venue_id, '_venue_website', 'https://lofi-brewing.example.invalid' );

/*
 * Booking configuration. Two rooms, because Gardner books both the taproom and
 * the patio and constantly has to keep them straight.
 */
$config               = ( new VenueBookingConfig() )->defaults();
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
update_term_meta( $venue_id, VenueBookingConfig::META_KEY, $config );

/*
 * Prove the venue configuration is valid as the product reads it. An invalid
 * intake field silently invalidates the whole configuration, which would then
 * surface as a cascade of unrelated journey failures rather than as the
 * fixture defect it actually is.
 */
$stored_config = ( new VenueBookingConfig() )->get( $venue_id );
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
 * Gardner's identity, consumed from the canonical Users contract. The fixture
 * uses the contract's non-production identity fields verbatim.
 */
$gardner_id = wp_create_user( 'gardner_persona_fixture', wp_generate_password( 24, true, true ), 'gardner-persona@example.invalid' );
if ( is_wp_error( $gardner_id ) ) {
	throw new RuntimeException( 'Could not create the Gardner fixture identity.' );
}
$gardner_id = (int) $gardner_id;
wp_update_user(
	array(
		'ID'           => $gardner_id,
		'display_name' => 'Chris Gardner (Test Persona)',
		'role'         => 'administrator',
	)
);

$gardner = get_userdata( $gardner_id );
foreach ( array( 'read', 'upload_files', 'edit_posts', 'edit_published_posts', 'delete_posts', 'access_events_admin', 'access_admin_bar', 'submit_for_review' ) as $capability ) {
	$gardner->add_cap( $capability );
}

/*
 * A second team member with no membership at this venue. Gardner should never
 * be able to see another room's private booking data, and this user proves the
 * server enforces that rather than the UI merely hiding it.
 */
$outsider_id = wp_create_user( 'gardner_persona_outsider', wp_generate_password( 24, true, true ), 'gardner-outsider@example.invalid' );
if ( is_wp_error( $outsider_id ) ) {
	throw new RuntimeException( 'Could not create the boundary fixture identity.' );
}
$outsider_id = (int) $outsider_id;
get_userdata( $outsider_id )->add_cap( 'access_events_admin' );

wp_set_current_user( 1 );
$membership                            = gardner_seed_execute(
	'extrachill/create-venue-membership',
	array(
		'venue_term_id' => $venue_id,
		'user_id'       => $gardner_id,
		'is_owner'      => true,
	)
);
$evidence['steps']['owner_membership'] = gardner_seed_outcome( $membership );
if ( is_wp_error( $membership ) ) {
	$membership                                        = ( new VenueMembershipRepository() )->create(
		array(
			'venue_term_id'      => $venue_id,
			'user_id'            => $gardner_id,
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
 * Inbound artist submissions are written through BookingRepository rather than
 * the public `create-booking-inquiry` ability. That ability's admission saga
 * serializes on the MySQL-only `GET_LOCK` primitive, which this WordPress
 * runtime's database layer does not provide, so the public intake path is out
 * of scope for this journey and is already covered by the MySQL-backed Booking
 * Network E2E gate.
 *
 * Only the arrival of the inquiries is staged. Every action Gardner then takes
 * as the venue operator -- the surface this journey exists to evaluate -- runs
 * through the real registered abilities with their authorization, versioning,
 * and idempotency contracts intact.
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

	$result                             = $booking_repository->create( $inquiry );
	$created[ $inquiry['artist_name'] ] = gardner_seed_outcome( $result );
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
 * The console is a front-end route, not wp-admin. Publish the page the persona
 * actually navigates to.
 */
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Venue Settings',
		'post_name'    => 'venue-settings',
		'post_content' => '<!-- wp:extrachill/venue-settings /-->',
	)
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( 'Could not publish the venue settings route.' );
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
$authorization = new \ExtraChillEvents\Core\VenueAuthorization();
$feature_ready = $authorization->has_feature_access( $gardner_id );
$can_access    = true === $authorization->authorize( $gardner_id, $venue_id, \ExtraChillEvents\Core\VenueAuthorization::ACTION_ACCESS_VENUE );

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
$evidence['gardner_id']    = $gardner_id;
$evidence['outsider_id']   = $outsider_id;
$evidence['page_id']       = (int) $page_id;
$evidence['console_url']   = '/?page_id=' . (int) $page_id . '&venue_id=' . $venue_id;
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

update_option( 'gardner_persona_fixture', $evidence, false );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "GARDNER_FIXTURE_RESULT:%s\n", base64_encode( wp_json_encode( $evidence ) ) );
