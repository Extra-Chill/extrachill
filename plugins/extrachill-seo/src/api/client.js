/**
 * SEO API Client
 */
import apiFetch from '@wordpress/api-fetch';

const getConfig = () => window.ecSeoAdmin || {};

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
const post = ( path, data ) => apiFetch( { path, method: 'POST', data } );
const put = ( path, data ) => apiFetch( { path, method: 'PUT', data } );

/**
 * Start an audit in the specified mode.
 *
 * @param {string} mode - 'full' or 'batch'
 */
export const runAudit = ( mode ) => post( 'extrachill/v1/seo/audit', { mode } );

/**
 * Get current audit status.
 */
export const getAuditStatus = () => get( 'extrachill/v1/seo/audit/status' );

/**
 * Continue a batch audit.
 */
export const continueAudit = () => post( 'extrachill/v1/seo/audit/continue' );

/**
 * Get details for a specific category.
 *
 * @param {string} category - Audit category
 * @param {number} page - Page number
 * @param {number} perPage - Items per page
 */
export const getAuditDetails = ( category, page = 1, perPage = 50 ) => {
	const params = new URLSearchParams( { category, page, per_page: perPage } );
	return get( `extrachill/v1/seo/audit/details?${ params }` );
};

/**
 * Export all details for a category.
 *
 * @param {string} category - Audit category
 */
export const exportAuditDetails = ( category ) => {
	const params = new URLSearchParams( { category, export: 'true' } );
	return get( `extrachill/v1/seo/audit/details?${ params }` );
};

/**
 * Save SEO config settings.
 *
 * @param {Object} data - Config data
 * @param {number} data.default_og_image_id - Default OG image attachment ID
 * @param {string} data.indexnow_key - IndexNow API key
 */
export const saveConfig = ( data ) => put( 'extrachill/v1/seo/config', data );

export default {
	getConfig,
	runAudit,
	getAuditStatus,
	continueAudit,
	getAuditDetails,
	exportAuditDetails,
	saveConfig,
};
