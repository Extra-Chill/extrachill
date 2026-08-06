/* global describe, expect, it */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';

const workspace = readFileSync(
	path.resolve( __dirname, 'booking-form-tab.js' ),
	'utf8'
);
const intake = readFileSync(
	path.resolve( __dirname, 'intake-tab.js' ),
	'utf8'
);
const wording = readFileSync(
	path.resolve( __dirname, 'intake-public.js' ),
	'utf8'
);
const publicForm = readFileSync(
	path.resolve( __dirname, '../../venue-booking-inquiry/src/view.js' ),
	'utf8'
);
const links = readFileSync(
	path.resolve( __dirname, 'booking-links.js' ),
	'utf8'
);

describe( 'booking form workspace', () => {
	it( 'puts secure embed setup first and keeps preview collapsed', () => {
		expect( workspace.indexOf( 'Embed your booking form' ) ).toBeLessThan(
			workspace.indexOf( '<IntakeTab' )
		);
		expect( workspace ).toContain( 'Copy embed code' );
		expect( workspace ).toContain( '<details' );
		expect( workspace ).toContain( '<summary>' );
		expect( workspace ).toContain( 'Preview booking form' );
		expect( workspace ).not.toContain( 'previewDevice' );
		expect( workspace ).not.toContain( 'QR' );
		expect( wording ).toContain( 'Edit standard wording' );
	} );

	it( 'hides schema and legal implementation vocabulary', () => {
		for ( const vocabulary of [
			'label="Key"',
			'label="Type"',
			'Show only when',
			'Matching value',
			'Consent version',
			'Allowed parent origins',
		] ) {
			expect( workspace + intake ).not.toContain( vocabulary );
		}
		expect( workspace ).toContain( 'Allowed websites' );
	} );

	it( 'uses one logical links row while preserving underlying payload keys', () => {
		expect( intake ).toContain( "key: 'links'" );
		expect( intake ).toContain( "type: 'url_list'" );
		expect( intake ).toContain( "key={ linkRow ? 'links' : field.key }" );
		expect( publicForm ).toContain( 'updateLinkCollection' );
		expect( links ).toContain( "field.type === 'url'" );
		expect( links ).toContain( 'links.splice( 0, 20 )' );
		expect( publicForm ).not.toContain( 'linkFields.map( ( field )' );
	} );

	it( 'previews through the production booking form component', () => {
		expect( workspace ).toContain(
			"import { BookingInquiry } from '../../venue-booking-inquiry/src/view';"
		);
		expect( workspace ).toContain( '<BookingInquiry' );
		expect( workspace ).not.toContain( 'booking-embed=1' );
	} );
} );
