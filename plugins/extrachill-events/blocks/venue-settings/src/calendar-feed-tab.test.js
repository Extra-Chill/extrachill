/* global afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { CalendarFeedTab } from './calendar-feed-tab';
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

const unbound = {
	venue_term_id: 1524,
	bound: false,
	feed_url: '',
	status: '',
	last_synced: '',
	last_error: '',
};

const bound = {
	venue_term_id: 1524,
	bound: true,
	feed_url: 'https://calendar.google.com/calendar/ical/x/public/basic.ics',
	status: 'active',
	last_synced: '2026-09-03 12:00:00',
	last_error: '',
};

async function renderTab() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( <CalendarFeedTab venue={ venue } /> ) );
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

describe( 'venue calendar feed tab', () => {
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

	it( 'loads the current binding on mount', async () => {
		runAbility.mockResolvedValue( unbound );

		await renderTab();

		expect( runAbility ).toHaveBeenCalledWith(
			'extrachill/get-venue-calendar-feed',
			{ venue_term_id: 1524 }
		);
	} );

	it( 'offers connect only, with no import or disconnect, when unbound', async () => {
		runAbility.mockResolvedValue( unbound );

		const container = await renderTab();

		expect( button( container, 'Connect calendar' ) ).toBeTruthy();
		expect( button( container, 'Import now' ) ).toBeFalsy();
		expect( button( container, 'Disconnect' ) ).toBeFalsy();
	} );

	it( 'offers import and disconnect once bound', async () => {
		runAbility.mockResolvedValue( bound );

		const container = await renderTab();

		expect( button( container, 'Update calendar' ) ).toBeTruthy();
		expect( button( container, 'Import now' ) ).toBeTruthy();
		expect( button( container, 'Disconnect' ) ).toBeTruthy();
	} );

	/**
	 * The verified event count is how an owner confirms they pasted the right
	 * calendar, so it has to reach the screen rather than a generic success.
	 */
	it( 'reports the verified event count after connecting', async () => {
		runAbility
			.mockResolvedValueOnce( unbound )
			.mockResolvedValueOnce( { ...bound, event_count: 12 } );

		const container = await renderTab();
		const input = container.querySelector( 'input[type="url"]' );

		await act( async () => {
			Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set.call( input, 'https://example.com/feed.ics' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		await click( button( container, 'Connect calendar' ) );

		expect( container.textContent ).toContain( 'Found 12 events' );
	} );

	it( 'surfaces a specific bind failure rather than a generic message', async () => {
		runAbility.mockResolvedValueOnce( unbound ).mockRejectedValueOnce( {
			code: 'venue_calendar_feed_not_calendar',
			message:
				'That address did not return a calendar feed. Use the public ICS address, not the calendar web page.',
		} );

		const container = await renderTab();
		const input = container.querySelector( 'input[type="url"]' );

		await act( async () => {
			Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set.call( input, 'https://example.com/not-a-feed' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		await click( button( container, 'Connect calendar' ) );

		expect( container.textContent ).toContain(
			'did not return a calendar feed'
		);
	} );

	it( 'summarizes what an import actually changed', async () => {
		runAbility.mockResolvedValueOnce( bound ).mockResolvedValueOnce( {
			...bound,
			created: 8,
			updated: 2,
			unchanged: 40,
			cancelled: 1,
			skipped: 0,
		} );

		const container = await renderTab();

		await click( button( container, 'Import now' ) );

		expect( container.textContent ).toContain(
			'8 added, 2 updated, 40 unchanged, 1 removed'
		);
	} );

	it( 'shows the parked error message when a binding has failed', async () => {
		runAbility.mockResolvedValue( {
			...bound,
			status: 'error',
			last_error: 'The calendar feed returned HTTP 404.',
		} );

		const container = await renderTab();

		expect( container.textContent ).toContain(
			'The calendar feed returned HTTP 404.'
		);
	} );

	/**
	 * Disconnecting must read as safe. Imported events are retained, and the
	 * copy has to say so or an owner will hesitate to use the button.
	 */
	it( 'states that imported events are kept after disconnecting', async () => {
		runAbility
			.mockResolvedValueOnce( bound )
			.mockResolvedValueOnce( unbound );

		const container = await renderTab();

		await click( button( container, 'Disconnect' ) );

		expect( container.textContent ).toContain(
			'Events already imported were kept'
		);
	} );
} );
