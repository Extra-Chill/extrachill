/**
 * Shared Artist Platform API Client
 *
 * Delegates all calls to @extrachill/api-client via WpApiFetchTransport.
 * Exports match the original function names so all consuming components
 * need zero changes.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

const transport = new WpApiFetchTransport( apiFetch );
const client = new ExtraChillClient( transport );

const getConfig = () =>
	window.ecArtistPlatformConfig ||
	window.ecLinkPageEditorConfig ||
	{};

export { getConfig };

// ─── Artist core ────────────────────────────────────────────────────────────

export const getArtist = ( artistId ) => client.artists.getArtist( artistId );
export const createArtist = ( data ) => client.artists.create( data );
export const updateArtist = ( artistId, data ) =>
	client.artists.update( artistId, data );

const abilityPath = ( name ) =>
	`/wp-abilities/v1/abilities/${ name
		.split( '/' )
		.map( encodeURIComponent )
		.join( '/' ) }/run`;

export const getLocalSupportAvailability = ( artistId ) => {
	const query = new URLSearchParams();
	query.set( 'input[id]', String( artistId ) );
	return apiFetch( {
		path: `${ abilityPath(
			'extrachill/artist-get-local-support-availability'
		) }?${ query.toString() }`,
		method: 'GET',
	} );
};

export const updateLocalSupportAvailability = (
	artistId,
	available,
	sceneSlug
) =>
	apiFetch( {
		path: abilityPath(
			'extrachill/artist-update-local-support-availability'
		),
		method: 'POST',
		data: {
			input: {
				id: artistId,
				available,
				...( sceneSlug ? { scene_slug: sceneSlug } : {} ),
			},
		},
	} );

export const searchEventLocations = ( search ) => {
	const query = new URLSearchParams();
	query.set( 'input[mode]', 'search' );
	query.set( 'input[search]', search );
	query.set( 'input[limit]', '10' );
	return apiFetch( {
		path: `${ abilityPath(
			'extrachill/user-event-locations'
		) }?${ query.toString() }`,
		method: 'GET',
	} );
};

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

export const getAnalytics = ( artistId, range = 30 ) =>
	client.artists.getAnalytics( artistId, range );

// ─── Media ──────────────────────────────────────────────────────────────────

export const uploadMedia = ( context, targetId, file ) => {
	const formData = client.media.buildUploadForm( context, targetId, file );
	return client.media.upload( formData );
};

export const deleteMedia = ( context, targetId ) =>
	client.media.delete( context, targetId );

// ─── Roster ─────────────────────────────────────────────────────────────────

export const getRoster = ( artistId ) => client.artists.getRoster( artistId );
/**
 * Invite a roster member by typed email or by a user picked from search.
 *
 * @param {number}                   artistId Artist profile ID.
 * @param {string|{user_id: number}} invitee  Email address, or `{ user_id }`.
 */
export const inviteRosterMember = ( artistId, invitee ) =>
	client.artists.inviteRosterMember( artistId, invitee );
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
	getLocalSupportAvailability,
	updateLocalSupportAvailability,
	searchEventLocations,
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
	getArtistPermissions,
	generateQRCode,
};
