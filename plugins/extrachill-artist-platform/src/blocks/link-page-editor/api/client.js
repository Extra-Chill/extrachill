/**
 * Link Page Editor API Client
 *
 * Wrapper around @wordpress/api-fetch for all REST API operations.
 * Consumes ecLinkPageEditorConfig from render.php localization.
 */

import apiFetch from '@wordpress/api-fetch';

const getConfig = () => window.ecLinkPageEditorConfig || {};

/**
 * GET /artists/{id} - Core artist data
 */
export const getArtist = async ( artistId ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }`,
		method: 'GET',
	} );
};

/**
 * PUT /artists/{id} - Update core artist data
 */
export const updateArtist = async ( artistId, data ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }`,
		method: 'PUT',
		data,
	} );
};

/**
 * GET /artists/{id}/links - Link page data (links, settings, cssVars)
 */
export const getLinks = async ( artistId ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/links`,
		method: 'GET',
	} );
};

/**
 * PUT /artists/{id}/links - Update link page data
 */
export const updateLinks = async ( artistId, data ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/links`,
		method: 'PUT',
		data,
	} );
};

/**
 * GET /artists/{id}/socials - Social links
 */
export const getSocials = async ( artistId ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/socials`,
		method: 'GET',
	} );
};

/**
 * PUT /artists/{id}/socials - Update social links
 */
export const updateSocials = async ( artistId, data ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/socials`,
		method: 'PUT',
		data,
	} );
};

/**
 * GET /artists/{id}/analytics - Analytics data
 */
export const getAnalytics = async ( artistId, dateRange = 30 ) => {
	return apiFetch( {
		path: `extrachill/v1/artists/${ artistId }/analytics?date_range=${ dateRange }`,
		method: 'GET',
	} );
};

/**
 * POST /media - Upload image
 */
export const uploadMedia = async ( context, targetId, file ) => {
	const formData = new FormData();
	formData.append( 'context', context );
	formData.append( 'target_id', targetId );
	formData.append( 'file', file );

	return apiFetch( {
		path: 'extrachill/v1/media',
		method: 'POST',
		body: formData,
	} );
};

/**
 * DELETE /media - Remove image
 */
export const deleteMedia = async ( context, targetId ) => {
	return apiFetch( {
		path: 'extrachill/v1/media',
		method: 'DELETE',
		data: {
			context,
			target_id: targetId,
		},
	} );
};

export default {
	getArtist,
	updateArtist,
	getLinks,
	updateLinks,
	getSocials,
	updateSocials,
	getAnalytics,
	uploadMedia,
	deleteMedia,
	getConfig,
};
