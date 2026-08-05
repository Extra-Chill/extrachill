/* eslint-disable no-undef -- Homeboy's file-scoped ESLint runner does not load the Jest environment. */

/**
 * Internal dependencies
 */
import {
	createPresetRange,
	endsAfterYesterday,
	getPresetDays,
	getRangeDays,
} from './date-range-state';

const TODAY = new Date( '2026-08-04T23:00:00.000Z' );

describe( 'Network date range state', () => {
	it( 'creates canonical inclusive presets ending yesterday', () => {
		expect( createPresetRange( 28, TODAY ) ).toEqual( {
			startDate: '2026-07-07',
			endDate: '2026-08-03',
		} );
		expect( getRangeDays( createPresetRange( 364, TODAY ) ) ).toBe( 364 );
	} );

	it( 'derives preset selection without separate preset state', () => {
		expect( getPresetDays( createPresetRange( 84, TODAY ), TODAY ) ).toBe(
			84
		);
		expect(
			getPresetDays(
				{ startDate: '2026-07-01', endDate: '2026-07-19' },
				TODAY
			)
		).toBeNull();
	} );

	it( 'rejects ranges that end after yesterday', () => {
		expect(
			endsAfterYesterday(
				{ startDate: '2026-08-01', endDate: '2026-08-04' },
				TODAY
			)
		).toBe( true );
		expect(
			endsAfterYesterday(
				{ startDate: '2026-08-01', endDate: '2026-08-03' },
				TODAY
			)
		).toBe( false );
	} );
} );
