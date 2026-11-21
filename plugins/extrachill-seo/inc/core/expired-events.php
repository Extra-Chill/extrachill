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
