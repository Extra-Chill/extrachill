/* global afterEach, beforeEach, describe, expect, it, jest */

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
import ShowCard from './ShowCard';

jest.mock( './RsvpPassBadge', () => ( { eventId } ) => (
	<div className="mock-rsvp-pass-badge" data-event-id={ eventId } />
) );

const baseShow = {
	event_id: 42,
	title: 'Extra Chill & WordPress Meetup',
	event_date: '2026-10-21',
	permalink: 'https://events.extrachill.com/extra-chill-wordpress-meetup/',
	timing: 'upcoming',
};

describe( 'ShowCard RSVP pass badge (#877 slice 2)', () => {
	let container;
	let root;

	beforeEach( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	it( 'renders the pass badge for the owner viewing an upcoming show', () => {
		act( () => {
			root.render( <ShowCard show={ baseShow } isOwn /> );
		} );

		const badge = container.querySelector( '.mock-rsvp-pass-badge' );
		expect( badge ).not.toBeNull();
		expect( badge.getAttribute( 'data-event-id' ) ).toBe( '42' );
	} );

	it( 'does not render the pass badge for a past show', () => {
		act( () => {
			root.render(
				<ShowCard show={ { ...baseShow, timing: 'past' } } isOwn />
			);
		} );

		expect( container.querySelector( '.mock-rsvp-pass-badge' ) ).toBeNull();
	} );

	it( 'does not render the pass badge for a public (non-owner) viewer', () => {
		act( () => {
			root.render( <ShowCard show={ baseShow } isOwn={ false } /> );
		} );

		expect( container.querySelector( '.mock-rsvp-pass-badge' ) ).toBeNull();
	} );

	it( 'does not render the pass badge when isOwn is omitted (defaults false)', () => {
		act( () => {
			root.render( <ShowCard show={ baseShow } /> );
		} );

		expect( container.querySelector( '.mock-rsvp-pass-badge' ) ).toBeNull();
	} );
} );
