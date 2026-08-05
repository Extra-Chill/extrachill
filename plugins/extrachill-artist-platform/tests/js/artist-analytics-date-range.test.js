// React's test-only act helper is supplied transitively by @wordpress/element.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import DateRangeControl from '../../src/blocks/artist-analytics/components/DateRangeControl';

describe( 'Artist Analytics date range control', () => {
	let container;
	let root;
	let controller;
	let options;

	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		controller = {
			reset: jest.fn(),
			destroy: jest.fn(),
		};
		window.ExtraChillAnalyticsDateRange = {
			create: jest.fn( ( input, createOptions ) => {
				options = createOptions;
				return controller;
			} ),
		};
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		delete window.ExtraChillAnalyticsDateRange;
	} );

	function render( selection, overrides = {} ) {
		const props = {
			selection,
			onSelectPreset: jest.fn(),
			onSelectCustom: jest.fn(),
			onSelectExact: jest.fn(),
			onReset: jest.fn(),
			...overrides,
		};
		act( () => root.render( <DateRangeControl { ...props } /> ) );
		return props;
	}

	test( 'initializes only for custom mode and destroys on mode change', () => {
		render( { mode: 'preset', days: 30 } );
		expect(
			window.ExtraChillAnalyticsDateRange.create
		).not.toHaveBeenCalled();

		render( { mode: 'exact', startDate: null, endDate: null } );
		expect(
			window.ExtraChillAnalyticsDateRange.create
		).toHaveBeenCalledWith(
			expect.any( window.HTMLInputElement ),
			expect.objectContaining( { maxDays: 90 } )
		);

		render( { mode: 'preset', days: 7 } );
		expect( controller.destroy ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'delivers exact ranges and resets through the shared controller', () => {
		const onSelectExact = jest.fn();
		const onReset = jest.fn();
		render(
			{ mode: 'exact', startDate: null, endDate: null },
			{ onSelectExact, onReset }
		);

		act( () =>
			options.onChange( {
				startDate: '2026-06-01',
				endDate: '2026-06-30',
			} )
		);
		expect( onSelectExact ).toHaveBeenCalledWith( {
			startDate: '2026-06-01',
			endDate: '2026-06-30',
		} );

		act( () =>
			container
				.querySelector( 'button' )
				.dispatchEvent(
					new window.MouseEvent( 'click', { bubbles: true } )
				)
		);
		expect( controller.reset ).toHaveBeenCalledTimes( 1 );
		expect( onReset ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'shows shared runtime validation failures accessibly', () => {
		render( { mode: 'exact', startDate: null, endDate: null } );

		act( () => options.onError( new RangeError( 'Maximum 90 days.' ) ) );

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'Maximum 90 days.'
		);
	} );
} );
