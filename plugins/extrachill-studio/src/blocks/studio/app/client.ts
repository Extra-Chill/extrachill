import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import type { SocialMediaUploadResponse } from '@extrachill/api-client';

export const studioClient = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

interface InstagramMediaParams {
	action?: string;
	media_id?: string;
	limit?: number;
	after?: string;
}

export interface InstagramMediaItem {
	id: string;
	caption?: string;
	comments_count?: number;
	media_type?: string;
	timestamp?: string;
}

interface InstagramMediaResponse {
	data: {
		media: InstagramMediaItem[];
	};
}

export interface InstagramComment {
	id: string;
	username?: string;
	text?: string;
	timestamp?: string;
}

interface InstagramCommentsResponse {
	data: {
		comments: InstagramComment[];
	};
}

export const studioSocialsApi = {
	getInstagramMedia( params: InstagramMediaParams = {} ): Promise< InstagramMediaResponse > {
		const query = new URLSearchParams( {
			action: params.action || 'list',
			...( params.media_id ? { media_id: params.media_id } : {} ),
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine-socials/v1/instagram/media?${ query.toString() }` } );
	},

	getInstagramComments( mediaId: string, params: { limit?: number; after?: string } = {} ): Promise< InstagramCommentsResponse > {
		const query = new URLSearchParams( {
			action: 'comments',
			media_id: mediaId,
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine-socials/v1/instagram/media?${ query.toString() }` } );
	},

	replyToInstagramComment( commentId: string, message: string ): Promise< unknown > {
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

export const uploadStudioFile = async ( file: File ): Promise< SocialMediaUploadResponse > => {
	const formData = new FormData();
	formData.append( 'file', file );

	return studioClient.socials.uploadCroppedMedia( formData );
};
