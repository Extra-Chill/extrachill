/* global beforeEach, describe, expect, it, jest */

const mockFlatpickr = jest.fn();

jest.mock( 'flatpickr', () => ( {
	__esModule: true,
	default: ( input, options ) => mockFlatpickr( input, options ),
} ) );

jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ), {
	virtual: true,
} );

/**
 * Internal dependencies
 */
import DateRange from './date-range';

describe( 'shared analytics date range', () => {
	let input;
	let instance;
	let config;

	beforeEach( () => {
		document.body.innerHTML = '<input id="range">';
		input = document.querySelector( '#range' );
		mockFlatpickr.mockReset();
		mockFlatpickr.mockImplementation( ( _input, options ) => {
			config = options;
			instance = {
				prevMonthNav: document.createElement( 'span' ),
				nextMonthNav: document.createElement( 'span' ),
				setDate: jest.fn(),
				clear: jest.fn( ( trigger = true ) => {
					if ( trigger ) {
						config.onChange( [], '', instance );
					}
				} ),
				destroy: jest.fn(),
			};
			options.onReady( [], '', instance );
			return instance;
		} );
	} );

	it( 'hydrates canonical dates and emits complete ranges only', () => {
		const onChange = jest.fn();
		const controller = DateRange.create( input, {
			startDate: '2026-07-01',
			endDate: '2026-07-28',
			onChange,
		} );

		expect( config.defaultDate ).toEqual( [ '2026-07-01', '2026-07-28' ] );
		config.onChange( [ new Date( 2026, 6, 2 ) ], '', instance );
		expect( onChange ).not.toHaveBeenCalled();
		config.onChange(
			[ new Date( 2026, 6, 2 ), new Date( 2026, 6, 8 ) ],
			'',
			instance
		);
		expect( onChange ).toHaveBeenCalledWith( {
			startDate: '2026-07-02',
			endDate: '2026-07-08',
		} );
		expect( controller.getRange() ).toEqual( {
			startDate: '2026-07-02',
			endDate: '2026-07-08',
		} );
	} );

	it( 'supports keyboard navigation and removes handlers on destroy', () => {
		const controller = DateRange.create( input );
		const click = jest.fn();
		instance.prevMonthNav.addEventListener( 'click', click );

		expect( instance.prevMonthNav.getAttribute( 'role' ) ).toBe( 'button' );
		expect( instance.prevMonthNav.getAttribute( 'aria-label' ) ).toBe(
			'Previous month'
		);
		instance.prevMonthNav.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'Enter' } )
		);
		expect( click ).toHaveBeenCalledTimes( 1 );

		controller.destroy();
		instance.prevMonthNav.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'Enter' } )
		);
		expect( click ).toHaveBeenCalledTimes( 1 );
		expect( instance.destroy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears, resets, and applies bounded presets', () => {
		const onChange = jest.fn();
		const controller = DateRange.create( input, {
			startDate: '2026-07-01',
			endDate: '2026-07-28',
			onChange,
			maxDays: 30,
		} );

		controller.clear();
		expect( onChange ).toHaveBeenLastCalledWith( null );
		controller.reset();
		expect( instance.setDate ).toHaveBeenLastCalledWith(
			[ '2026-07-01', '2026-07-28' ],
			false
		);
		controller.setPreset( { days: 7, endDate: '2026-08-04' } );
		expect( onChange ).toHaveBeenLastCalledWith( {
			startDate: '2026-07-29',
			endDate: '2026-08-04',
		} );
		expect( () =>
			controller.setPreset( { days: 31, endDate: '2026-08-04' } )
		).toThrow( RangeError );
	} );

	it( 'rejects oversized picker selections without delivering a change', () => {
		const onChange = jest.fn();
		const onError = jest.fn();
		DateRange.create( input, { maxDays: 7, onChange, onError } );

		config.onChange(
			[ new Date( 2026, 6, 1 ), new Date( 2026, 6, 8 ) ],
			'',
			instance
		);

		expect( onChange ).not.toHaveBeenCalled();
		expect( onError.mock.calls[ 0 ][ 0 ] ).toBeInstanceOf( RangeError );
		expect( instance.clear ).toHaveBeenCalledWith( false );
	} );
} );
