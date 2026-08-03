/* global describe, expect, it */

/**
 * Internal dependencies
 */
import {
	bookingButtonSnippet,
	bookingEmbedSnippet,
	normalizeBookingOrigin,
} from './booking-embed';

describe( 'venue booking embed generator', () => {
	it( 'accepts only exact standard-port HTTPS origins', () => {
		expect( normalizeBookingOrigin( 'https://Venue.Example' ) ).toBe(
			'https://venue.example'
		);
		[
			'http://venue.example',
			'https://venue.example/path',
			'https://user@venue.example',
			'https://*.example',
			'https://localhost',
			'https://127.0.0.1',
			'https://venue.example:8443',
		].forEach( ( origin ) =>
			expect( normalizeBookingOrigin( origin ) ).toBeNull()
		);
	} );

	it( 'builds accessible link and iframe markup without hand-edited IDs', () => {
		const link =
			'https://events.extrachill.com/venue/the-room/#booking-inquiry';
		expect( bookingButtonSnippet( link, 'The Room' ) ).toContain(
			'Book The Room'
		);
		const snippet = bookingEmbedSnippet(
			link,
			'The Room',
			'https://venue.example'
		);
		expect( snippet ).toContain( 'title="Book The Room"' );
		expect( snippet ).toContain( 'loading="lazy"' );
		expect( snippet ).toContain( 'Open Book The Room on Extra Chill' );
		expect( snippet ).toContain(
			'parent-origin=https%3A%2F%2Fvenue.example'
		);
	} );

	it( 'uses strict message source, origin, type, and bounded integer checks', () => {
		const snippet = bookingEmbedSnippet(
			'https://events.extrachill.com/venue/the-room/',
			'The Room',
			'https://venue.example'
		);
		expect( snippet ).toContain( 'e.source!==f.contentWindow' );
		expect( snippet ).toContain(
			"e.origin!=='https://events.extrachill.com'"
		);
		expect( snippet ).toContain( "d.type!=='extrachill:booking-height'" );
		expect( snippet ).toContain( 'Number.isInteger(d.height)' );
		expect( snippet ).not.toContain( "postMessage('*'" );
		expect( snippet ).not.toMatch(
			/contact|email|token|public_id|reference|intake/
		);
	} );
} );
