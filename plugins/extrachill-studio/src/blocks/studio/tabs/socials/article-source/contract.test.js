import {
	applyArticleSource,
	articleReviewMeta,
	articlePostsUrl,
	declaredSchemaInput,
	genericArticleInput,
	normalizeArticlePost,
} from './contract';

const source = ( id = 42 ) => ( {
	id,
	title: `Article ${ id }`,
	url: `https://extrachill.com/article-${ id }/`,
	excerpt: `Excerpt ${ id }`,
	author: 'Extra Chill Staff',
	date: '2026-08-22T18:00:00',
	featuredMedia: {
		url: `https://extrachill.com/uploads/article-${ id }.jpg`,
		sourceId: `1:${ 100 + id }`,
		alt: 'Festival crowd',
	},
} );

const draft = () => ( {
	caption: '',
	images: [],
	mediaKind: '',
	fields: { privacy_level: 'PUBLIC_TO_EVERYONE' },
	sourcePostId: null,
	sourceUrl: '',
} );

const generic = () => ( {
	slug: 'generic',
	charLimit: 280,
	composer: {
		crossPostCompatible: true,
		mediaKinds: [ 'image' ],
		target: { transport: 'rest', name: 'datamachine/v1/socials/post' },
		inputSchema: {
			type: 'object',
			properties: {
				platforms: { type: 'array' },
				caption: { type: 'string' },
				media_kind: { type: 'string' },
				images: { type: 'array' },
			},
		},
		mediaRequirements: { image: { required: [ 'caption', 'images' ] } },
	},
} );

const specialized = () => ( {
	slug: 'specialized',
	charLimit: 5000,
	composer: {
		crossPostCompatible: false,
		mediaKinds: [ 'video' ],
		target: { transport: 'ability', name: 'vendor/video-publish' },
		inputSchema: {
			type: 'object',
			properties: {
				title: { type: 'string' },
				description: { type: 'string' },
				privacy_level: { type: 'string' },
			},
		},
		mediaRequirements: {},
	},
} );

describe( 'article social source contract', () => {
	it( 'requests recent and searched published posts from WordPress core', () => {
		const recent = new URL( articlePostsUrl( 'https://extrachill.com/' ) );
		expect( recent.pathname ).toBe( '/wp-json/wp/v2/posts' );
		expect( recent.searchParams.get( 'status' ) ).toBe( 'publish' );
		expect( recent.searchParams.get( 'orderby' ) ).toBe( 'date' );
		expect( recent.searchParams.has( 'search' ) ).toBe( false );

		const searched = new URL(
			articlePostsUrl( 'https://extrachill.com/', 'local music' )
		);
		expect( searched.searchParams.get( 'search' ) ).toBe( 'local music' );
		expect( searched.searchParams.get( 'orderby' ) ).toBe( 'relevance' );
		expect( searched.searchParams.get( '_embed' ) ).toContain( 'author' );
		expect( searched.searchParams.get( '_fields' ) ).toContain( '_links' );
	} );

	it( 'normalizes canonical post, author, excerpt, and featured media fields', () => {
		expect(
			normalizeArticlePost( {
				id: 42,
				date: '2026-08-22T18:00:00',
				link: 'https://extrachill.com/article-42/',
				title: { rendered: 'Article &amp; Festival' },
				excerpt: { rendered: '<p>A packed local show.</p>' },
				featured_media: 142,
				_embedded: {
					author: [ { name: 'Extra Chill Staff' } ],
					'wp:featuredmedia': [
						{
							id: 142,
							source_url:
								'https://extrachill.com/uploads/article.jpg',
							alt_text: 'Festival crowd',
						},
					],
				},
			} )
		).toMatchObject( {
			id: 42,
			title: 'Article & Festival',
			excerpt: 'A packed local show.',
			author: 'Extra Chill Staff',
			featuredMedia: {
				sourceId: '1:142',
				url: 'https://extrachill.com/uploads/article.jpg',
			},
		} );
	} );

	it( 'prefills generic drafts while retaining canonical post identity', () => {
		const selected = applyArticleSource( draft(), source(), generic() );
		expect( selected ).toMatchObject( {
			caption: 'Article 42\n\nExcerpt 42',
			mediaKind: 'image',
			sourcePostId: 42,
			sourceUrl: 'https://extrachill.com/article-42/',
		} );
		expect( selected.images[ 0 ].sourceId ).toBe( '1:142' );
		expect(
			genericArticleInput( selected, 'generic', 'image', selected.fields )
		).toMatchObject( {
			post_id: 42,
			post_site_id: 1,
			source_url: 'https://extrachill.com/article-42/',
			platforms: [ 'generic' ],
		} );
	} );

	it( 'keeps caption and media overrides while retaining review source identity', () => {
		const selected = applyArticleSource( draft(), source(), generic() );
		const edited = {
			...selected,
			caption: 'A social manager wrote this caption.',
			images: [
				{
					url: 'https://extrachill.com/uploads/override.jpg',
					sourceId: '1:999',
				},
			],
		};
		expect(
			genericArticleInput( edited, 'generic', 'image', {} )
		).toMatchObject( {
			caption: 'A social manager wrote this caption.',
			images: [ { url: 'https://extrachill.com/uploads/override.jpg' } ],
			post_id: 42,
			post_site_id: 1,
		} );
		expect( articleReviewMeta( edited ) ).toEqual( {
			_studio_social_source_post_id: 42,
			_studio_social_source_url: 'https://extrachill.com/article-42/',
		} );
	} );

	it( 'maps specialized source values only into declared fields', () => {
		const selected = applyArticleSource( draft(), source(), specialized() );
		expect( selected.fields ).toEqual( {
			privacy_level: 'PUBLIC_TO_EVERYONE',
			title: 'Article 42',
			description: 'Excerpt 42',
		} );
		expect(
			declaredSchemaInput( specialized().composer.inputSchema, {
				...selected.fields,
				post_id: 42,
				source_url: source().url,
			} )
		).toEqual( selected.fields );
	} );

	it( 'clears source values and replaces them when switching articles', () => {
		const first = applyArticleSource( draft(), source( 42 ), generic() );
		const switched = applyArticleSource( first, source( 77 ), generic() );
		expect( switched.sourcePostId ).toBe( 77 );
		expect( switched.caption ).toContain( 'Article 77' );
		expect( switched.images[ 0 ].sourceId ).toBe( '1:177' );

		const cleared = applyArticleSource( switched, null, generic() );
		expect( cleared ).toMatchObject( {
			caption: '',
			images: [],
			sourcePostId: null,
			sourceUrl: '',
		} );
	} );

	it( 'only builds draft state and a GET source request, never a publish action', () => {
		const url = new URL( articlePostsUrl( 'https://extrachill.com/' ) );
		const selected = applyArticleSource( draft(), source(), generic() );
		expect( url.pathname ).toBe( '/wp-json/wp/v2/posts' );
		expect( selected ).not.toHaveProperty( 'status' );
		expect( selected ).not.toHaveProperty( 'publish' );
	} );
} );
