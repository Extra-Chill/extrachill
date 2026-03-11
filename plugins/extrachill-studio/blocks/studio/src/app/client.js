import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

export const studioClient = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

export const studioSocialsApi = {
	getInstagramMedia( params = {} ) {
		const query = new URLSearchParams( {
			action: params.action || 'list',
			...( params.media_id ? { media_id: params.media_id } : {} ),
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine-socials/v1/instagram/media?${ query.toString() }` } );
	},

	getInstagramComments( mediaId, params = {} ) {
		const query = new URLSearchParams( {
			action: 'comments',
			media_id: mediaId,
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine-socials/v1/instagram/media?${ query.toString() }` } );
	},

	replyToInstagramComment( commentId, message ) {
		return apiFetch( {
			path: '/datamachine-socials/v1/instagram/comments/reply',
			method: 'POST',
			data: {
				comment_id: commentId,
				message,
			},
		} );
	},
};

export const uploadStudioFile = async ( file ) => {
	const formData = new FormData();
	formData.append( 'file', file );

	return studioClient.socials.uploadCroppedMedia( formData );
};
