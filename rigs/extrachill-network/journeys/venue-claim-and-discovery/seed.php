<?php
/**
 * Seed a real venue archive claim scenario and a real Charleston venue
 * directory on the events site.
 *
 * Ported from extrachill-events/tests/browser/venue-claim-acquisition.evidence.js
 * + venue-claim-archive-fixture.php and tests/browser/city-calendar-priority.evidence.js
 * (extrachill-events#292). The originals reproduced inc/templates/archive.php
 * and inc/templates/location-venue-badges.php by hand behind a fully mocked
 * WordPress runtime; this seed instead creates the real content those
 * templates render from, so the browser steps exercise the real templates.
 *
 * Simplification, documented here rather than silently: the original city-
 * calendar fixture used an arbitrary 22/97-venue count purely to prove the
 * collapsed-disclosure pattern scales past a handful of badges. This seed
 * creates 5 venues (>= the plugin's own `extrachill_events_venue_badge_min_count`
 * filter default of 3 upcoming events each) -- enough to prove the same
 * collapse/reveal behavior for real, without the cost of seeding dozens of
 * venues x 3 real events apiece. See the journey README.
 *
 * @package ExtraChillNetwork
 */

const VENUE_CLAIM_NONMEMBER_USER_ID = 301;
const VENUE_CLAIM_MEMBER_USER_ID    = 302;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function venue_claim_site_id( string $domain ): int {
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
function venue_claim_bootstrap_events_plugins(): void {
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
 * Force-create (or reuse) a fixture user at an exact, deterministic ID.
 *
 * @param int    $user_id      Forced user ID.
 * @param string $login        user_login.
 * @param string $email        user_email.
 * @return WP_User
 */
function venue_claim_force_user( int $user_id, string $login, string $email ): WP_User {
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
				'display_name'    => $login,
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

/**
 * Seed one published, future-dated event at a venue and location.
 *
 * @param string $slug        Unique post slug.
 * @param string $title       Post title.
 * @param int    $venue_id    Venue term ID.
 * @param int    $location_id Location term ID.
 * @param string $start       MySQL start datetime.
 * @param string $end         MySQL end datetime.
 */
function venue_claim_seed_event( string $slug, string $title, int $venue_id, int $location_id, string $start, string $end ): void {
	$existing = get_page_by_path( $slug, OBJECT, 'data_machine_events' );
	if ( $existing instanceof WP_Post ) {
		$event_id = (int) $existing->ID;
	} else {
		$attrs        = wp_json_encode(
			array(
				'startDate' => substr( $start, 0, 10 ),
				'endDate'   => substr( $end, 0, 10 ),
				'startTime' => substr( $start, 11, 5 ),
				'endTime'   => substr( $end, 11, 5 ),
			)
		);
		$post_content = "<!-- wp:data-machine-events/event-details {$attrs} -->\n<div class=\"wp-block-data-machine-events-event-details\"></div>\n<!-- /wp:data-machine-events/event-details -->";
		$event_id     = wp_insert_post(
			array(
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $post_content,
			),
			true
		);
		if ( is_wp_error( $event_id ) ) {
			throw new RuntimeException( esc_html( 'Could not seed venue-directory event ' . $slug . ': ' . $event_id->get_error_message() ) );
		}
		$event_id = (int) $event_id;
		if ( function_exists( 'data_machine_events_sync_datetime_meta' ) ) {
			data_machine_events_sync_datetime_meta( $event_id, get_post( $event_id ), false );
		}
	}
	wp_set_object_terms( $event_id, array( $venue_id ), 'venue', false );
	wp_set_object_terms( $event_id, array( $location_id ), 'location', false );
}

$events_blog_id = venue_claim_site_id( 'events.extrachill.com' );

$evidence = array(
	'schema' => 'extrachill-network/journey-fixture/venue-claim-and-discovery/v1',
	'steps'  => array(),
);

wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );
venue_claim_bootstrap_events_plugins();

/*
 * ---------------------------------------------------------------------------
 * The claim-acquisition venue: booking enabled and public, so the CTA and
 * the workspace disclosure both render on its archive.
 * ---------------------------------------------------------------------------
 */
if ( class_exists( '\\ExtraChillEvents\\Core\\VenueBookingConfig' ) ) {
	$venue = wp_insert_term( 'Venue Claim Fixture Room', 'venue', array( 'slug' => 'venue-claim-fixture-room' ) );
	if ( is_wp_error( $venue ) ) {
		$existing_venue = get_term_by( 'slug', 'venue-claim-fixture-room', 'venue' );
		if ( ! $existing_venue ) {
			throw new RuntimeException( esc_html( 'Could not create the venue-claim fixture venue: ' . $venue->get_error_message() ) );
		}
		$venue_id = (int) $existing_venue->term_id;
	} else {
		$venue_id = (int) $venue['term_id'];
	}
	update_term_meta( $venue_id, '_venue_address', '10 Claim Street' );
	update_term_meta( $venue_id, '_venue_city', 'Charleston' );
	update_term_meta( $venue_id, '_venue_state', 'SC' );
	update_term_meta( $venue_id, '_venue_zip', '29403' );
	update_term_meta( $venue_id, '_venue_country', 'US' );

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
	update_term_meta( $venue_id, \ExtraChillEvents\Core\VenueBookingConfig::META_KEY, $config );

	$nonmember = venue_claim_force_user( VENUE_CLAIM_NONMEMBER_USER_ID, 'venue_claim_nonmember_fixture', 'venue-claim-nonmember@example.invalid' );
	if ( ! is_user_member_of_blog( VENUE_CLAIM_NONMEMBER_USER_ID, $events_blog_id ) ) {
		add_user_to_blog( $events_blog_id, VENUE_CLAIM_NONMEMBER_USER_ID, 'subscriber' );
	}
	$nonmember->add_cap( 'access_events_admin' );

	/*
	 * The `venue_booking` feature sits behind a `team` rollout ceiling
	 * (extrachill-events LifecycleProvider::register_feature_ceilings()).
	 * That tier's bypass is `user_can( $id, 'manage_options' )` OR
	 * `ec_is_team_member( $id )`. A plain `subscriber` satisfies neither, so
	 * the active-member fixture needs the real `extra_chill_team` role --
	 * verified empirically: without it, `TeamCapabilities::authorize()`
	 * fails the feature-tier gate even with an active membership row, and
	 * the archive workspace action falls through to "non_member".
	 */
	if ( function_exists( 'ec_users_register_team_role' ) ) {
		ec_users_register_team_role();
	}
	$member = venue_claim_force_user( VENUE_CLAIM_MEMBER_USER_ID, 'venue_claim_member_fixture', 'venue-claim-member@example.invalid' );
	if ( ! is_user_member_of_blog( VENUE_CLAIM_MEMBER_USER_ID, $events_blog_id ) ) {
		add_user_to_blog( $events_blog_id, VENUE_CLAIM_MEMBER_USER_ID, get_role( 'extra_chill_team' ) ? 'extra_chill_team' : 'subscriber' );
	}
	if ( get_role( 'extra_chill_team' ) ) {
		$member->set_role( 'extra_chill_team' );
	}
	$member->add_cap( 'access_events_admin' );

	$ability    = wp_get_ability( 'extrachill/create-venue-membership' );
	$membership = $ability ? $ability->execute(
		array(
			'venue_term_id' => $venue_id,
			'user_id'       => VENUE_CLAIM_MEMBER_USER_ID,
			'is_owner'      => true,
		)
	) : new WP_Error( 'venue_claim_ability_missing', 'extrachill/create-venue-membership' );
	if ( is_wp_error( $membership ) && class_exists( '\\ExtraChillEvents\\Core\\VenueMembershipRepository' ) ) {
		( new \ExtraChillEvents\Core\VenueMembershipRepository() )->create(
			array(
				'venue_term_id'      => $venue_id,
				'user_id'            => VENUE_CLAIM_MEMBER_USER_ID,
				'is_owner'           => true,
				'status'             => 'active',
				'created_by_user_id' => 1,
			)
		);
	}

	$evidence['venue_id'] = $venue_id;
}

/*
 * ---------------------------------------------------------------------------
 * The Charleston venue directory: 5 venues, each with >= 3 upcoming events,
 * so location-venue-badges.php's own visibility filter renders every one.
 * ---------------------------------------------------------------------------
 */
$usa        = wp_insert_term( 'United States', 'location', array( 'slug' => 'usa' ) );
$usa_term   = get_term_by( 'slug', 'usa', 'location' );
$usa_id     = is_wp_error( $usa ) ? ( $usa_term instanceof WP_Term ? (int) $usa_term->term_id : 0 ) : (int) $usa['term_id'];
$sc         = wp_insert_term(
	'South Carolina',
	'location',
	array(
		'slug'   => 'south-carolina',
		'parent' => $usa_id,
	)
);
$sc_term    = get_term_by( 'slug', 'south-carolina', 'location' );
$sc_id      = is_wp_error( $sc ) ? ( $sc_term instanceof WP_Term ? (int) $sc_term->term_id : 0 ) : (int) $sc['term_id'];
$charleston = wp_insert_term(
	'Charleston',
	'location',
	array(
		'slug'   => 'charleston',
		'parent' => $sc_id,
	)
);
if ( is_wp_error( $charleston ) ) {
	$charleston_term = get_term_by( 'slug', 'charleston', 'location' );
	if ( ! $charleston_term ) {
		throw new RuntimeException( esc_html( 'Could not create the Charleston location term: ' . $charleston->get_error_message() ) );
	}
	$charleston_id = (int) $charleston_term->term_id;
} else {
	$charleston_id = (int) $charleston['term_id'];
}

$seeded_venue_ids = array();
for ( $venue_index = 1; $venue_index <= 5; $venue_index++ ) {
	$slug     = 'city-calendar-venue-' . $venue_index;
	$existing = get_term_by( 'slug', $slug, 'venue' );
	if ( $existing ) {
		$directory_venue_id = (int) $existing->term_id;
	} else {
		$created = wp_insert_term( 'City Calendar Venue ' . $venue_index, 'venue', array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( esc_html( 'Could not create city-calendar venue ' . $venue_index . ': ' . $created->get_error_message() ) );
		}
		$directory_venue_id = (int) $created['term_id'];
	}
	$seeded_venue_ids[] = $directory_venue_id;

	for ( $show_index = 1; $show_index <= 3; $show_index++ ) {
		$day   = 10 + ( $venue_index * 3 ) + $show_index;
		$start = sprintf( '2028-11-%02d 20:00:00', min( $day, 28 ) );
		$end   = sprintf( '2028-11-%02d 23:00:00', min( $day, 28 ) );
		venue_claim_seed_event(
			sprintf( 'city-calendar-venue-%d-show-%d', $venue_index, $show_index ),
			sprintf( 'City Calendar Venue %d Show %d', $venue_index, $show_index ),
			$directory_venue_id,
			$charleston_id,
			$start,
			$end
		);
	}
}

$evidence['charleston_location_id'] = $charleston_id;
$evidence['directory_venue_ids']    = $seeded_venue_ids;

update_option( 'ec_rig_journey_fixture_venue_claim_and_discovery', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
