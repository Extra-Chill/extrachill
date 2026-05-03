/**
 * Analytics API Client
 *
 * Uses wp-native-client (WPNativeClient + WpApiFetchTransport) for
 * ability-backed calls, and falls back to direct apiFetch for REST
 * endpoints that do not yet have ability equivalents.
 *
 * Exports match the original function names so dashboard components
 * need zero changes.
 *
 * Abilities used:
 *   - extrachill/get-analytics-summary
 *
 * Direct REST (no ability registered — see PR for gap documentation):
 *   - GET extrachill/v1/analytics/events   → getEvents()
 *   - GET extrachill/v1/analytics/meta     → getAnalyticsMeta()
 */

import apiFetch from '@wordpress/api-fetch';
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';

/** @typedef {import('../types/analytics').AnalyticsEventsParams} AnalyticsEventsParams */
/** @typedef {import('../types/analytics').AnalyticsEventsResponse} AnalyticsEventsResponse */
/** @typedef {import('../types/analytics').AnalyticsSummaryResponse} AnalyticsSummaryResponse */
/** @typedef {import('../types/analytics').AnalyticsMetaResponse} AnalyticsMetaResponse */

const transport = new WpApiFetchTransport( apiFetch );
const client = new WPNativeClient( transport, { validateAbilityNames: false } );

export const getConfig = () => window.extraChillAnalytics || {};

/**
 * Build a query string from params (filters out falsy values).
 *
 * @param {Record<string, string|number|boolean|undefined>} params
 * @return {string}
 */
function buildQuery( params ) {
	const parts = Object.entries( params )
		.filter( ( [ , v ] ) => v !== undefined && v !== '' && v !== null )
		.map(
			( [ k, v ] ) =>
				`${ encodeURIComponent( k ) }=${ encodeURIComponent(
					String( v )
				) }`
		);
	return parts.length ? `?${ parts.join( '&' ) }` : '';
}

/**
 * Fetch paginated analytics events.
 *
 * No ability equivalent registered — uses direct REST endpoint.
 *
 * @param {AnalyticsEventsParams} params
 * @return {Promise<AnalyticsEventsResponse>}
 */
export const getEvents = ( params = {} ) =>
	apiFetch( {
		path: `extrachill/v1/analytics/events${ buildQuery( params ) }`,
	} );

/**
 * Fetch analytics metadata (event types, blog list).
 *
 * No ability equivalent registered — uses direct REST endpoint.
 *
 * @return {Promise<AnalyticsMetaResponse>}
 */
export const getAnalyticsMeta = () =>
	apiFetch( { path: 'extrachill/v1/analytics/meta' } );

/**
 * Fetch analytics summary via the Abilities API.
 *
 * Uses: extrachill/get-analytics-summary
 *
 * @param {string}  eventType
 * @param {number}  [days=30]
 * @param {number}  [blogId]
 * @return {Promise<AnalyticsSummaryResponse>}
 */
export const getEventsSummary = ( eventType, days = 30, blogId ) =>
	client.execute( 'extrachill/get-analytics-summary', {
		event_type: eventType,
		days,
		...( blogId !== undefined && { blog_id: blogId } ),
	} );

export default {
	getConfig,
	getEvents,
	getAnalyticsMeta,
	getEventsSummary,
};
