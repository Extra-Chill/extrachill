import {
	browserComposerSchema,
	buildComposerRequest,
	filterAvailablePlatforms,
	normalizePublishOutcome,
	validateComposerInput,
} from './contract';

const composer = ( overrides = {} ) => ( {
	crossPostCompatible: true,
	mediaKinds: [ 'image' ],
	target: { transport: 'rest', name: 'datamachine/v1/socials/post' },
	inputSchema: {
		type: 'object',
		required: [ 'platforms', 'caption', 'media_kind' ],
		properties: {
			platforms: { type: 'array', items: { type: 'string' } },
			caption: { type: 'string' },
			media_kind: { type: 'string', enum: [ 'image' ] },
		},
	},
	mediaRequirements: { image: { required: [ 'caption', 'images' ] } },
	...overrides,
} );

const platform = ( slug, authenticated, contract = composer() ) => ( {
	slug,
	label: slug,
	type: 'publish',
	authenticated,
	username: null,
	capabilities: [ { slug: 'publish', label: 'Publish' } ],
	composer: contract,
} );

describe( 'social composer contract', () => {
	it( 'discovers every authenticated publisher when the allowlist is empty', () => {
		const platforms = [
			platform( 'generic-one', true ),
			platform(
				'specialized-one',
				true,
				composer( {
					crossPostCompatible: false,
					target: {
						transport: 'ability',
						name: 'vendor/special-publish',
					},
				} )
			),
			platform( 'disconnected', false ),
			platform( 'missing-contract', true, null ),
		];

		expect(
			filterAvailablePlatforms( platforms, [] ).map(
				( item ) => item.slug
			)
		).toEqual( [ 'generic-one', 'specialized-one', 'missing-contract' ] );
		expect(
			filterAvailablePlatforms( platforms, [ 'specialized-one' ] ).map(
				( item ) => item.slug
			)
		).toEqual( [ 'specialized-one' ] );
	} );

	it( 'routes generic publishers through their declared REST target', () => {
		expect(
			buildComposerRequest( composer(), { platforms: [ 'anything' ] } )
		).toEqual( {
			path: '/datamachine/v1/socials/post',
			method: 'POST',
			data: { platforms: [ 'anything' ] },
		} );
	} );

	it( 'routes every specialized publisher through its declared core Ability target', () => {
		const specialized = composer( {
			crossPostCompatible: false,
			target: { transport: 'ability', name: 'vendor/special-publish' },
		} );

		expect(
			buildComposerRequest( specialized, { title: 'A video' } )
		).toEqual( {
			path: '/wp-abilities/v1/abilities/vendor/special-publish/run',
			method: 'POST',
			data: { input: { title: 'A video' } },
		} );
	} );

	it( 'rejects missing required and one-of fields before submission', () => {
		const errors = validateComposerInput(
			{
				type: 'object',
				required: [ 'title' ],
				oneOf: [
					{ required: [ 'video_file_path' ] },
					{ required: [ 'video_url' ] },
				],
				properties: {
					title: { type: 'string' },
					video_file_path: { type: 'string' },
					video_url: { type: 'string', format: 'uri' },
				},
			},
			{}
		);

		expect( errors.join( ' ' ) ).toContain( 'title' );
		expect( errors.join( ' ' ) ).toContain( 'video' );
	} );

	it( 'selects the browser URL branch and omits server-only file paths', () => {
		const schema = browserComposerSchema( {
			type: 'object',
			required: [ 'title' ],
			oneOf: [
				{ required: [ 'video_file_path' ] },
				{ required: [ 'video_url' ] },
			],
			properties: {
				title: { type: 'string' },
				video_file_path: {
					type: 'string',
					description: 'Absolute local path to the video file',
				},
				video_url: { type: 'string', format: 'uri' },
			},
		} );

		expect( schema.properties ).not.toHaveProperty( 'video_file_path' );
		expect( schema.properties ).toHaveProperty( 'video_url' );
		expect( schema.required ).toEqual( [ 'title', 'video_url' ] );
		expect(
			validateComposerInput( schema, { title: 'Browser upload' } ).join(
				' '
			)
		).toContain( 'video url' );
	} );

	it( 'normalizes publish results without exposing arbitrary response data', () => {
		expect(
			normalizePublishOutcome( {
				success: true,
				status: 'complete',
				video_id: 'video-123',
				url: 'https://example.com/watch/video-123',
				privacy_status: 'private',
				access_token: 'must-not-render',
				raw: { debug: true },
			} )
		).toEqual( {
			success: true,
			status: 'complete',
			id: 'video-123',
			url: 'https://example.com/watch/video-123',
			privacy: 'private',
		} );
	} );

	it( 'rejects unsafe publish result URLs', () => {
		expect(
			normalizePublishOutcome( {
				success: true,
				url: 'javascript:alert(document.domain)',
			} )
		).toEqual( { success: true } );
	} );

	it( 'does not need a platform slug to choose specialized routing', () => {
		const contract = composer( {
			crossPostCompatible: false,
			target: { transport: 'ability', name: 'vendor/video-publish' },
		} );
		const first = buildComposerRequest( contract, { value: 'one' } );
		const second = buildComposerRequest( contract, { value: 'two' } );

		expect( first.path ).toBe( second.path );
		expect( first.path ).not.toMatch( /youtube|tiktok/ );
	} );
} );
