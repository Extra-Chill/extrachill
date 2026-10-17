/* global afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';
import { act } from 'react';

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { GraphicsTab, effectiveLayout, quickRange } from './graphics-tab';
import { runAbility } from './api';

jest.mock( './api', () => ( {
	__esModule: true,
	runAbility: jest.fn(),
	errorDetails: ( error ) => ( {
		code: error?.code || 'failed',
		message: error?.message || 'The request could not be completed.',
	} ),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children, className } ) =>
		React.createElement( 'div', { className }, children );
	return {
		ActionRow: Wrapper,
		InlineStatus: ( { children, tone } ) =>
			React.createElement( 'div', { 'data-tone': tone }, children ),
		Panel: Wrapper,
		PanelHeader: ( { description, title } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'h2', null, title ),
				React.createElement( 'p', null, description )
			),
		FieldGroup: ( { children, label } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'span', null, label ),
				children
			),
	};
} );

const venue = { id: 1524, name: 'Lo-Fi Brewing' };

const built = {
	success: true,
	venue_term_id: 1524,
	venue_name: 'Lo-Fi Brewing',
	date_start: '2026-09-03',
	date_end: '2026-09-09',
	layout: 'daily',
	title: 'This Week at Lo-Fi Brewing',
	total_events: 5,
	slide_count: 2,
	slides: [
		{
			filename: 'venue-1524-shows-2026-09-03-slide-1.png',
			data_uri: 'data:image/png;base64,c2xpZGUtMQ==',
		},
		{
			filename: 'venue-1524-shows-2026-09-03-slide-2.png',
			data_uri: 'data:image/png;base64,c2xpZGUtMg==',
		},
	],
};

async function renderTab() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( <GraphicsTab venue={ venue } /> ) );
	return container;
}

const button = ( container, text ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( node ) => node.textContent === text
	);

async function click( element ) {
	await act( async () => {
		element.click();
		await Promise.resolve();
	} );
}

describe( 'venue graphics tab', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
		runAbility.mockReset();
	} );

	it( 'generates for the scoped venue with the selected quick range', async () => {
		runAbility.mockResolvedValue( built );

		const container = await renderTab();
		await click( button( container, 'Generate graphics' ) );

		expect( runAbility ).toHaveBeenCalledTimes( 1 );
		const [ ability, input ] = runAbility.mock.calls[ 0 ];
		expect( ability ).toBe( 'extrachill/venue-graphics-build' );
		expect( input.venue_term_id ).toBe( 1524 );
		expect( input ).toEqual(
			expect.objectContaining( {
				...quickRange( 'this-week' ),
				layout: 'daily',
			} )
		);
		// No stray title key: the server owns the default title.
		expect( input ).not.toHaveProperty( 'title' );
	} );

	it( 'shows a loading state while generating', async () => {
		let release;
		runAbility.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					release = () => resolve( built );
				} )
		);

		const container = await renderTab();
		await act( async () => {
			button( container, 'Generate graphics' ).click();
		} );

		const generating = button( container, 'Generating...' );
		expect( generating ).toBeTruthy();
		expect( generating.disabled ).toBe( true );

		await act( async () => {
			release();
			await Promise.resolve();
		} );
		expect( button( container, 'Generate graphics' ) ).toBeTruthy();
	} );

	it( 'previews every slide with a per-slide download and download all', async () => {
		runAbility.mockResolvedValue( built );

		const container = await renderTab();
		await click( button( container, 'Generate graphics' ) );

		const thumbs = container.querySelectorAll(
			'.ec-venue-graphics__slide img'
		);
		expect( thumbs ).toHaveLength( 2 );
		expect( thumbs[ 0 ].src ).toBe( built.slides[ 0 ].data_uri );

		const downloads = [ ...container.querySelectorAll( 'a[download]' ) ];
		expect( downloads ).toHaveLength( 2 );
		expect( downloads[ 1 ].getAttribute( 'download' ) ).toBe(
			built.slides[ 1 ].filename
		);
		expect( button( container, 'Download all' ) ).toBeTruthy();
		expect( container.textContent ).toContain(
			'Made 2 slides from 5 shows.'
		);
	} );

	it( 'passes the compact calendar layout and a custom title when chosen', async () => {
		runAbility.mockResolvedValue( built );

		const container = await renderTab();
		await click( button( container, 'Compact calendar' ) );

		const titleInput = container.querySelector( 'input[type="text"]' );
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set.call( titleInput, 'September at Lo-Fi' );
			titleInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		await click( button( container, 'Generate graphics' ) );

		expect( runAbility.mock.calls[ 0 ][ 1 ] ).toEqual(
			expect.objectContaining( {
				layout: 'calendar',
				title: 'September at Lo-Fi',
			} )
		);
	} );

	it( 'requires both dates before a custom range can generate', async () => {
		const container = await renderTab();
		await click( button( container, 'Custom' ) );

		expect( button( container, 'Generate graphics' ).disabled ).toBe(
			true
		);

		for ( const input of container.querySelectorAll(
			'input[type="date"]'
		) ) {
			await act( async () => {
				Object.getOwnPropertyDescriptor(
					window.HTMLInputElement.prototype,
					'value'
				).set.call( input, '2026-09-03' );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		}

		expect( button( container, 'Generate graphics' ).disabled ).toBe(
			false
		);
	} );

	/**
	 * The zero-shows error explains what happened and how to fix it, so it
	 * must reach the screen verbatim rather than a generic failure.
	 */
	it( 'surfaces the specific no-shows explanation', async () => {
		runAbility.mockRejectedValue( {
			code: 'venue_graphics_no_events',
			message:
				'No published shows were found for Lo-Fi Brewing between 2026-09-03 and 2026-09-09.',
		} );

		const container = await renderTab();
		await click( button( container, 'Generate graphics' ) );

		expect( container.textContent ).toContain(
			'No published shows were found for Lo-Fi Brewing'
		);
		expect(
			container.querySelectorAll( '.ec-venue-graphics__slide' )
		).toHaveLength( 0 );
	} );

	it( 'resolves quick ranges against a fixed today', () => {
		const today = new Date( 2026, 8, 3 ); // Sep 3, 2026.
		expect( quickRange( 'this-week', today ) ).toEqual( {
			date_start: '2026-09-03',
			date_end: '2026-09-09',
		} );
		expect( quickRange( 'this-month', today ) ).toEqual( {
			date_start: '2026-09-03',
			date_end: '2026-09-30',
		} );
		expect( quickRange( 'next-month', today ) ).toEqual( {
			date_start: '2026-10-01',
			date_end: '2026-10-31',
		} );
		expect( quickRange( 'custom', today ) ).toBeNull();
	} );

	it( 'defaults the layout to match the server: daily weeks, calendar months', () => {
		expect( effectiveLayout( null, 'this-week' ) ).toBe( 'daily' );
		expect( effectiveLayout( null, 'this-month' ) ).toBe( 'calendar' );
		expect( effectiveLayout( null, 'next-month' ) ).toBe( 'calendar' );
		expect( effectiveLayout( null, 'custom' ) ).toBe( 'daily' );
		expect( effectiveLayout( 'daily', 'next-month' ) ).toBe( 'daily' );
	} );
} );

/**
 * Design-system vocabulary guard, following the per-template allow-list tests
 * (RsvpPassDesignSystemTest): every class the tab renders must either be a
 * pinned theme class, a shared-component class, or carry a real rule in this
 * block's style.scss. A class that invents itself renders unstyled.
 */
describe( 'venue graphics tab design system', () => {
	const source = readFileSync(
		path.resolve( __dirname, 'graphics-tab.js' ),
		'utf8'
	);
	const styles = readFileSync(
		path.resolve( __dirname, 'style.scss' ),
		'utf8'
	);

	const THEME_CLASSES = [
		'button-1',
		'button-2',
		'button-3',
		'button-danger',
		'button-small',
		'button-medium',
		'button-large',
		'notice',
		'notice-info',
		'notice-success',
		'notice-error',
		// Theme button state modifier, used across the workspace toggles.
		'is-active',
	];

	// Classes that intentionally carry no CSS rule (inherit typography).
	const CSS_FREE_CLASSES = [ 'ec-venue-settings__hint' ];

	const classesUsed = () => {
		const classes = new Set();
		for ( const match of source.matchAll( /className="([^"]+)"/g ) ) {
			match[ 1 ]
				.split( /\s+/ )
				.filter( Boolean )
				.forEach( ( name ) => classes.add( name ) );
		}
		// Conditional className expressions list their variants as quoted
		// strings; collect those too.
		for ( const match of source.matchAll( /'((?:button|ec-)[^']*)'/g ) ) {
			match[ 1 ]
				.split( /\s+/ )
				.filter( Boolean )
				.forEach( ( name ) => classes.add( name ) );
		}
		return [ ...classes ];
	};

	it( 'uses only classes that actually render', () => {
		const unstyled = classesUsed().filter(
			( name ) =>
				! THEME_CLASSES.includes( name ) &&
				! CSS_FREE_CLASSES.includes( name ) &&
				! new RegExp( `\\.${ name }(?![a-z0-9_-])[^{}]*\\{` ).test(
					styles
				)
		);
		expect( unstyled ).toEqual( [] );
	} );

	it( 'does not use invented .card / .btn classes', () => {
		expect( source ).not.toMatch(
			/className="[^"]*\b(card|btn|btn--[a-z]+|notice--[a-z]+)\b/
		);
	} );

	it( 'styles every button and download link with a theme button class', () => {
		for ( const match of source.matchAll(
			/<(?:button|a)\b[\s\S]*?className=(?:"([^"]+)"|\{([\s\S]*?)\})/g
		) ) {
			const value = match[ 1 ] || match[ 2 ];
			expect( value ).toMatch( /button-(1|2|3|danger)/ );
		}
	} );
} );
