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
import RsvpPassBadge from './RsvpPassBadge';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

describe( 'RsvpPassBadge', () => {
	let container;
	let root;

	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		apiFetch.mockReset();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( async () => {
		await act( async () => root.unmount() );
		container.remove();
	} );

	it( 'renders nothing while no pass has been fetched yet', async () => {
		apiFetch.mockImplementation( () => new Promise( () => {} ) );

		await act( async () => {
			root.render( <RsvpPassBadge eventId={ 42 } /> );
		} );

		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders nothing when the event has no perk / no active pass', async () => {
		apiFetch.mockResolvedValue( { issued: false } );

		await act( async () => {
			root.render( <RsvpPassBadge eventId={ 42 } /> );
			await Promise.resolve();
		} );

		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders the perk text and code when an active pass exists', async () => {
		apiFetch.mockResolvedValue( {
			issued: true,
			code: 'ABCDE-FGH2J-K3LMN',
			perk_text: 'First beer on Extra Chill',
		} );

		await act( async () => {
			root.render( <RsvpPassBadge eventId={ 42 } /> );
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp-abilities/v1/abilities/extrachill/get-my-event-pass/run',
				data: { input: { event_id: 42 } },
			} )
		);
		expect(
			container.querySelector( '.ec-concert-stats__rsvp-pass-code' )
				.textContent
		).toBe( 'ABCDE-FGH2J-K3LMN' );
		expect(
			container.querySelector( '.ec-concert-stats__rsvp-pass-perk' )
				.textContent
		).toBe( 'First beer on Extra Chill' );
	} );

	it( 'renders nothing when the fetch fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'network' ) );

		await act( async () => {
			root.render( <RsvpPassBadge eventId={ 42 } /> );
			await Promise.resolve();
		} );

		expect( container.innerHTML ).toBe( '' );
	} );
} );
