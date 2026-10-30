import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import {
	buildNetworkSearchPath,
	TermPicker,
} from '../../src/term-picker/TermPicker';
import type { TaxonomyConfig } from '../../src/term-picker/types';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

function changeInput( input: HTMLInputElement, value: string ) {
	const setter = Object.getOwnPropertyDescriptor(
		HTMLInputElement.prototype,
		'value'
	)?.set;
	setter?.call( input, value );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

function renderModalMarkup( autoOpen: boolean ) {
	document.body.innerHTML = `
		<a id="new-topic-modal-trigger" data-modal-mode="discussion" href="#new-post">Create Discussion</a>
		<div id="new-topic-modal-overlay"></div>
		<div id="new-topic-modal" data-auto-open="${ autoOpen }">
			<button type="button" class="new-topic-modal-close">Close</button>
			<h2 id="new-topic-modal-title">Create Discussion</h2>
			<p id="new-topic-modal-description"></p>
			<p><select id="bbp_forum_id"><option value="1">Music</option></select></p>
			<input id="bbp_topic_title" type="text" />
			<textarea id="bbp_topic_content"></textarea>
		</div>`;
}

const artistConfig: TaxonomyConfig = {
	taxonomy: 'artist',
	label: 'Artist',
	placeholder: 'Search artists...',
	field: 'bbp_topic_artist',
	selected: [ { id: 42, name: 'Phish', slug: 'phish' } ],
};

describe( 'discussion composer continuation', () => {
	beforeAll( () => {
		const reactGlobal = globalThis as typeof globalThis & {
			IS_REACT_ACT_ENVIRONMENT: boolean;
		};
		reactGlobal.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
		jest.useRealTimers();
		jest.resetModules();
	} );

	it( 'auto-opens the existing discussion modal when instructed', () => {
		renderModalMarkup( true );

		jest.isolateModules( () => {
			require( '../../inc/home/assets/js/new-topic-modal.js' );
		} );

		expect(
			document.getElementById( 'new-topic-modal' )?.classList
		).toContain( 'is-open' );
		expect(
			document.getElementById( 'new-topic-modal-overlay' )?.classList
		).toContain( 'is-open' );
		expect( document.body.classList ).toContain( 'new-topic-modal-open' );
		expect(
			document.getElementById( 'new-topic-modal' )?.ownerDocument
				.activeElement
		).toBe( document.getElementById( 'bbp_topic_title' ) );
	} );

	it( 'does not auto-open the normal composer without continuation state', () => {
		renderModalMarkup( false );

		jest.isolateModules( () => {
			require( '../../inc/home/assets/js/new-topic-modal.js' );
		} );

		expect(
			document.getElementById( 'new-topic-modal' )?.classList
		).not.toContain( 'is-open' );
	} );

	it( 'preserves existing Community-local IDs on edit', () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		act( () => {
			root.render(
				<TermPicker config={ artistConfig } topicId={ 91 } />
			);
		} );

		const selectedInput = container.querySelector< HTMLInputElement >(
			'input[name="bbp_topic_artist[]"]'
		);
		expect( selectedInput?.value ).toBe( '42' );
		expect( container.textContent ).toContain( 'Phish' );

		act( () => root.unmount() );
	} );

	it( 'routes search through the network ability and assigns projected local IDs', async () => {
		jest.useFakeTimers();
		mockedApiFetch
			.mockResolvedValueOnce( {
				terms: [
					{
						taxonomy: 'artist',
						slug: 'kid-lake',
						name: 'Kid Lake',
						source: 'main',
					},
				],
			} as never )
			.mockResolvedValueOnce( {
				taxonomy: 'artist',
				slug: 'kid-lake',
				term_id: 77,
			} as never );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		act( () => {
			root.render(
				<TermPicker
					config={ { ...artistConfig, selected: [] } }
					topicId={ 91 }
				/>
			);
		} );

		const input = container.querySelector< HTMLInputElement >(
			'.ec-term-picker__input'
		);
		await act( async () => {
			if ( input ) {
				changeInput( input, 'Kid Lake' );
			}
			jest.advanceTimersByTime( 250 );
			await Promise.resolve();
		} );

		expect( mockedApiFetch ).toHaveBeenNthCalledWith(
			1,
			expect.objectContaining( {
				method: 'GET',
				path: expect.stringContaining(
					'/wp-abilities/v1/abilities/extrachill/search-network-terms/run?input='
				),
			} )
		);
		expect( mockedApiFetch.mock.calls[ 0 ][ 0 ].path ).not.toContain(
			'/wp/v2/'
		);

		const suggestion = container.querySelector< HTMLElement >(
			'.ec-term-picker__suggestion:not(.ec-term-picker__suggestion--status)'
		);
		await act( async () => {
			suggestion?.dispatchEvent(
				new MouseEvent( 'mousedown', { bubbles: true } )
			);
			await Promise.resolve();
		} );

		expect( mockedApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wp-abilities/v1/abilities/extrachill/project-network-term/run',
			method: 'POST',
			data: {
				input: {
					site: 'community',
					post_id: 91,
					taxonomy: 'artist',
					slug: 'kid-lake',
				},
			},
		} );
		expect(
			container.querySelector< HTMLInputElement >(
				'input[name="bbp_topic_artist[]"]'
			)?.value
		).toBe( '77' );

		act( () => root.unmount() );
	} );

	it( 'degrades without blocking when the network runtime is absent', async () => {
		jest.useFakeTimers();
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Ability not found' )
		);
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		act( () => {
			root.render(
				<TermPicker config={ artistConfig } topicId={ 91 } />
			);
		} );

		const input = container.querySelector< HTMLInputElement >(
			'.ec-term-picker__input'
		);
		await act( async () => {
			if ( input ) {
				changeInput( input, 'Missing' );
			}
			jest.advanceTimersByTime( 250 );
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain(
			'Term suggestions are unavailable. You can still save your topic.'
		);
		expect( container.textContent ).not.toContain( 'No matching terms' );
		expect(
			container.querySelector< HTMLInputElement >(
				'input[name="bbp_topic_artist[]"]'
			)?.value
		).toBe( '42' );

		act( () => root.unmount() );
	} );

	it( 'encodes the complete approved-network search contract', () => {
		const path = buildNetworkSearchPath( 'festival', 'High Water' );
		const input = JSON.parse(
			decodeURIComponent( path.split( '?input=' )[ 1 ] )
		);
		expect( input ).toEqual( {
			site: 'community',
			post_type: 'topic',
			taxonomy: 'festival',
			query: 'High Water',
			limit: 10,
		} );
	} );
} );
