/**
 * Shared Artist Platform API Client
 */

import apiFetch from '@wordpress/api-fetch';

const getConfig = () =>
	window.ecArtistPlatformConfig ||
	window.ecLinkPageEditorConfig ||
	window.ecArtistShopManagerConfig ||
	{};

// Configure apiFetch middleware to include nonce from config
apiFetch.use( ( options, next ) => {
	const config = getConfig();
	if ( config.nonce && ! options.headers?.['X-WP-Nonce'] ) {
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
const del = ( path, data ) => apiFetch( { path, method: 'DELETE', data } );

// Artist core
export const getArtist = ( artistId ) => get( `extrachill/v1/artists/${ artistId }` );
export const createArtist = ( data ) => post( 'extrachill/v1/artists', data );
export const updateArtist = ( artistId, data ) => put( `extrachill/v1/artists/${ artistId }`, data );

// Link page
export const getLinks = ( artistId ) => get( `extrachill/v1/artists/${ artistId }/links` );
export const updateLinks = ( artistId, data ) => put( `extrachill/v1/artists/${ artistId }/links`, data );

// Socials
export const getSocials = ( artistId ) =>
	get( `extrachill/v1/artists/${ artistId }/socials?include_icon_class=1` );
export const updateSocials = ( artistId, data ) =>
	put( `extrachill/v1/artists/${ artistId }/socials`, data );

// Analytics (artist scoped via API plugin)
export const getAnalytics = ( artistId, dateRange = 30 ) =>
	get( `extrachill/v1/artists/${ artistId }/analytics?date_range=${ dateRange }` );

// Media
export const uploadMedia = ( context, targetId, file ) => {
	const formData = new FormData();
	formData.append( 'context', context );
	if ( targetId ) {
		formData.append( 'target_id', targetId );
	}
	formData.append( 'file', file );

	return apiFetch( {
		path: 'extrachill/v1/media',
		method: 'POST',
		body: formData,
	} );
};

export const deleteMedia = ( context, targetId ) =>
	del( 'extrachill/v1/media', {
		context,
		target_id: targetId,
	} );

// Roster
export const getRoster = ( artistId ) => get( `extrachill/v1/artists/${ artistId }/roster` );
export const inviteRosterMember = ( artistId, email ) =>
	post( `extrachill/v1/artists/${ artistId }/roster`, { email } );
export const removeRosterMember = ( artistId, userId ) =>
	del( `extrachill/v1/artists/${ artistId }/roster/${ userId }` );
export const cancelRosterInvite = ( artistId, inviteId ) =>
	del( `extrachill/v1/artists/${ artistId }/roster/invites/${ inviteId }` );

// User search
export const searchArtistCapableUsers = ( term, excludeArtistId ) => {
	const params = new URLSearchParams( {
		term,
		context: 'artist-capable',
	} );
	if ( excludeArtistId ) {
		params.append( 'exclude_artist_id', excludeArtistId );
	}
	return get( `extrachill/v1/users/search?${ params.toString() }` );
};

// Subscribers
export const getSubscribers = ( artistId, page = 1, perPage = 20 ) =>
	get(
		`extrachill/v1/artist/subscribers?artist_id=${ artistId }&page=${ page }&per_page=${ perPage }`
	);

export const exportSubscribers = ( artistId, includeExported = false ) => {
	const flag = includeExported ? '&include_exported=1' : '';
	return get( `extrachill/v1/artist/subscribers/export?artist_id=${ artistId }${ flag }` );
};

// Shop products
export const listShopProducts = () => get( 'extrachill/v1/shop/products' );
export const createShopProduct = ( data ) => post( 'extrachill/v1/shop/products', data );
export const updateShopProduct = ( productId, data ) =>
	put( `extrachill/v1/shop/products/${ productId }`, data );
export const deleteShopProduct = ( productId ) =>
	del( `extrachill/v1/shop/products/${ productId }` );

// Shop payments (Stripe Connect)
export const getStripeConnectStatus = ( artistId ) =>
	get( `extrachill/v1/shop/stripe-connect/status?artist_id=${ artistId }` );
export const createStripeConnectOnboardingLink = ( artistId ) =>
	post( 'extrachill/v1/shop/stripe-connect/onboarding-link', { artist_id: artistId } );
export const createStripeConnectDashboardLink = ( artistId ) =>
	post( 'extrachill/v1/shop/stripe-connect/dashboard-link', { artist_id: artistId } );

// Permissions
export const getArtistPermissions = ( artistId ) =>
	get( `extrachill/v1/artist/permissions?artist_id=${ artistId }` );

export default {
	getConfig,
	getArtist,
	createArtist,
	updateArtist,
	getLinks,
	updateLinks,
	getSocials,
	updateSocials,
	getAnalytics,
	uploadMedia,
	deleteMedia,
	getRoster,
	inviteRosterMember,
	removeRosterMember,
	cancelRosterInvite,
	searchArtistCapableUsers,
	getSubscribers,
	exportSubscribers,
	listShopProducts,
	createShopProduct,
	updateShopProduct,
	deleteShopProduct,
	getStripeConnectStatus,
	createStripeConnectOnboardingLink,
	createStripeConnectDashboardLink,
	getArtistPermissions,
};
