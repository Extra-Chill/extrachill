<?php
/**
 * Shared event indexing retention policy.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the local-wall-time cutoff used by the event dates table.
 *
 * @return string MySQL datetime.
 */
function ec_seo_event_indexing_cutoff() {
	/**
	 * Filter how many days after an event ends it remains indexable.
	 *
	 * @param int $days Retention window, default 180 days.
	 */
	$days = max( 0, (int) apply_filters( 'extrachill_seo_event_retention_days', 180 ) );

	return current_datetime()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
}

/**
 * Determine whether an event ended strictly before the retention cutoff.
 *
 * Mirrors UpcomingFilter::past_where(), the owning plugin's SQL primitive.
 * Missing dates fail open rather than accidentally removing undated events.
 *
 * @param int $post_id Event ID.
 * @return bool
 */
function ec_seo_is_expired_event( $post_id ) {
	if ( ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
		return false;
	}

	$dates = \DataMachineEvents\Core\EventDatesTable::get( (int) $post_id );
	if ( ! $dates || empty( $dates->start_datetime ) ) {
		return false;
	}

	$cutoff = ec_seo_event_indexing_cutoff();

	return $dates->start_datetime < $cutoff
		&& ( empty( $dates->end_datetime ) || $dates->end_datetime < $cutoff );
}

/**
 * Mark only core event sitemap queries, including the pagination count query.
 *
 * @param array  $args Query arguments.
 * @param string $post_type Sitemap subtype.
 * @return array
 */
function ec_seo_event_sitemap_query_args( $args, $post_type ) {
	if ( 'data_machine_events' === $post_type ) {
		$args['ec_seo_event_retention_cutoff'] = ec_seo_event_indexing_cutoff();
		// Core currently leaves filters enabled; make this requirement explicit.
		$args['suppress_filters'] = false;
	}

	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', __NAMESPACE__ . '\\ec_seo_event_sitemap_query_args', 10, 2 );

/**
 * Exclude expired events in SQL without materializing IDs or filtering pages.
 *
 * The correlated lookup uses the event dates primary key (post_id). Both URL
 * and count queries get the same predicate, preserving core pagination. Rows
 * without dates remain included. past_where (owned by
 * Data Machine Events) defines when an event has ended.
 *
 * @param string    $where Existing WHERE SQL.
 * @param \WP_Query $query Core sitemap query.
 * @return string
 */
function ec_seo_event_sitemap_where( $where, $query ) {
	$cutoff = $query->get( 'ec_seo_event_retention_cutoff' );
	if ( ! $cutoff || 'data_machine_events' !== $query->get( 'post_type' )
		|| ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' )
		|| ! class_exists( '\\DataMachineEvents\\Blocks\\Calendar\\Query\\UpcomingFilter' ) ) {
		return $where;
	}

	global $wpdb;
	$table = \DataMachineEvents\Core\EventDatesTable::table_name();
	$past  = \DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter::past_where( $cutoff );

	// Trusted table identifiers and a prepared predicate supplied by the owner API.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from WordPress/owner API; past_where prepares the cutoff.
	return $where . " AND NOT EXISTS (SELECT 1 FROM {$table} ed WHERE ed.post_id = {$wpdb->posts}.ID AND {$past})";
}
add_filter( 'posts_where', __NAMESPACE__ . '\\ec_seo_event_sitemap_where', 10, 2 );
