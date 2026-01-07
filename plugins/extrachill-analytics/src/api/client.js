/**
 * Analytics API Client
 *
 * API functions for the analytics dashboard using @wordpress/api-fetch.
 */

import apiFetch from '@wordpress/api-fetch';

const getConfig = () => window.extraChillAnalytics || {};

// Configure apiFetch middleware to include nonce from config
apiFetch.use( ( options, next ) => {
	const config = getConfig();
	if ( config.nonce && ! options.headers?.[ 'X-WP-Nonce' ] ) {
		options.headers = {
			...options.headers,
			'X-WP-Nonce': config.nonce,
		};
	}
	return next( options );
} );

export { getConfig };

const get = ( path ) => apiFetch( { path, method: 'GET' } );

/**
 * Get analytics events with filtering and pagination.
 *
 * @param {Object} params Query parameters
 * @param {string} params.event_type Filter by event type
 * @param {number} params.blog_id Filter by blog ID
 * @param {string} params.date_from Start date (Y-m-d)
 * @param {string} params.date_to End date (Y-m-d)
 * @param {string} params.search Search within event_data
 * @param {number} params.limit Number of results per page
 * @param {number} params.offset Pagination offset
 * @return {Promise} API response with events, count, total
 */
export const getEvents = ( params = {} ) => {
	const query = new URLSearchParams();
	
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== '' && value !== null ) {
			query.append( key, value );
		}
	} );

	const queryString = query.toString();
	return get( `extrachill/v1/analytics/events${ queryString ? `?${ queryString }` : '' }` );
};

/**
 * Get analytics metadata (event types and blogs with events).
 *
 * @return {Promise} API response with event_types and blogs arrays
 */
export const getAnalyticsMeta = () => get( 'extrachill/v1/analytics/meta' );

/**
 * Get summary statistics for an event type.
 *
 * @param {string} eventType Event type to summarize
 * @param {number} days Number of days to look back
 * @param {number} blogId Optional blog ID filter
 * @return {Promise} API response with stats
 */
export const getEventsSummary = ( eventType, days = 30, blogId = 0 ) => {
	const params = new URLSearchParams( { event_type: eventType, days } );
	if ( blogId ) {
		params.append( 'blog_id', blogId );
	}
	return get( `extrachill/v1/analytics/events/summary?${ params }` );
};

export default {
	getConfig,
	getEvents,
	getAnalyticsMeta,
	getEventsSummary,
};
