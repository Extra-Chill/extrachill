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
		expect( workspace ).toContain( "Website where you'll embed this form" );
		expect( workspace ).toContain(
			'You can place the form on any page of that website.'
		);
	} );

	it( 'separates universal booking fields from venue custom fields', () => {
		expect( wording ).toContain( 'Standard fields' );
		expect( wording ).toContain( 'Requested date (required)' );
		expect( wording ).toContain( 'Contact email (required)' );
		expect( intake ).toContain( 'Custom fields' );
		expect( intake ).toContain( 'Add custom field' );
		expect( intake ).toContain( 'Field type' );
		expect( intake ).toContain( 'Multiple choice' );
		expect( intake ).toContain( 'Enter one choice per line.' );
		expect( intake ).not.toContain( 'Add question' );
	} );

	it( 'uses complete theme button variants', () => {
		expect( workspace ).toContain( 'button-1 button-medium' );
		expect( intake ).toContain( 'button-2 button-medium' );
		expect( intake ).toContain( 'button-danger button-small' );
		expect( workspace + intake ).not.toContain( 'button-link-delete' );
	} );

	it( 'edits and renders link fields independently', () => {
		expect( intake ).toContain( "[ 'url', 'Website link' ]" );
		expect( intake ).toContain( "[ 'url_list', 'List of links' ]" );
		expect( publicForm ).toContain( 'visibleFields.map( ( field )' );
		expect( publicForm ).not.toContain( 'linkCollection' );
		expect( publicForm ).not.toContain( "label: 'Links'" );
	} );

	it( 'previews through the production booking form component', () => {
		expect( workspace ).toContain(
			"import { BookingInquiry } from '../../venue-booking-inquiry/src/view';"
		);
		expect( workspace ).toContain( '<BookingInquiry' );
		expect( workspace ).not.toContain( 'booking-embed=1' );
	} );
} );
