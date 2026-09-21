import {
	getArtist,
	getLinks,
	getSocials,
	updateArtist,
	updateLinks,
	updateSocials,
	deleteMedia,
} from '../../src/blocks/shared/api/client';
import apiFetch from '@wordpress/api-fetch';
import { adapter } from '../../src/blocks/link-page-editor/adapter';

jest.mock( '../../src/blocks/shared/api/client', () => ( {
	getArtist: jest.fn( async () => ( { id: 7, name: 'Identity' } ) ),
	getLinks: jest.fn( async () => ( {
		links: [],
		settings: {},
		css_vars: {},
	} ) ),
	getSocials: jest.fn( async () => ( { social_links: [] } ) ),
	updateArtist: jest.fn( async () => ( {} ) ),
	updateLinks: jest.fn( async () => ( {} ) ),
	updateSocials: jest.fn( async () => ( {} ) ),
	uploadMedia: jest.fn(),
	deleteMedia: jest.fn(),
	generateQRCode: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( async () => ( {} ) ),
} ) );

const draft = {
	identity: { name: 'Changed', imageId: 9 },
	page: {
		links: [],
		settings: {},
		bio: '',
		styles: {},
		backgroundImageId: 0,
	},
	socials: [],
};

describe( 'shared editor Artist adapter', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'saves only identity resources when only identity is dirty', async () => {
		await adapter.save( 7, draft, { dirtyAreas: [ 'identity' ] } );
		expect( updateArtist ).toHaveBeenCalledTimes( 1 );
		expect( updateLinks ).not.toHaveBeenCalled();
		expect( updateSocials ).not.toHaveBeenCalled();
		expect( getArtist ).toHaveBeenCalledWith( 7 );
		expect( getLinks ).toHaveBeenCalledWith( 7 );
		expect( getSocials ).toHaveBeenCalledWith( 7 );
	} );

	it( 'keeps unrelated resources untouched for page and social saves', async () => {
		await adapter.save( 7, draft, { dirtyAreas: [ 'links' ] } );
		expect( updateLinks ).toHaveBeenCalledTimes( 1 );
		expect( updateLinks ).toHaveBeenCalledWith( 7, { links: [] } );
		expect( updateArtist ).not.toHaveBeenCalled();
		expect( updateSocials ).not.toHaveBeenCalled();

		jest.clearAllMocks();
		await adapter.save( 7, draft, { dirtyAreas: [ 'socials' ] } );
		expect( updateSocials ).toHaveBeenCalledTimes( 1 );
		expect( updateArtist ).not.toHaveBeenCalled();
		expect( updateLinks ).not.toHaveBeenCalled();
	} );

	it( 'omits unrelated Link Page fields from bio-only saves', async () => {
		await adapter.save( 7, draft, { dirtyAreas: [ 'bio' ] } );
		expect( updateLinks ).not.toHaveBeenCalled();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/extrachill/save-link-page-settings/run',
			method: 'POST',
			data: { input: { artist_id: 7, bio: '' } },
		} );
	} );

	it( 'deletes background media before clearing its attachment id', async () => {
		await adapter.upload( 'background-remove', 7, null );
		expect( deleteMedia ).toHaveBeenCalledWith( 'link_page_background', 7 );
	} );
} );

// extrachill-artist-platform#225: the adapter is the only place that can set
// `allow_empty`, and only ever from a deliberate, confirmed user action.
describe( 'shared editor Artist adapter — clearing all links (extrachill-artist-platform#225)', () => {
	const populatedLinks = {
		links: [
			{
				section_title: '',
				links: [
					{ link_text: 'Site', link_url: 'https://example.com' },
				],
			},
		],
		settings: {},
		css_vars: {},
	};

	beforeEach( () => {
		jest.clearAllMocks();
		window.confirm = jest.fn();
	} );

	it( 'prompts and threads a strict allow_empty=true when clearing a populated page', async () => {
		getLinks.mockResolvedValueOnce( populatedLinks );
		window.confirm.mockReturnValue( true );

		await adapter.save( 7, draft, { dirtyAreas: [ 'links' ] } );

		expect( window.confirm ).toHaveBeenCalledWith(
			'Remove all links from your page?'
		);
		expect( updateLinks ).toHaveBeenCalledWith( 7, {
			links: [],
			allow_empty: true,
		} );
	} );

	it( 'aborts the entire save (including other dirty areas) when the user declines', async () => {
		getLinks.mockResolvedValueOnce( populatedLinks );
		window.confirm.mockReturnValue( false );

		await adapter.save( 7, draft, {
			dirtyAreas: [ 'links', 'identity' ],
		} );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( updateLinks ).not.toHaveBeenCalled();
		expect( updateArtist ).not.toHaveBeenCalled();
	} );

	it( 'never prompts, and never sends allow_empty, when the page is already empty', async () => {
		await adapter.save( 7, draft, { dirtyAreas: [ 'links' ] } );

		expect( window.confirm ).not.toHaveBeenCalled();
		expect( updateLinks ).toHaveBeenCalledWith( 7, { links: [] } );
	} );

	it( 'never prompts when the draft still has links', async () => {
		const nonEmptyDraft = {
			...draft,
			page: { ...draft.page, links: populatedLinks.links },
		};

		await adapter.save( 7, nonEmptyDraft, { dirtyAreas: [ 'links' ] } );

		expect( getLinks ).toHaveBeenCalledTimes( 1 ); // Only the final read().
		expect( window.confirm ).not.toHaveBeenCalled();
		expect( updateLinks ).toHaveBeenCalledWith( 7, {
			links: populatedLinks.links,
		} );
	} );
} );
