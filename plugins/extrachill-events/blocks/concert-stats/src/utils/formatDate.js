/**
 * Date formatting helpers for the concert-stats block.
 *
 * Single source of truth for show-date display, replacing the duplicated
 * MONTHS array + formatDate that previously lived in ShowCard and
 * EventSearchResult.
 *
 * @package
 */

const MONTHS = [
	'Jan',
	'Feb',
	'Mar',
	'Apr',
	'May',
	'Jun',
	'Jul',
	'Aug',
	'Sep',
	'Oct',
	'Nov',
	'Dec',
];

/**
 * Parse a Y-m-d date string into a local Date, or null when invalid.
 *
 * @param {string} dateString A Y-m-d date string.
 * @return {Date|null} Parsed date, or null.
 */
function parseDate( dateString ) {
	if ( ! dateString ) {
		return null;
	}
	const d = new Date( dateString + 'T00:00:00' );
	return Number.isNaN( d.getTime() ) ? null : d;
}

/**
 * Format a date as a short month + day, e.g. "Jan 5".
 *
 * @param {string} dateString A Y-m-d date string.
 * @return {string} Formatted date, or '' when invalid.
 */
export function formatShortDate( dateString ) {
	const d = parseDate( dateString );
	if ( ! d ) {
		return '';
	}
	return `${ MONTHS[ d.getMonth() ] } ${ d.getDate() }`;
}

/**
 * Format a date as month + day + year, e.g. "Jan 5, 2018".
 *
 * @param {string} dateString A Y-m-d date string.
 * @return {string} Formatted date, or '' when invalid.
 */
export function formatLongDate( dateString ) {
	const d = parseDate( dateString );
	if ( ! d ) {
		return '';
	}
	return `${ MONTHS[ d.getMonth() ] } ${ d.getDate() }, ${ d.getFullYear() }`;
}
