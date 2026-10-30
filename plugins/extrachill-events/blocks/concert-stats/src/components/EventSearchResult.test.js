/**
 * EventSearchResult timing-derived labels (#837).
 */
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
import EventSearchResult from './EventSearchResult';
import useMarkAttendance from '../hooks/useMarkAttendance';

jest.mock( '../hooks/useMarkAttendance', () => {
	const mark = jest.fn( () => Promise.resolve( { marked: true } ) );
	return {
		__esModule: true,
		default: () => ( { mark, isMarking: false, error: null } ),
	};
} );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );
	return { ActionRow: Wrapper, InlineStatus: Wrapper };
} );

const event = ( overrides = {} ) => ( {
	post_id: 42,
	title: 'Test Event',
	event_date: '2026-08-01',
	artists: [],
	...overrides,
} );

async function renderResult( props ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	await act( async () => {
		root.render(
			<EventSearchResult { ...props } onMarkedChange={ () => {} } />
		);
		await Promise.resolve();
	} );

	return { container, root };
}

const actionButton = ( container ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( button ) => ! button.disabled
	);

describe( 'EventSearchResult labels', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		document.body.innerHTML = '';
		useMarkAttendance().mark.mockClear();
	} );
	it.each( [
		[ 'upcoming', "I'm going" ],
		[ 'ongoing', 'Check In' ],
		[ 'past', '+ Mark Attended' ],
	] )( 'offers %s events a "%s" action', async ( timing, label ) => {
		const { container, root } = await renderResult( {
			event: event( { timing } ),
		} );

		expect( actionButton( container ).textContent ).toBe( label );
		await act( async () => root.unmount() );
	} );

	it.each( [
		[ 'upcoming', '✓ Going' ],
		[ 'ongoing', '✓ Checked In' ],
		[ 'past', '✓ Tracked' ],
	] )( 'shows a check-marked "%s" tracked state', async ( timing, label ) => {
		const { container, root } = await renderResult( {
			event: event( { timing, is_marked: true } ),
		} );

		const marked = container.querySelector( 'button[disabled]' );
		expect( marked.textContent ).toBe( label );
		expect( actionButton( container ) ).toBeUndefined();
		await act( async () => root.unmount() );
	} );

	it( 'keeps past labels when timing is absent', async () => {
		const { container, root } = await renderResult( {
			event: event(),
		} );

		expect( actionButton( container ).textContent ).toBe(
			'+ Mark Attended'
		);
		await act( async () => root.unmount() );
	} );

	it( 'marks an upcoming event going on click', async () => {
		const { container, root } = await renderResult( {
			event: event( { timing: 'upcoming' } ),
		} );

		await act( async () => {
			actionButton( container ).click();
			await Promise.resolve();
		} );

		expect( useMarkAttendance().mark ).toHaveBeenCalledWith( {
			eventId: 42,
		} );
		await act( async () => root.unmount() );
	} );
} );
