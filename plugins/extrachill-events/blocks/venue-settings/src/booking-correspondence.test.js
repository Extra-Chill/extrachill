/* global afterEach, beforeEach, describe, expect, HTMLInputElement, HTMLTextAreaElement, it, jest */

/**
 * External dependencies
 */
import { act } from 'react';
import { createRoot } from 'react-dom/client';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	return {
		FieldGroup: ( { children, label } ) =>
			React.createElement( 'label', null, label, children ),
		Grid: ( { children } ) => React.createElement( 'div', null, children ),
		InlineStatus: ( { children, tone } ) =>
			React.createElement( 'div', { 'data-tone': tone }, children ),
	};
} );

jest.mock( './api', () => ( {
	runAbility: jest.fn(),
	errorDetails: ( error ) => ( {
		code: error?.code || 'request_failed',
		message: error?.message || 'Request failed.',
		status: error?.data?.status || 0,
	} ),
} ) );

/**
 * Internal dependencies
 */
import { Correspondence } from './booking-console';
import { runAbility } from './api';

const { TextEncoder } = require( 'node:util' );
const { webcrypto } = require( 'node:crypto' );

global.IS_REACT_ACT_ENVIRONMENT = true;
global.TextEncoder = TextEncoder;
Object.defineProperty( global, 'crypto', {
	configurable: true,
	value: webcrypto,
} );

const booking = {
	id: 42,
	contact_email: 'artist@example.com',
	status: 'under_review',
};

const mounted = [];

const fill = ( container, selector, value ) => {
	const field = container.querySelector( selector );
	const prototype =
		field.tagName === 'TEXTAREA'
			? HTMLTextAreaElement.prototype
			: HTMLInputElement.prototype;
	Object.getOwnPropertyDescriptor( prototype, 'value' ).set.call(
		field,
		value
	);
	field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
};

const submit = ( container ) =>
	container
		.querySelector( 'form' )
		.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);

const waitFor = async ( predicate ) => {
	for ( let attempt = 0; attempt < 20; attempt++ ) {
		if ( predicate() ) {
			return;
		}
		await act(
			async () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) )
		);
	}
	throw new Error( 'Timed out waiting for correspondence state.' );
};

const renderCorrespondence = async (
	onRefresh = jest.fn().mockResolvedValue( [] )
) => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render(
			<Correspondence
				booking={ booking }
				items={ [] }
				onRefresh={ onRefresh }
			/>
		);
	} );
	await act( async () => {
		fill( container, '#booking-message-subject', ' Offer details ' );
		fill( container, '#booking-message-reply-to', 'venue@example.com' );
		fill( container, '#booking-message-body', 'Hold this date.' );
	} );
	mounted.push( { container, root } );
	return { container, root };
};

describe( 'booking correspondence delivery state', () => {
	beforeEach( () => {
		runAbility.mockReset();
	} );

	afterEach( async () => {
		await act( async () => {
			mounted.splice( 0 ).forEach( ( { container, root } ) => {
				root.unmount();
				container.remove();
			} );
		} );
	} );

	it( 'keeps committed success when the correspondence refresh fails', async () => {
		runAbility.mockResolvedValue( { status: 'queued' } );
		const onRefresh = jest.fn().mockRejectedValue( new Error( 'offline' ) );
		const { container } = await renderCorrespondence( onRefresh );

		await act( async () => submit( container ) );
		await waitFor( () => runAbility.mock.calls.length === 1 );

		expect( runAbility.mock.calls[ 0 ][ 1 ].subject ).toBe(
			'Offer details'
		);
		expect(
			container.querySelector( '#booking-message-subject' ).maxLength
		).toBe( 200 );
		expect( container.querySelector( '[role="status"]' ).textContent ).toBe(
			'Message queued. Correspondence could not be refreshed.'
		);
		expect( container.querySelector( '#booking-message-body' ).value ).toBe(
			''
		);
		expect( runAbility ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reconciles a lost success response by exact history key', async () => {
		runAbility.mockRejectedValue( new Error( 'connection lost' ) );
		const onRefresh = jest.fn( async () => [
			{
				kind: 'booking_message_requested',
				idempotency_key:
					runAbility.mock.calls[ 0 ][ 1 ].idempotency_key,
			},
		] );
		const { container } = await renderCorrespondence( onRefresh );

		await act( async () => submit( container ) );
		await waitFor(
			() =>
				container.querySelector( '[role="status"]' )?.textContent ===
				'Message recorded. Delivery status refreshed.'
		);

		expect( container.querySelector( '[role="status"]' ).textContent ).toBe(
			'Message recorded. Delivery status refreshed.'
		);
		expect( container.querySelector( '#booking-message-body' ).value ).toBe(
			''
		);
	} );

	it( 'blocks a double click before React pending state renders', async () => {
		let resolveSend;
		runAbility.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveSend = resolve;
			} )
		);
		const { container } = await renderCorrespondence();

		await act( async () => {
			submit( container );
			submit( container );
			await Promise.resolve();
		} );
		await waitFor( () => runAbility.mock.calls.length === 1 );
		expect( runAbility ).toHaveBeenCalledTimes( 1 );

		await act( async () => resolveSend( { status: 'queued' } ) );
	} );

	it( 'preserves a stale-session draft and its key while history is unavailable', async () => {
		const stale = Object.assign( new Error( 'Session expired.' ), {
			data: { status: 403 },
		} );
		runAbility.mockRejectedValue( stale );
		const { container } = await renderCorrespondence(
			jest.fn().mockRejectedValue( stale )
		);

		await act( async () => submit( container ) );
		await waitFor( () =>
			container
				.querySelector( '[role="status"]' )
				?.textContent.includes( 'Draft preserved' )
		);
		const firstKey = runAbility.mock.calls[ 0 ][ 1 ].idempotency_key;
		expect( container.querySelector( '#booking-message-body' ).value ).toBe(
			'Hold this date.'
		);
		expect(
			container.querySelector( '#booking-message-subject' ).value
		).toBe( 'Offer details' );
		expect(
			container.querySelector( '[role="status"]' ).textContent
		).toContain( 'Draft preserved' );

		await act( async () => submit( container ) );
		await waitFor( () => runAbility.mock.calls.length === 2 );
		expect( runAbility.mock.calls[ 1 ][ 1 ].idempotency_key ).toBe(
			firstKey
		);
	} );

	it( 'rotates only after history confirms a conclusive rejection', async () => {
		const rejected = Object.assign( new Error( 'Recipient changed.' ), {
			data: { status: 409 },
		} );
		runAbility.mockRejectedValue( rejected );
		const { container } = await renderCorrespondence();

		await act( async () => submit( container ) );
		await waitFor(
			() =>
				container.querySelector( '[role="status"]' )?.textContent ===
				'Recipient changed.'
		);
		const rejectedKey = runAbility.mock.calls[ 0 ][ 1 ].idempotency_key;
		expect( container.querySelector( '[role="status"]' ).textContent ).toBe(
			'Recipient changed.'
		);
		expect( container.querySelector( '#booking-message-body' ).value ).toBe(
			'Hold this date.'
		);

		await act( async () => submit( container ) );
		await waitFor( () => runAbility.mock.calls.length === 2 );
		expect( runAbility.mock.calls[ 1 ][ 1 ].idempotency_key ).not.toBe(
			rejectedKey
		);
	} );
} );
