import apiFetch from '@wordpress/api-fetch';
import { getAnalytics } from '../../src/blocks/shared/api/client';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'Artist Analytics API client', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { summary: {} } );
	} );

	it( 'serializes exact dates through the released shared client', async () => {
		await getAnalytics( 42, {
			start_date: '2026-06-01',
			end_date: '2026-06-30',
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: 'extrachill/v1/artists/42/analytics?start_date=2026-06-01&end_date=2026-06-30',
			} )
		);
	} );
} );
