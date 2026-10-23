<?php
/**
 * Seed for show-ratings-fan.
 *
 * Two past Goose shows in Charleston on the events site, a My Shows page,
 * and a non-technical fan account who attended both. The fan has marked
 * "I Was There" on the older show only (as an import would); the newer
 * show is unmarked so the journey walks the mark-then-rate path.
 *
 * @package ExtraChillNetwork
 */

const EC_RATINGS_FAN_ID = 711;

$sites = get_sites( array( 'domain' => 'events.extrachill.com', 'number' => 1 ) );
if ( empty( $sites ) ) {
	throw new RuntimeException( 'events.extrachill.com is not in the booted topology.' );
}
$events_blog_id = (int) $sites[0]->blog_id;

delete_site_option( 'ec_show_ratings_observations' );

global $wpdb;
if ( ! get_user_by( 'id', EC_RATINGS_FAN_ID ) ) {
	$wpdb->insert(
		$wpdb->users,
		array(
			'ID'              => EC_RATINGS_FAN_ID,
			'user_login'      => 'ratings_fan',
			'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
			'user_nicename'   => 'ratings_fan',
			'user_email'      => 'ratings-fan@example.invalid',
			'user_registered' => current_time( 'mysql', true ),
			'display_name'    => 'Dana Fan',
		)
	); // phpcs:ignore WordPress.DB
	clean_user_cache( EC_RATINGS_FAN_ID );
}

switch_to_blog( $events_blog_id );
wp_set_current_user( 1 );
if ( ! is_user_member_of_blog( EC_RATINGS_FAN_ID, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, EC_RATINGS_FAN_ID, 'subscriber' );
}

require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
if ( ! post_type_exists( 'data_machine_events' ) ) {
	\DataMachineEvents\Core\Event_Post_Type::register();
}
foreach ( array( 'Venue_Taxonomy', 'Promoter_Taxonomy', 'Event_Type_Taxonomy' ) as $taxonomy_class ) {
	$fqcn = "\\DataMachineEvents\\Core\\{$taxonomy_class}";
	if ( class_exists( $fqcn ) ) {
		$fqcn::register();
	}
}
require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
	extrachill_events_register_taxonomies();
}
if ( ! taxonomy_exists( 'artist' ) ) {
	throw new RuntimeException( 'The artist taxonomy is not registered on the events site.' );
}
if ( ! function_exists( 'ec_users_mark_event' ) ) {
	throw new RuntimeException( 'extrachill-users concert tracking is unavailable.' );
}

$venue = term_exists( 'Charleston Pour House', 'venue' );
$venue = $venue ? $venue : wp_insert_term( 'Charleston Pour House', 'venue' );
$venue_id = (int) $venue['term_id'];
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_address', '1977 Maybank Hwy' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );

$artist = term_exists( 'Goose', 'artist' );
$artist = $artist ? $artist : wp_insert_term( 'Goose', 'artist', array( 'slug' => 'goose' ) );
$artist_id = (int) $artist['term_id'];

$make = static function ( string $slug, string $title, int $days_ago ) use ( $venue_id, $artist_id ): int {
	$date    = gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) );
	$attrs   = wp_json_encode(
		array(
			'startDate' => $date,
			'endDate'   => $date,
			'startTime' => '20:00',
			'endTime'   => '23:30',
			'venue'     => 'Charleston Pour House',
		)
	);
	$content = "<!-- wp:data-machine-events/event-details {$attrs} -->\n<div class=\"wp-block-data-machine-events-event-details\"><!-- wp:paragraph -->\n<p>Goose brings the jams to the Pour House deck.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:data-machine-events/event-details -->";
	$existing = get_page_by_path( $slug, OBJECT, 'data_machine_events' );
	$id       = $existing ? (int) $existing->ID : wp_insert_post(
		array(
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_author'  => 1,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( $id->get_error_message() ) );
	}
	if ( function_exists( 'data_machine_events_sync_datetime_meta' ) ) {
		data_machine_events_sync_datetime_meta( (int) $id, get_post( $id ), false );
	}
	wp_set_object_terms( (int) $id, array( $venue_id ), 'venue', false );
	wp_set_object_terms( (int) $id, array( $artist_id ), 'artist', false );
	if ( null === \DataMachineEvents\Core\EventDatesTable::get( (int) $id ) ) {
		throw new RuntimeException( 'Event dates did not sync for ' . esc_html( $slug ) );
	}
	return (int) $id;
};

$recent = $make( 'goose-charleston-pour-house-fall', 'Goose at Charleston Pour House', 30 );
$older  = $make( 'goose-charleston-pour-house-summer', 'Goose at Charleston Pour House (Summer)', 90 );

// The older show is already in the fan's history, as an import would leave it.
ec_users_mark_event( EC_RATINGS_FAN_ID, $older, $events_blog_id );

if ( ! get_page_by_path( 'my-shows' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'My Shows',
			'post_name'    => 'my-shows',
			'post_content' => '<!-- wp:extrachill/concert-stats /-->',
		)
	);
}

$fixture = array(
	'schema'         => 'extrachill-network/journey-fixture/show-ratings-fan/v1',
	'events_blog_id' => $events_blog_id,
	'fan_id'         => EC_RATINGS_FAN_ID,
	'recent_id'      => $recent,
	'older_id'       => $older,
	'recent_url'     => get_permalink( $recent ),
	'older_url'      => get_permalink( $older ),
	'artist_url'     => get_term_link( $artist_id, 'artist' ),
	'venue_url'      => get_term_link( $venue_id, 'venue' ),
	'options'        => array(
		'comment_moderation'          => get_option( 'comment_moderation' ),
		'comment_previously_approved' => get_option( 'comment_previously_approved' ),
		'comment_registration'        => get_option( 'comment_registration' ),
	),
);

restore_current_blog();
wp_set_current_user( 0 );

update_site_option( 'ec_rig_journey_fixture_show_ratings_fan', $fixture );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $fixture ) ) );
