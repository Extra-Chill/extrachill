<?php
/**
 * Shared analytics date-window validation.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/** Maximum inclusive analytics date range. */
const EXTRACHILL_ANALYTICS_MAX_DATE_RANGE_DAYS = 364;

/**
 * Validate a canonical UTC calendar date.
 *
 * @param mixed $date Candidate date.
 * @return bool Whether the date is valid Y-m-d.
 */
function extrachill_analytics_is_valid_date( $date ) {
	if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
		return false;
	}

	return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
}

/**
 * Resolve optional inclusive dates to exact UTC boundaries.
 *
 * Empty dates indicate that the caller should preserve its legacy relative
 * window. Exact windows are represented as [start_at, end_exclusive), avoiding
 * fractional-second and end-of-day ambiguity in SQL.
 *
 * @param array $input Ability input.
 * @param int   $maximum_days Maximum inclusive range length.
 * @return array|WP_Error|null Exact window, validation error, or null.
 */
function extrachill_analytics_resolve_date_range( $input, $maximum_days = EXTRACHILL_ANALYTICS_MAX_DATE_RANGE_DAYS ) {
	$start_date = isset( $input['start_date'] ) ? trim( (string) $input['start_date'] ) : '';
	$end_date   = isset( $input['end_date'] ) ? trim( (string) $input['end_date'] ) : '';

	if ( '' === $start_date && '' === $end_date ) {
		return null;
	}

	if (
		'' === $start_date
		|| '' === $end_date
		|| ! extrachill_analytics_is_valid_date( $start_date )
		|| ! extrachill_analytics_is_valid_date( $end_date )
		|| $start_date > $end_date
	) {
		return new WP_Error(
			'invalid_analytics_date_range',
			__( 'start_date and end_date must be valid Y-m-d dates, with start_date on or before end_date.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	$start_ts = gmmktime( 0, 0, 0, (int) substr( $start_date, 5, 2 ), (int) substr( $start_date, 8, 2 ), (int) substr( $start_date, 0, 4 ) );
	$end_ts   = gmmktime( 0, 0, 0, (int) substr( $end_date, 5, 2 ), (int) substr( $end_date, 8, 2 ), (int) substr( $end_date, 0, 4 ) );
	$days     = (int) ( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;

	if ( $days > max( 1, (int) $maximum_days ) ) {
		return new WP_Error(
			'analytics_date_range_too_large',
			sprintf(
				/* translators: %d: Maximum inclusive date range in days. */
				__( 'The selected analytics date range cannot exceed %d days.', 'extrachill-analytics' ),
				(int) $maximum_days
			),
			array( 'status' => 400 )
		);
	}

	return array(
		'start_date'    => $start_date,
		'end_date'      => $end_date,
		'start_at'      => $start_date . ' 00:00:00',
		'end_exclusive' => gmdate( 'Y-m-d H:i:s', $end_ts + DAY_IN_SECONDS ),
		'days'          => $days,
	);
}

/**
 * Build the immediately preceding inclusive window of equal length.
 *
 * @param array $window Exact window from extrachill_analytics_resolve_date_range().
 * @return array Previous date window.
 */
function extrachill_analytics_previous_date_range( $window ) {
	$start_ts = (int) strtotime( $window['start_at'] . ' UTC' );
	$days     = (int) $window['days'];

	return array(
		'start_date'    => gmdate( 'Y-m-d', $start_ts - ( $days * DAY_IN_SECONDS ) ),
		'end_date'      => gmdate( 'Y-m-d', $start_ts - DAY_IN_SECONDS ),
		'start_at'      => gmdate( 'Y-m-d H:i:s', $start_ts - ( $days * DAY_IN_SECONDS ) ),
		'end_exclusive' => $window['start_at'],
		'days'          => $days,
	);
}
