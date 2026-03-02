/**
 * Analytics API Client
 *
 * Delegates all calls to @extrachill/api-client via WpApiFetchTransport.
 * Exports match the original function names so dashboard components need zero changes.
 */

import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

const transport = new WpApiFetchTransport( apiFetch );
const client = new ExtraChillClient( transport );

export const getConfig = () => window.extraChillAnalytics || {};

export const getEvents = ( params ) => client.analytics.getEvents( params );
export const getAnalyticsMeta = () => client.analytics.getMeta();
export const getEventsSummary = ( eventType, days, blogId ) =>
	client.analytics.getSummary( eventType, days, blogId );

export default {
	getConfig,
	getEvents,
	getAnalyticsMeta,
	getEventsSummary,
};
