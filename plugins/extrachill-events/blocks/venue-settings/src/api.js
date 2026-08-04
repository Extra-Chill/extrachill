/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export const runAbility = ( name, input = {} ) => {
	return apiFetch( {
		path: `/wp-abilities/v1/abilities/${ name }/run`,
		method: 'POST',
		data: { input },
	} );
};

export const errorDetails = ( error ) => ( {
	code: error?.code || 'venue_settings_request_failed',
	message: error?.message || 'The request could not be completed.',
	status: error?.data?.status || 0,
	conflict: error?.data?.conflict || null,
} );
