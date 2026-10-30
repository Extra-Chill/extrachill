/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const METHODS = {
	'extrachill/get-venue-profile': 'GET',
	'extrachill/get-venue-booking-config': 'GET',
	'extrachill/list-venue-memberships': 'GET',
	'extrachill/list-venue-invitations': 'GET',
	'extrachill/list-venue-claims': 'GET',
	'extrachill/get-venue-booking-activity': 'GET',
	'extrachill/list-booking-holds': 'GET',
	'extrachill/list-booking-communications': 'GET',
	'extrachill/events-calendar': 'GET',
	'extrachill/get-promoter-link-page': 'GET',
	'extrachill/get-venue-link-page': 'GET',
	'extrachill/review-venue-claim': 'DELETE',
	'extrachill/cancel-venue-invitation': 'DELETE',
	'extrachill/cancel-venue-claim': 'DELETE',
};

export const runAbility = ( name, input = {} ) => {
	const method = METHODS[ name ] || 'POST';
	const path = `/wp-abilities/v1/abilities/${ name }/run`;

	if ( method !== 'POST' ) {
		return apiFetch( {
			path: addQueryArgs( path, { input } ),
			method,
		} );
	}

	return apiFetch( {
		path,
		method,
		data: { input },
	} );
};

export const errorDetails = ( error ) => ( {
	code: error?.code || 'venue_settings_request_failed',
	message: error?.message || 'The request could not be completed.',
	status: error?.data?.status || 0,
	conflict: error?.data?.conflict || null,
} );
