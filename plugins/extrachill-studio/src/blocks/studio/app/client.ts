/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import type { SocialMediaUploadResponse } from '@extrachill/api-client';

/**
 * Internal dependencies
 */
import type {
	AnalyticsSummaryResponse,
	ConversionMapResponse,
	GaDateStatsResponse,
	RetentionResponse,
	SurfaceGrowthResponse,
} from '../types/analytics';

export const studioClient = new ExtraChillClient(
	new WpApiFetchTransport( apiFetch )
);

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
	getInstagramMedia(
		params: InstagramMediaParams = {}
	): Promise< InstagramMediaResponse > {
		const query = new URLSearchParams( {
			action: params.action || 'list',
			...( params.media_id ? { media_id: params.media_id } : {} ),
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( {
			path: `/datamachine/v1/socials/instagram/media?${ query.toString() }`,
		} );
	},

	getInstagramComments(
		mediaId: string,
		params: { limit?: number; after?: string } = {}
	): Promise< InstagramCommentsResponse > {
		const query = new URLSearchParams( {
			action: 'comments',
			media_id: mediaId,
			...( params.limit ? { limit: String( params.limit ) } : {} ),
			...( params.after ? { after: params.after } : {} ),
		} );

		return apiFetch( {
			path: `/datamachine/v1/socials/instagram/media?${ query.toString() }`,
		} );
	},

	replyToInstagramComment(
		commentId: string,
		message: string
	): Promise< unknown > {
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
	 * @param platform
	 * @param mediaId
	 */
	getAllComments(
		platform: string,
		mediaId: string
	): Promise< GenericCommentsResponse > {
		const query = new URLSearchParams( {
			media_id: mediaId,
			all: 'true',
		} );

		return apiFetch( {
			path: `/datamachine/v1/socials/comments/${ platform }?${ query.toString() }`,
		} );
	},

	/**
	 * Generic comment reply API.
	 * @param platform
	 * @param commentId
	 * @param message
	 */
	replyToComment(
		platform: string,
		commentId: string,
		message: string
	): Promise< CommentReplyResponse > {
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

/**
 * Network-tab analytics reads.
 *
 * Mirrors the raw-`apiFetch` style of `studioSocialsApi` above — NO new fetch
 * layer (per extrachill-studio#84, three JS API clients already coexist; we do
 * not add a fourth). `apiFetch` injects the REST nonce globally, so these
 * typed methods just build the query and hit the route.
 *
 * The four first-party routes are extrachill-api wrappers around
 * extrachill-analytics abilities (relaxed to the team-readable tier in
 * extrachill-analytics#95). The GA4 sessions route is Data Machine Business's
 * generic analytics route; it is NOT yet team-accessible (depends on a DM
 * read-only analytics cap + an Extra Chill `user_has_cap` grant still in
 * flight — see extrachill-studio#104's permission-model comment), so its caller
 * must tolerate a 403/401/404 and degrade gracefully rather than error.
 */
export const studioAnalyticsApi = {
	/**
	 * GET /extrachill/v1/analytics/summary — event counts by type over a window.
	 * @param params
	 * @param params.days
	 * @param params.event_type
	 * @param params.blog_id
	 */
	getSummary(
		params: { days?: number; event_type?: string; blog_id?: number } = {}
	): Promise< AnalyticsSummaryResponse > {
		const query = new URLSearchParams();
		if ( params.days !== undefined ) {
			query.set( 'days', String( params.days ) );
		}
		if ( params.event_type ) {
			query.set( 'event_type', params.event_type );
		}
		if ( params.blog_id ) {
			query.set( 'blog_id', String( params.blog_id ) );
		}
		const qs = query.toString();
		return apiFetch( {
			path: `/extrachill/v1/analytics/summary${ qs ? `?${ qs }` : '' }`,
		} );
	},

	/**
	 * GET /extrachill/v1/analytics/surface-growth — ranked cross-surface growth.
	 * @param params
	 * @param params.weeks
	 */
	getSurfaceGrowth(
		params: { weeks?: number } = {}
	): Promise< SurfaceGrowthResponse > {
		const query = new URLSearchParams();
		if ( params.weeks !== undefined ) {
			query.set( 'weeks', String( params.weeks ) );
		}
		const qs = query.toString();
		return apiFetch( {
			path: `/extrachill/v1/analytics/surface-growth${
				qs ? `?${ qs }` : ''
			}`,
		} );
	},

	/**
	 * GET /extrachill/v1/analytics/retention — visitor return-rate + cohorts.
	 * @param params
	 * @param params.days
	 * @param params.blog_id
	 * @param params.cohort_weeks
	 */
	getRetention(
		params: { days?: number; blog_id?: number; cohort_weeks?: number } = {}
	): Promise< RetentionResponse > {
		const query = new URLSearchParams();
		if ( params.days !== undefined ) {
			query.set( 'days', String( params.days ) );
		}
		if ( params.blog_id ) {
			query.set( 'blog_id', String( params.blog_id ) );
		}
		if ( params.cohort_weeks !== undefined ) {
			query.set( 'cohort_weeks', String( params.cohort_weeks ) );
		}
		const qs = query.toString();
		return apiFetch( {
			path: `/extrachill/v1/analytics/retention${ qs ? `?${ qs }` : '' }`,
		} );
	},

	/**
	 * GET /extrachill/v1/analytics/conversion-map — article -> platform reach.
	 * @param params
	 * @param params.days
	 * @param params.top_articles
	 * @param params.min_entry_sessions
	 * @param params.author_id
	 */
	getConversionMap(
		params: {
			days?: number;
			top_articles?: number;
			min_entry_sessions?: number;
			author_id?: number;
		} = {}
	): Promise< ConversionMapResponse > {
		const query = new URLSearchParams();
		if ( params.days !== undefined ) {
			query.set( 'days', String( params.days ) );
		}
		if ( params.top_articles !== undefined ) {
			query.set( 'top_articles', String( params.top_articles ) );
		}
		if ( params.min_entry_sessions !== undefined ) {
			query.set(
				'min_entry_sessions',
				String( params.min_entry_sessions )
			);
		}
		if ( params.author_id ) {
			query.set( 'author_id', String( params.author_id ) );
		}
		const qs = query.toString();
		return apiFetch( {
			path: `/extrachill/v1/analytics/conversion-map${
				qs ? `?${ qs }` : ''
			}`,
		} );
	},

	/**
	 * POST /datamachine/v1/analytics/ga (action: date_stats) — daily sessions.
	 *
	 * Not yet team-accessible. Callers MUST catch and inspect the error: a 401/
	 * 403 (and a 404 if the route is absent on this install) is the expected
	 * "admins only / coming soon" path, not a failure to surface to the user.
	 * @param params
	 * @param params.hostname
	 * @param params.start_date
	 * @param params.end_date
	 * @param params.limit
	 */
	getGaDateStats( params: {
		hostname?: string;
		start_date: string;
		end_date: string;
		limit?: number;
	} ): Promise< GaDateStatsResponse > {
		return apiFetch( {
			path: '/datamachine/v1/analytics/ga',
			method: 'POST',
			data: {
				action: 'date_stats',
				...( params.hostname ? { hostname: params.hostname } : {} ),
				start_date: params.start_date,
				end_date: params.end_date,
				...( params.limit !== undefined
					? { limit: params.limit }
					: {} ),
			},
		} );
	},
};

export const uploadStudioFile = async (
	file: File
): Promise< SocialMediaUploadResponse > => {
	const formData = new FormData();
	formData.append( 'file', file );

	return studioClient.socials.uploadCroppedMedia( formData );
};
