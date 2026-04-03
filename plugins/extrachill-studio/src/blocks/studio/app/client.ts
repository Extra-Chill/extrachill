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

/**
 * Normalized comment shape returned by the generic comments API.
 * Platform-agnostic — works for Instagram, Facebook, and future platforms.
 */
export interface SocialComment {
	id: string;
	platform: string;
	author_username: string;
	text: string;
	timestamp: string;
	like_count: number;
	reply_count: number;
	mentions: string[];
	parent_id: string | null;
	raw: Record< string, unknown >;
}

interface GenericCommentsResponse {
	success: boolean;
	data: {
		comments: SocialComment[];
		count: number;
		platform: string;
		partial?: boolean;
		pages?: number;
		error?: string;
	};
	error?: string;
}

interface CommentReplyResponse {
	success: boolean;
	data?: {
		comment_id: string;
		reply_id: string;
		message: string;
	};
	error?: string;
}

export const studioSocialsApi = {
	getInstagramMedia( params: InstagramMediaParams = {} ): Promise< InstagramMediaResponse > {
		const query = new URLSearchParams( {
			action: params.action || 'list',
			...( params.media_id ? { media_id: params.media_id } : {} ),
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine/v1/socials/instagram/media?${ query.toString() }` } );
	},

	getInstagramComments( mediaId: string, params: { limit?: number; after?: string } = {} ): Promise< InstagramCommentsResponse > {
		const query = new URLSearchParams( {
			action: 'comments',
			media_id: mediaId,
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( { path: `/datamachine/v1/socials/instagram/media?${ query.toString() }` } );
	},

	replyToInstagramComment( commentId: string, message: string ): Promise< unknown > {
		return apiFetch( {
			path: '/datamachine/v1/socials/instagram/comments/reply',
			method: 'POST',
			data: {
				comment_id: commentId,
				message,
			},
		} );
	},

	/**
	 * Generic comments API — fetch all comments for a post, normalized.
	 */
	getAllComments( platform: string, mediaId: string ): Promise< GenericCommentsResponse > {
		const query = new URLSearchParams( {
			media_id: mediaId,
			all: 'true',
		} );

		return apiFetch( { path: `/datamachine/v1/socials/comments/${ platform }?${ query.toString() }` } );
	},

	/**
	 * Generic comment reply API.
	 */
	replyToComment( platform: string, commentId: string, message: string ): Promise< CommentReplyResponse > {
		return apiFetch( {
			path: `/datamachine/v1/socials/comments/${ platform }/reply`,
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
