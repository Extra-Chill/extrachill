<?php
/**
 * Verify festival and artist topic notification hooks without mutating site data.
 *
 * Run with: wp eval-file scripts/smoke-festival-topic-notifications.php
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$authorized = apply_filters(
	'extrachill_users_entity_subscription_producer_authorized',
	false,
	'extrachill-community',
	array(
		'entity_type' => 'festival',
		'taxonomy'    => 'festival',
		'slug'        => 'smoke-test',
	),
	'email'
);

if ( ! $authorized ) {
	WP_CLI::error( 'Community festival notification producer is not authorized.' );
}

$artist_authorized = apply_filters(
	'extrachill_users_entity_subscription_producer_authorized',
	false,
	'extrachill-community',
	array(
		'entity_type' => 'artist',
		'taxonomy'    => 'artist',
		'slug'        => 'smoke-test',
	),
	'notification'
);

if ( ! $artist_authorized ) {
	WP_CLI::error( 'Community artist notification producer is not authorized.' );
}

if ( 30 !== has_action( 'bbp_new_topic', 'extrachill_community_notify_festival_topic_subscribers' ) ) {
	WP_CLI::error( 'Festival topic notification capture is not registered.' );
}

if ( 30 !== has_action( 'bbp_new_topic', 'extrachill_community_notify_artist_topic_subscribers' ) ) {
	WP_CLI::error( 'Artist topic notification capture is not registered.' );
}

if ( has_action( 'bbp_new_reply', 'extrachill_community_notify_festival_topic_subscribers' ) || has_action( 'bbp_edit_topic', 'extrachill_community_notify_festival_topic_subscribers' ) ) {
	WP_CLI::error( 'Festival topic notification capture must only observe new topics.' );
}

if ( has_action( 'bbp_new_reply', 'extrachill_community_notify_artist_topic_subscribers' ) || has_action( 'bbp_edit_topic', 'extrachill_community_notify_artist_topic_subscribers' ) ) {
	WP_CLI::error( 'Artist topic notification capture must only observe new topics.' );
}

WP_CLI::success( 'Festival and artist topic notification smoke checks passed.' );
