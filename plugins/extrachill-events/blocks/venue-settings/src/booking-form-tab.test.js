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
const styles = readFileSync( path.resolve( __dirname, 'style.scss' ), 'utf8' );
const wording = readFileSync(
	path.resolve( __dirname, 'intake-public.js' ),
	'utf8'
);
const publicForm = readFileSync(
	path.resolve( __dirname, '../../venue-booking-inquiry/src/view.js' ),
	'utf8'
);

describe( 'booking form workspace', () => {
	it( 'prioritizes form building and keeps technical details optional', () => {
		expect( workspace.indexOf( '<IntakeTab' ) ).toBeLessThan(
			workspace.indexOf( 'Embed your booking form' )
		);
		expect( workspace.indexOf( 'Save booking form' ) ).toBeLessThan(
			workspace.indexOf( 'Embed your booking form' )
		);
		expect( workspace ).toContain( 'Copy embed code' );
		expect( workspace ).toContain( 'Show advanced embed code' );
		expect( workspace ).toContain( 'ec-booking-form-editor__preview' );
		expect( workspace ).toContain( 'Preview booking form' );
		expect( workspace ).toContain(
			'Live preview of your public booking form.'
		);
		expect( workspace ).not.toContain( 'previewDevice' );
		expect( workspace ).not.toContain( 'QR' );
		expect( wording ).toContain( 'Edit standard wording' );
	} );

	it( 'prefills an empty embed website from the venue profile', () => {
		expect( workspace ).toContain(
			"bookingOriginFromWebsite( profile?.website || '' )"
		);
		expect( workspace ).toContain(
			'embed: { allowed_parent_origins: [ origin ] }'
		);
		expect( workspace ).toContain(
			'initializedWebsite.current || websites.length'
		);
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
		expect( intake ).toContain( 'ec-booking-field__type' );
		expect( intake ).toContain( 'Multiple choice' );
		expect( intake ).toContain( 'Enter one choice per line.' );
		expect( intake ).toContain( 'ec-booking-field__row' );
		expect( intake ).toContain( 'Move ${ field.label } up' );
		expect( intake ).toContain( 'Move ${ field.label } down' );
		expect( intake ).not.toContain( 'Add question' );
		expect( intake ).not.toContain( 'fieldset' );
	} );

	it( 'uses complete theme button variants', () => {
		expect( workspace ).toContain( 'button-1 button-medium' );
		expect( intake ).toContain( 'ec-booking-fields__add' );
		expect( intake ).toContain(
			'ec-booking-fields__add button-2 button-medium button-block'
		);
		expect( styles ).not.toContain( '.ec-booking-fields__add {' );
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
