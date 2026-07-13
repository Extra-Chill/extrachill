<?php
/**
 * Verify artist discussion taxonomy wiring without mutating site data.
 *
 * Run with: wp eval-file scripts/smoke-artist-topic-taxonomy.php
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$artist_taxonomy = get_taxonomy( 'artist' );
if ( ! $artist_taxonomy || ! in_array( 'topic', $artist_taxonomy->object_type, true ) ) {
	WP_CLI::error( 'Artist taxonomy is not registered for bbPress topics.' );
}

$artist_picker = null;
foreach ( extrachill_community_term_picker_taxonomies() as $picker ) {
	if ( 'artist' === $picker['taxonomy'] ) {
		$artist_picker = $picker;
		break;
	}
}

if ( ! $artist_picker || 'bbp_topic_artist' !== $artist_picker['field'] ) {
	WP_CLI::error( 'Artist topic picker configuration is missing or invalid.' );
}

if ( 20 !== has_action( 'bbp_new_topic', 'extrachill_community_save_topic_artist' ) || 20 !== has_action( 'bbp_edit_topic', 'extrachill_community_save_topic_artist' ) ) {
	WP_CLI::error( 'Artist topic persistence hooks are not registered.' );
}

$query                   = new WP_Query();
$query->is_tax           = true;
$query->queried_object   = (object) array( 'taxonomy' => 'artist' );
$previous_wp_query     = $GLOBALS['wp_query'];
$previous_wp_the_query = $GLOBALS['wp_the_query'];
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Smoke script intentionally swaps the main query to simulate an artist archive request, then restores it below.
$GLOBALS['wp_query']     = $query;
$GLOBALS['wp_the_query'] = $query;

extrachill_community_artist_archive_include_topics( $query );

$GLOBALS['wp_query']     = $previous_wp_query;
$GLOBALS['wp_the_query'] = $previous_wp_the_query;
// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

if ( array( 'topic' ) !== $query->get( 'post_type' ) || array( 'publish', 'closed' ) !== $query->get( 'post_status' ) ) {
	WP_CLI::error( 'Artist archive query does not include published and closed topics.' );
}

WP_CLI::success( 'Artist topic taxonomy smoke check passed.' );
