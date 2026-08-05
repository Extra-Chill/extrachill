/* eslint-disable no-undef -- Homeboy's file-scoped ESLint runner does not load the Jest environment. */

/**
 * WordPress dependencies
 */
import { createElement, createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

window.IS_REACT_ACT_ENVIRONMENT = true;

const mockCreate = jest.fn();
jest.mock(
	'extrachill-analytics-date-range',
	() => ( {
		__esModule: true,
		default: { create: ( ...args ) => mockCreate( ...args ), maxDays: 364 },
	} ),
	{ virtual: true }
);

/**
 * Internal dependencies
 */
import { DateRangeInput } from './date-range-input';

const INITIAL = { startDate: '2026-07-07', endDate: '2026-08-03' };
let root;
let container;

afterEach( () => {
	if ( root ) {
		act( () => root.unmount() );
	}
	root = undefined;
	container?.remove();
	container = undefined;
	mockCreate.mockReset();
} );

describe( 'DateRangeInput lifecycle', () => {
	it( 'creates once, syncs controlled values, and destroys on unmount', () => {
		const controller = {
			getRange: jest.fn( () => INITIAL ),
			setRange: jest.fn(),
			destroy: jest.fn(),
		};
		mockCreate.mockReturnValue( controller );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		const onChange = jest.fn();

		act( () => {
			root.render(
				createElement( DateRangeInput, {
					id: 'network-range',
					value: INITIAL,
					onChange,
				} )
			);
		} );

		expect( mockCreate ).toHaveBeenCalledTimes( 1 );
		expect( mockCreate.mock.calls[ 0 ][ 1 ] ).toMatchObject( {
			...INITIAL,
			maxDays: 364,
		} );

		const custom = { startDate: '2026-07-10', endDate: '2026-07-22' };
		controller.getRange.mockReturnValue( INITIAL );
		act( () => {
			root.render(
				createElement( DateRangeInput, {
					id: 'network-range',
					value: custom,
					onChange,
				} )
			);
		} );

		expect( mockCreate ).toHaveBeenCalledTimes( 1 );
		expect( controller.setRange ).toHaveBeenCalledWith(
			custom.startDate,
			custom.endDate,
			false
		);

		act( () => root.unmount() );
		root = undefined;
		expect( controller.destroy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'accepts only complete non-future ranges', () => {
		const controller = {
			getRange: jest.fn( () => INITIAL ),
			setRange: jest.fn(),
			destroy: jest.fn(),
		};
		mockCreate.mockReturnValue( controller );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		const onChange = jest.fn();

		act( () => {
			root.render(
				createElement( DateRangeInput, {
					id: 'network-range',
					value: INITIAL,
					onChange,
				} )
			);
		} );

		const options = mockCreate.mock.calls[ 0 ][ 1 ];
		act( () => options.onChange( null ) );
		expect( onChange ).not.toHaveBeenCalled();
		expect( controller.setRange ).toHaveBeenCalledWith(
			INITIAL.startDate,
			INITIAL.endDate,
			false
		);

		act( () =>
			options.onChange( {
				startDate: '2999-01-01',
				endDate: '2999-01-02',
			} )
		);
		expect( onChange ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain(
			'ending no later than yesterday'
		);

		const custom = { startDate: '2026-07-10', endDate: '2026-07-22' };
		act( () => {
			root.render(
				createElement( DateRangeInput, {
					id: 'network-range',
					value: custom,
					onChange,
				} )
			);
		} );
		expect( container.textContent ).not.toContain(
			'ending no later than yesterday'
		);
	} );
} );
