/* global afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { TicketsTab, formatMoney, salesSummary } from './tickets-tab';
import { runAbility } from './api';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( './api', () => ( {
	runAbility: jest.fn(),
	errorDetails: ( error ) => ( {
		message: error?.message || 'Request failed.',
	} ),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children, compact, depth, tone, ...props } ) => {
		void compact;
		void depth;
		void tone;
		return React.createElement( 'div', props, children );
	};
	return {
		Badge: ( { children } ) =>
			React.createElement( 'span', null, children ),
		InlineStatus: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title } ) =>
			React.createElement( 'header', null, title ),
		DataTable: ( { columns, data, emptyMessage, rowKey } ) =>
			data.length === 0
				? React.createElement( 'p', null, emptyMessage )
				: React.createElement(
						'table',
						null,
						React.createElement(
							'tbody',
							null,
							data.map( ( row ) =>
								React.createElement(
									'tr',
									{ key: row[ rowKey ] },
									columns.map( ( column ) =>
										React.createElement(
											'td',
											{ key: column.key },
											column.render
												? column.render(
														row[ column.key ],
														row
													)
												: row[ column.key ]
										)
									)
								)
							)
						)
					),
	};
} );

const show = ( orders ) => ( {
	event_id: 9,
	title: 'Late Show',
	url: 'https://events.test/late-show/',
	manage_url: 'https://events.test/late-show/#ec-guest-list-management',
	start: '2026-11-20 21:00:00',
	timing: 'upcoming',
	tickets_enabled: true,
	price_minor: 1500,
	currency: 'usd',
	quantity: 100,
	sold: 3,
	checked_in: 1,
	refunded: 0,
	orders,
} );

const paid = {
	order_reference: 'cs_paid',
	name: 'Ada',
	quantity: 2,
	checked_in: 0,
	bought_at: '2026-11-08 12:00:00',
	status: 'paid',
	refundable: true,
};
const admitted = {
	order_reference: 'cs_in',
	name: 'Bo',
	quantity: 1,
	checked_in: 1,
	bought_at: '2026-11-08 13:00:00',
	status: 'checked_in',
	refundable: false,
};

const flush = () => act( async () => {} );

describe( 'TicketsTab', () => {
	let container;
	let root;
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		runAbility.mockReset();
		apiFetch.mockReset();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );
	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	const mount = async ( seller = { type: 'venue', id: 1524 } ) => {
		await act( async () =>
			root.render( <TicketsTab seller={ seller } /> )
		);
		await flush();
	};

	it( 'summarizes a show', () => {
		expect( formatMoney( 1500 ) ).toBe( '$15.00' );
		expect( salesSummary( show( [] ) ) ).toBe(
			'3 of 100 sold · 1 checked in · $15.00 each'
		);
	} );

	it( 'lists shows for the seller and offers refund only before check-in', async () => {
		runAbility.mockResolvedValue( {
			events: [ show( [ paid, admitted ] ) ],
		} );
		await mount();

		expect( runAbility ).toHaveBeenCalledWith(
			'extrachill/list-seller-ticket-sales',
			{ type: 'venue', id: 1524 }
		);
		expect( container.textContent ).toContain( 'Late Show' );
		const buttons = [ ...container.querySelectorAll( 'button' ) ];
		expect( buttons.map( ( button ) => button.textContent ) ).toEqual( [
			'Refund',
		] );
		expect( container.textContent ).toContain( 'Checked in' );
	} );

	it( 'refunds through the Events route after confirmation, then reloads', async () => {
		runAbility.mockResolvedValue( { events: [ show( [ paid ] ) ] } );
		apiFetch.mockResolvedValue( { refunded: true } );
		window.confirm = jest.fn( () => true );
		await mount();

		await act( async () => container.querySelector( 'button' ).click() );
		await flush();

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/extrachill/v1/events/tickets/orders/cs_paid/refund',
			method: 'POST',
		} );
		expect( runAbility ).toHaveBeenCalledTimes( 2 );
		expect( container.textContent ).toContain( 'Refunded Ada.' );
	} );

	it( 'does nothing when the refund is not confirmed', async () => {
		runAbility.mockResolvedValue( { events: [ show( [ paid ] ) ] } );
		window.confirm = jest.fn( () => false );
		await mount();

		await act( async () => container.querySelector( 'button' ).click() );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'explains a refund refused after check-in', async () => {
		runAbility.mockResolvedValue( { events: [ show( [ paid ] ) ] } );
		apiFetch.mockRejectedValue( { code: 'ticket_already_redeemed' } );
		window.confirm = jest.fn( () => true );
		await mount();

		await act( async () => container.querySelector( 'button' ).click() );
		await flush();
		expect( container.textContent ).toContain(
			'can no longer be refunded'
		);
	} );

	it( 'shows the authority error for people without finance access', async () => {
		runAbility.mockRejectedValue( {
			message:
				"Only people who manage this seller's money can see ticket sales.",
		} );
		await mount();
		expect( container.textContent ).toContain( 'Only people who manage' );
	} );
} );
