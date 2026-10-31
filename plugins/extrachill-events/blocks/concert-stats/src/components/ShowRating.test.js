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
import ShowRating from './ShowRating';

describe( 'ShowRating (private My Shows rating)', () => {
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

	const render = ( props ) =>
		act( () => {
			root.render(
				<ShowRating eventId={ 9 } title="Goose" { ...props } />
			);
		} );
	const stars = () =>
		container.querySelectorAll( '.ec-concert-stats__show-rating-star' );
	const toggle = () =>
		container.querySelector( '.ec-concert-stats__show-rating-toggle' );

	it( 'shows a quiet Rate button, not empty stars, for an unrated show', () => {
		render( { rating: 0, onRate: jest.fn() } );

		expect( stars() ).toHaveLength( 0 );
		expect( toggle().textContent ).toBe( 'Rate' );
		expect( toggle().getAttribute( 'aria-label' ) ).toBe( 'Rate Goose' );
	} );

	it( 'opens the stars on tap and saves the picked rating', () => {
		const onRate = jest.fn();
		render( { rating: 0, onRate } );

		act( () => toggle().click() );
		expect( stars() ).toHaveLength( 5 );

		act( () => stars()[ 2 ].click() );
		expect( onRate ).toHaveBeenCalledWith( 9, 3 );
	} );

	it( 'shows filled stars for a rated show', () => {
		render( { rating: 4, onRate: jest.fn() } );

		expect( toggle() ).toBeNull();
		const on = Array.from( stars() ).filter( ( star ) =>
			star.classList.contains( 'is-on' )
		);
		expect( on ).toHaveLength( 4 );
		expect( stars()[ 3 ].getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	it( 'changes the rating, and clears it when the current star is tapped', () => {
		const onRate = jest.fn();
		render( { rating: 4, onRate } );

		act( () => stars()[ 1 ].click() );
		expect( onRate ).toHaveBeenLastCalledWith( 9, 2 );

		act( () => stars()[ 3 ].click() );
		expect( onRate ).toHaveBeenLastCalledWith( 9, 0 );
	} );

	it( 'collapses back to the Rate button once cleared', () => {
		const onRate = jest.fn();
		render( { rating: 4, onRate } );
		act( () => stars()[ 3 ].click() );
		render( { rating: 0, onRate } );

		expect( stars() ).toHaveLength( 0 );
		expect( toggle() ).not.toBeNull();
	} );

	it( 'surfaces a save error', () => {
		render( { rating: 0, onRate: jest.fn(), error: 'Nope.' } );

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'Nope.'
		);
	} );
} );
