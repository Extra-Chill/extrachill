/**
 * API Client for Link Page Analytics
 *
 * REST API wrapper for analytics data fetching.
 */

import apiFetch from '@wordpress/api-fetch';

export const getAnalytics = async ( artistId, dateRange = 30 ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/analytics?date_range=${ dateRange }`,
		method: 'GET',
	} );
};
