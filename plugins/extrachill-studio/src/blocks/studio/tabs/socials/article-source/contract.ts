import type {
	ComposerPlatformConfig,
	ComposerInputSchema,
} from '../publish/contract';
import type { PlatformPublishDraft, SelectedImage } from '../publish';

export interface ArticleSource {
	id: number;
	title: string;
	url: string;
	excerpt: string;
	author: string;
	date: string;
	featuredMedia: SelectedImage | null;
}

interface CoreRenderedField {
	rendered?: string;
}

export interface CoreArticlePost {
	id?: number;
	date?: string;
	link?: string;
	title?: CoreRenderedField;
	excerpt?: CoreRenderedField;
	featured_media?: number;
	_embedded?: {
		author?: Array< { name?: string } >;
		'wp:featuredmedia'?: Array< {
			id?: number;
			source_url?: string;
			alt_text?: string;
			title?: CoreRenderedField;
		} >;
	};
}

const ARTICLE_FIELDS = new Set( [
	'caption',
	'content',
	'cover_url',
	'description',
	'image_url',
	'image_urls',
	'link',
	'post_id',
	'source_url',
	'title',
	'url',
] );

export const articlePostsUrl = ( mainSiteUrl: string, search = '' ): string => {
	const url = new URL(
		'wp-json/wp/v2/posts',
		ensureTrailingSlash( mainSiteUrl )
	);
	url.searchParams.set( 'status', 'publish' );
	url.searchParams.set( 'orderby', search ? 'relevance' : 'date' );
	url.searchParams.set( 'order', 'desc' );
	url.searchParams.set( 'per_page', '12' );
	url.searchParams.set( '_embed', 'author,wp:featuredmedia' );
	url.searchParams.set(
		'_fields',
		'id,date,link,title,excerpt,featured_media,_embedded'
	);
	if ( search ) {
		url.searchParams.set( 'search', search );
	}
	return url.toString();
};

export const normalizeArticlePost = (
	post: CoreArticlePost,
	mainBlogId = 1
): ArticleSource | null => {
	if ( ! post.id || ! post.link ) {
		return null;
	}
	const media = post._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ];
	const mediaId = Number( media?.id || post.featured_media || 0 );
	const mediaUrl = media?.source_url || '';

	return {
		id: post.id,
		title: htmlToText( post.title?.rendered || '' ),
		url: post.link,
		excerpt: htmlToText( post.excerpt?.rendered || '' ),
		author: htmlToText( post._embedded?.author?.[ 0 ]?.name || '' ),
		date: post.date || '',
		featuredMedia:
			mediaId > 0 && mediaUrl
				? {
						url: mediaUrl,
						sourceId: `${ mainBlogId }:${ mediaId }`,
						alt: media?.alt_text || undefined,
						title:
							htmlToText( media?.title?.rendered || '' ) ||
							undefined,
				  }
				: null,
	};
};

export const articleCaption = (
	source: ArticleSource,
	maxLength = 0
): string => {
	const value = [ source.title, source.excerpt ]
		.filter( Boolean )
		.join( '\n\n' );
	if ( maxLength <= 0 || value.length <= maxLength ) {
		return value;
	}
	return `${ value.slice( 0, Math.max( 0, maxLength - 1 ) ).trimEnd() }…`;
};

export const applyArticleSource = (
	draft: PlatformPublishDraft,
	source: ArticleSource | null,
	config: ComposerPlatformConfig
): PlatformPublishDraft => {
	const contract = config.composer;
	if ( ! contract ) {
		return draft;
	}
	const properties = contract.inputSchema.properties || {};
	const fields = Object.fromEntries(
		Object.entries( draft.fields ).filter(
			( [ name ] ) => ! ARTICLE_FIELDS.has( name )
		)
	);

	if ( ! source ) {
		return {
			...draft,
			caption: contract.crossPostCompatible ? '' : draft.caption,
			images: contract.crossPostCompatible ? [] : draft.images,
			fields,
			sourcePostId: null,
			sourceUrl: '',
		};
	}

	const caption = articleCaption( source, config.charLimit || 0 );
	if ( ! contract.crossPostCompatible ) {
		const candidates: Record< string, unknown > = {
			caption,
			content: caption,
			cover_url: source.featuredMedia?.url,
			description: source.excerpt || caption,
			image_url: source.featuredMedia?.url,
			image_urls: source.featuredMedia
				? [ source.featuredMedia.url ]
				: undefined,
			link: source.url,
			post_id: source.id,
			source_url: source.url,
			title: source.title,
			url: source.url,
		};
		for ( const name of Object.keys( properties ) ) {
			if ( candidates[ name ] !== undefined ) {
				fields[ name ] = candidates[ name ];
			}
		}
		return {
			...draft,
			fields,
			sourcePostId: source.id,
			sourceUrl: source.url,
		};
	}

	let mediaKind = contract.mediaKinds[ 0 ] || '';
	if ( contract.mediaKinds.includes( draft.mediaKind ) ) {
		mediaKind = draft.mediaKind;
	}
	if ( contract.mediaKinds.includes( 'image' ) ) {
		mediaKind = 'image';
	}
	const requirements = contract.mediaRequirements[ mediaKind ] || {};
	const acceptsImage = [
		...( requirements.required || [] ),
		...( requirements.requiredAnyOf || [] ),
	].includes( 'images' );

	return {
		...draft,
		caption,
		images:
			acceptsImage && source.featuredMedia
				? [ source.featuredMedia ]
				: [],
		mediaKind,
		fields,
		sourcePostId: source.id,
		sourceUrl: source.url,
	};
};

export const declaredSchemaInput = (
	schema: ComposerInputSchema,
	fields: Record< string, unknown >
): Record< string, unknown > =>
	Object.fromEntries(
		Object.keys( schema.properties || {} )
			.filter( ( name ) => fields[ name ] !== undefined )
			.map( ( name ) => [ name, fields[ name ] ] )
	);

export const genericArticleInput = (
	draft: PlatformPublishDraft,
	platform: string,
	mediaKind: string,
	fields: Record< string, unknown >
): Record< string, unknown > => ( {
	...fields,
	platforms: [ platform ],
	caption: draft.caption.trim(),
	media_kind: mediaKind,
	images: draft.images.map( ( { url, alt, title } ) => ( {
		url,
		alt,
		title,
	} ) ),
	post_id: draft.sourcePostId,
	post_site_id: draft.sourcePostId ? 1 : null,
	source_url: draft.sourceUrl,
} );

export const articleReviewMeta = (
	draft: PlatformPublishDraft
): Record< string, number | string > => ( {
	_studio_social_source_post_id: draft.sourcePostId || 0,
	_studio_social_source_url: draft.sourceUrl,
} );

const ensureTrailingSlash = ( value: string ): string =>
	value.endsWith( '/' ) ? value : `${ value }/`;

const htmlToText = ( value: string ): string => {
	if ( ! value ) {
		return '';
	}
	const element = document.createElement( 'div' );
	element.innerHTML = value;
	return ( element.textContent || '' ).replace( /\s+/g, ' ' ).trim();
};
