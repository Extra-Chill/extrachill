<?php
/** Deterministic show-goer fixtures; no rating or eligibility stubs. */
$sites = get_sites( array( 'domain' => 'events.extrachill.com', 'number' => 1 ) );
if ( ! $sites ) {
	throw new RuntimeException( 'Events site missing' );
}
switch_to_blog( (int) $sites[0]->blog_id );
require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
\DataMachineEvents\Core\Event_Post_Type::register();
\DataMachineEvents\Core\EventRatings::register();
\DataMachineEvents\Core\Venue_Taxonomy::register();
if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
	extrachill_events_register_taxonomies();
}
wp_set_current_user( 1 );
global $wpdb;
foreach ( array( 751 => 'ratings-owner', 752 => 'ratings-viewer' ) as $id => $login ) {
	if ( ! get_userdata( $id ) ) {
		$ok = $wpdb->insert( $wpdb->users, array( 'ID' => $id, 'user_login' => $login, 'user_nicename' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_hash_password( wp_generate_password( 32 ) ), 'display_name' => $login, 'user_registered' => current_time( 'mysql', true ) ) );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not create fixture user' );
		}
		clean_user_cache( $id );
	}
	add_user_to_blog( get_current_blog_id(), $id, 'subscriber' );
	update_user_meta( $id, '_extrachill_concert_history_visibility', 'public' );
	update_user_meta( $id, '_extrachill_event_attendance_visibility', 'public' );
}
$fixture = array();
foreach ( array( 'past' => '-10 days', 'inline' => '-20 days', 'future' => '+30 days' ) as $key => $offset ) {
	$slug = 'ratings-' . $key;
	$date = gmdate( 'Y-m-d', strtotime( $offset ) );
	$attrs = wp_json_encode( array( 'startDate' => $date, 'endDate' => $date, 'startTime' => '18:00', 'endTime' => '21:00', 'venue' => 'Ratings Hall' ) );
	$existing = get_page_by_path( $slug, OBJECT, 'data_machine_events' );
	$post = array( 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Ratings Band ' . $key, 'post_name' => $slug, 'comment_status' => 'open', 'post_content' => '<!-- wp:data-machine-events/event-details ' . $attrs . ' --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->' );
	if ( $existing ) {
		$post['ID'] = $existing->ID;
	}
	$id = wp_insert_post( $post, true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	\DataMachineEvents\Core\data_machine_events_sync_datetime_meta( $id, get_post( $id ), false );
	foreach ( array( 'artist' => 'Ratings Band', 'venue' => 'Ratings Hall', 'location' => 'Charleston' ) as $taxonomy => $name ) {
		$term = term_exists( $name, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term( $name, $taxonomy );
		}
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( $term->get_error_message() );
		}
		wp_set_object_terms( $id, array( (int) $term['term_id'] ), $taxonomy );
	}
	$fixture[ $key ] = $id;
}
$result = ec_users_mark_event( 751, $fixture['inline'], get_current_blog_id() );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
$page = get_page_by_path( 'my-shows' );
if ( ! $page ) {
	$result = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'my-shows', 'post_title' => 'My Shows', 'post_content' => '<!-- wp:extrachill/concert-stats /-->' ), true );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
}
update_option( 'ec_rig_show_ratings', $fixture, false );
flush_rewrite_rules( false );
restore_current_blog();
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( wp_json_encode( $fixture ) ) );
