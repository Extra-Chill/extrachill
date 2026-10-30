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

describe( 'ShowCard opens the show', () => {
	let container;
	let root;

	const pastShow = {
		...baseShow,
		title: 'Burgundy: Extra Chill Wednesdays ft. Steel Petals',
		timing: 'past',
		artists: [
			{
				name: 'Steel Petals',
				slug: 'steel-petals',
				url: 'https://events.extrachill.com/artist/steel-petals/',
			},
			{
				name: 'Jeff Nark',
				slug: 'jeff-nark',
				url: 'https://artist.extrachill.com/jeff-nark/',
			},
		],
		venue: {
			name: 'The Starlight Motor Inn',
			slug: 'the-starlight-motor-inn',
			url: 'https://events.extrachill.com/venue/the-starlight-motor-inn/',
		},
		city: {
			name: 'Charleston',
			slug: 'charleston',
			url: 'https://events.extrachill.com/location/charleston/',
		},
	};

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

	const render = ( show, props = {} ) =>
		act( () => {
			root.render( <ShowCard show={ show } { ...props } /> );
		} );

	it( 'links the show name to the event, not the artist', () => {
		render( pastShow );

		const link = container.querySelector( '.ec-concert-stats__show-link' );
		expect( link ).not.toBeNull();
		expect( link.getAttribute( 'href' ) ).toBe( pastShow.permalink );
		expect( link.textContent ).toBe( 'Steel Petals, Jeff Nark' );

		const hrefs = Array.from( container.querySelectorAll( 'a' ) ).map(
			( a ) => a.getAttribute( 'href' )
		);
		expect( hrefs ).not.toContain( pastShow.artists[ 0 ].url );
		expect( hrefs ).not.toContain( pastShow.artists[ 1 ].url );
	} );

	it( 'has exactly one link to the event per row', () => {
		render( pastShow );

		const eventLinks = Array.from(
			container.querySelectorAll( 'a' )
		).filter( ( a ) => a.getAttribute( 'href' ) === pastShow.permalink );
		expect( eventLinks ).toHaveLength( 1 );
		expect(
			container
				.querySelector( '.ec-concert-stats__show-card' )
				.classList.contains( 'has-link' )
		).toBe( true );
	} );

	it( 'keeps venue and city badges as their own links', () => {
		render( pastShow );

		const venue = container.querySelector( 'a.venue-badge' );
		const city = container.querySelector( 'a.location-badge' );
		expect( venue.getAttribute( 'href' ) ).toBe( pastShow.venue.url );
		expect( city.getAttribute( 'href' ) ).toBe( pastShow.city.url );
	} );

	it( 'falls back to the show title when there are no artists', () => {
		render( { ...pastShow, artists: [] } );

		expect(
			container.querySelector( '.ec-concert-stats__show-link' )
				.textContent
		).toBe( pastShow.title );
	} );

	it( 'renders the name as plain text when the show has no permalink', () => {
		render( { ...pastShow, permalink: '' } );

		expect(
			container.querySelector( '.ec-concert-stats__show-link' )
		).toBeNull();
		expect(
			container.querySelector( '.ec-concert-stats__show-artist' )
				.textContent
		).toBe( 'Steel Petals, Jeff Nark' );
		expect(
			container
				.querySelector( '.ec-concert-stats__show-card' )
				.classList.contains( 'has-link' )
		).toBe( false );
	} );

	it( 'rates in place without following the row link', () => {
		const onRate = jest.fn();
		render( pastShow, { isOwn: true, rating: { value: 0, onRate } } );

		act( () => {
			container
				.querySelector( '.ec-concert-stats__show-rating-toggle' )
				.click();
		} );
		const stars = container.querySelectorAll(
			'.ec-concert-stats__show-rating-star'
		);
		expect( stars ).toHaveLength( 5 );
		expect( stars[ 3 ].closest( 'a' ) ).toBeNull();

		act( () => {
			stars[ 3 ].click();
		} );
		expect( onRate ).toHaveBeenCalledWith( pastShow.event_id, 4 );
	} );
} );
