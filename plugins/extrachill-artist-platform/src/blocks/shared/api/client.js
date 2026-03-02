/**
 * Shared Artist Platform API Client
 *
 * Delegates all calls to @extrachill/api-client via WpApiFetchTransport.
 * Exports match the original function names so all consuming components
 * need zero changes.
 *
 * shopFetch calls remain as raw fetch() — they target shop.extrachill.com
 * cross-origin and cannot go through the api-client transport.
 */

import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

const transport = new WpApiFetchTransport( apiFetch );
const client = new ExtraChillClient( transport );

const getConfig = () =>
	window.ecArtistPlatformConfig ||
	window.ecLinkPageEditorConfig ||
	window.ecArtistShopManagerConfig ||
	{};

export { getConfig };

// ─── Artist core ────────────────────────────────────────────────────────────

export const getArtist = ( artistId ) => client.artists.getArtist( artistId );
export const createArtist = ( data ) => client.artists.create( data );
export const updateArtist = ( artistId, data ) =>
	client.artists.update( artistId, data );

// ─── Link page ──────────────────────────────────────────────────────────────

export const getLinks = ( artistId ) => client.artists.getLinks( artistId );
export const updateLinks = ( artistId, data ) =>
	client.artists.updateLinks( artistId, data );

// ─── Socials ────────────────────────────────────────────────────────────────

export const getSocials = ( artistId ) =>
	client.artists.getSocials( artistId, true );
export const updateSocials = ( artistId, data ) =>
	client.artists.updateSocials( artistId, data );

// ─── Analytics ──────────────────────────────────────────────────────────────

export const getAnalytics = ( artistId, dateRange = 30 ) =>
	client.artists.getAnalytics( artistId, dateRange );

// ─── Media ──────────────────────────────────────────────────────────────────

export const uploadMedia = ( context, targetId, file ) => {
	const formData = client.media.buildUploadForm( context, targetId, file );
	return client.media.upload( formData );
};

export const deleteMedia = ( context, targetId ) =>
	client.media.delete( context, targetId );

// ─── Roster ─────────────────────────────────────────────────────────────────

export const getRoster = ( artistId ) => client.artists.getRoster( artistId );
export const inviteRosterMember = ( artistId, email ) =>
	client.artists.inviteRosterMember( artistId, email );
export const removeRosterMember = ( artistId, userId ) =>
	client.artists.removeRosterMember( artistId, userId );
export const cancelRosterInvite = ( artistId, inviteId ) =>
	client.artists.cancelRosterInvite( artistId, inviteId );

// ─── User search ────────────────────────────────────────────────────────────

export const searchArtistCapableUsers = ( term, excludeArtistId ) =>
	client.users.search( term, 'artist-capable', excludeArtistId );

// ─── Subscribers ────────────────────────────────────────────────────────────

export const getSubscribers = ( artistId, page = 1, perPage = 20 ) =>
	client.artists.getSubscribers( artistId, page, perPage );

export const exportSubscribers = ( artistId, includeExported = false ) =>
	client.artists.exportSubscribers( artistId, includeExported );

// ─── Shop products ──────────────────────────────────────────────────────────

export const listShopProducts = () => client.shop.listProducts();
export const createShopProduct = ( data ) => client.shop.createProduct( data );
export const updateShopProduct = ( productId, data ) =>
	client.shop.updateProduct( productId, data );
export const deleteShopProduct = ( productId ) =>
	client.shop.deleteProduct( productId );

export const uploadShopProductImages = ( productId, files ) => {
	const formData = new FormData();
	( files || [] ).forEach( ( file ) => {
		formData.append( 'files[]', file );
	} );
	return client.shop.uploadProductImages( productId, formData );
};

export const deleteShopProductImage = ( productId, attachmentId ) =>
	client.shop.deleteProductImage( productId, attachmentId );

// ─── Shop payments (Stripe Connect) — cross-origin, stays as raw fetch ─────

const shopFetch = ( path, options = {} ) => {
	const config = getConfig();
	const shopRestUrl = config.shopRestUrl || config.restUrl;
	const url = shopRestUrl + path;

	return fetch( url, {
		...options,
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...( options.headers || {} ),
		},
	} ).then( ( res ) => {
		if ( ! res.ok ) {
			return res.json().then( ( err ) => {
				throw new Error( err?.message || 'Request failed' );
			} );
		}
		return res.json();
	} );
};

export const getStripeConnectStatus = ( artistId ) =>
	shopFetch( `shop/stripe-connect/status?artist_id=${ artistId }`, {
		method: 'GET',
	} );
export const createStripeConnectOnboardingLink = ( artistId ) =>
	shopFetch( 'shop/stripe-connect/onboarding-link', {
		method: 'POST',
		body: JSON.stringify( { artist_id: artistId } ),
	} );
export const createStripeConnectDashboardLink = ( artistId ) =>
	shopFetch( 'shop/stripe-connect/dashboard-link', {
		method: 'POST',
		body: JSON.stringify( { artist_id: artistId } ),
	} );

// ─── Shop orders — cross-origin ─────────────────────────────────────────────

export const listShopOrders = ( artistId, status = 'all', page = 1 ) =>
	shopFetch(
		`shop/orders?artist_id=${ artistId }&status=${ status }&page=${ page }`,
		{ method: 'GET' }
	);

export const updateShopOrderStatus = (
	orderId,
	artistId,
	status,
	trackingNumber = null
) =>
	shopFetch( `shop/orders/${ orderId }/status`, {
		method: 'PUT',
		body: JSON.stringify( {
			artist_id: artistId,
			status,
			tracking_number: trackingNumber,
		} ),
	} );

export const refundShopOrder = ( orderId, artistId ) =>
	shopFetch( `shop/orders/${ orderId }/refund`, {
		method: 'POST',
		body: JSON.stringify( { artist_id: artistId } ),
	} );

// ─── Shipping ───────────────────────────────────────────────────────────────

export const getArtistShippingAddress = ( artistId ) =>
	client.shop.getShippingAddress( artistId );

export const updateArtistShippingAddress = ( artistId, address ) =>
	client.shop.updateShippingAddress( artistId, address );

export const purchaseShippingLabel = ( orderId, artistId ) =>
	client.shop.purchaseShippingLabel( orderId, artistId );

export const getShippingLabel = ( orderId, artistId ) =>
	client.shop.getShippingLabel( orderId, artistId );

// ─── Permissions ────────────────────────────────────────────────────────────

export const getArtistPermissions = ( artistId ) =>
	client.artists.getPermissions( artistId );

// ─── QR Code ────────────────────────────────────────────────────────────────

export const generateQRCode = ( url, size ) =>
	client.admin.generateQrCode( url, size );

// ─── Default export ─────────────────────────────────────────────────────────

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
	uploadShopProductImages,
	deleteShopProductImage,
	getStripeConnectStatus,
	createStripeConnectOnboardingLink,
	createStripeConnectDashboardLink,
	listShopOrders,
	updateShopOrderStatus,
	refundShopOrder,
	getArtistShippingAddress,
	updateArtistShippingAddress,
	purchaseShippingLabel,
	getShippingLabel,
	getArtistPermissions,
	generateQRCode,
};
