/* global describe, expect, it */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';

/**
 * Internal dependencies
 */
import { bookingQrRequest } from './booking-appearance';

describe( 'booking form workspace', () => {
	it( 'generates QR codes only for the canonical booking anchor', () => {
		const url = 'https://events.example/venue/the-room/#booking-inquiry';
		expect( bookingQrRequest( url, 1000 ) ).toEqual( {
			path: '/extrachill/v1/tools/qr-code',
			method: 'POST',
			data: { url, size: 1000 },
		} );
		expect( bookingQrRequest( url, 1000 ).data.url ).not.toContain(
			'booking-embed'
		);
	} );

	it( 'previews through the production booking form component', () => {
		const source = readFileSync(
			path.resolve( __dirname, 'booking-form-tab.js' ),
			'utf8'
		);
		expect( source ).toContain(
			"import { BookingInquiry } from '../../venue-booking-inquiry/src/view';"
		);
		expect( source ).toContain( '<BookingInquiry' );
		expect( source ).not.toContain( 'booking-embed=1' );
	} );
} );
