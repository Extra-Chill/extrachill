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
const publicForm = readFileSync(
	path.resolve( __dirname, '../../venue-booking-inquiry/src/view.js' ),
	'utf8'
);

describe( 'booking form workspace', () => {
	it( 'prioritizes form building and keeps technical details optional', () => {
		expect( workspace.indexOf( '<IntakeTab' ) ).toBeLessThan(
			workspace.indexOf( 'Put this booking form on your website' )
		);
		expect( workspace.indexOf( 'Save booking form' ) ).toBeLessThan(
			workspace.indexOf( 'Put this booking form on your website' )
		);
		expect( workspace ).toContain( 'Copy website code' );
		expect( workspace ).toContain( 'View advanced website code' );
		expect( workspace ).toContain( 'ec-booking-form-editor__preview' );
		expect( workspace ).toContain( 'Preview booking form' );
		expect( workspace ).toContain(
			'Live preview of your public booking form.'
		);
		expect( workspace ).not.toContain( 'previewDevice' );
		expect( workspace ).not.toContain( 'QR' );
	} );

	it( 'prefills an empty embed website from the venue profile', () => {
		expect( workspace ).toContain(
			"bookingOriginFromWebsite( profile?.website || '' )"
		);
		expect( workspace ).toContain(
			'embed: { allowed_parent_origins: [ origin ] }'
		);
		expect( workspace ).toContain( 'onInitializeConfig( {' );
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
		expect( workspace ).toContain( 'Your venue website address' );
		expect( workspace ).toContain(
			'Entering the address does not publish the form.'
		);
		expect( workspace ).toMatch( /person\s+who\s+manages your website\./ );
		expect( workspace ).not.toContain( 'Authorize your venue website' );
		expect( workspace ).not.toContain( 'secure embed code' );
	} );

	it( 'names the always-asked questions beside the venue additions', () => {
		expect( intake ).toContain( 'Requested date' );
		expect( intake ).toContain( 'Artist or project name' );
		expect( intake ).toContain( 'What is your vision for the show?' );
		expect( intake ).toContain( 'Booking form questions' );
		expect( intake ).toContain( 'Add a question' );
		expect( intake ).toContain( 'ec-booking-field__type' );
		expect( intake ).toContain( 'ec-booking-field__row' );
	} );

	it( 'keeps the question editor free of form-builder ceremony', () => {
		for ( const ceremony of [
			'<details',
			'<summary',
			'fieldset',
			'Move ${ field.label } up',
			'Move ${ field.label } down',
			'hasValidFieldOrder',
			'ec-booking-field__summary-meta',
			'ec-booking-field__position',
		] ) {
			expect( intake ).not.toContain( ceremony );
		}
	} );

	it( 'offers only the answer shapes that are not already standard fields', () => {
		expect( intake ).toContain( "[ 'text', 'Short answer' ]" );
		expect( intake ).toContain( "[ 'textarea', 'Long answer' ]" );
		expect( intake ).toContain( "[ 'url', 'Link' ]" );
		expect( intake ).toContain( "[ 'select', 'Choose one' ]" );
		for ( const retired of [
			"'Email address'",
			"'Phone number'",
			"'Number'",
			"'Checkbox'",
			"'List of links'",
		] ) {
			expect( intake ).not.toContain( retired );
		}
	} );

	it( 'keeps custom field labels on theme-native form surfaces', () => {
		const labelInput = intake.match(
			/<input[\s\S]*?className="ec-booking-field__label"[\s\S]*?\/>/
		)?.[ 0 ];

		expect( labelInput ).toContain( 'type="text"' );
		expect( styles ).not.toContain( '.ec-booking-field__label {' );
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

	it( 'uses theme tokens and a compact mobile field summary', () => {
		expect( styles ).toContain( '.ec-booking-field__summary-meta' );
		expect( styles ).toContain( 'color: var(--muted-text)' );
		expect( styles ).toContain( 'background: var(--background-color)' );
		expect( styles ).toContain( '@media screen and (max-width: 480px)' );
		expect( styles ).toContain(
			'.ec-booking-field__summary-content {\n\t\tgrid-template-columns: auto minmax(0, 1fr);'
		);
	} );

	it( 'edits and renders link fields independently', () => {
		expect( publicForm ).toContain( 'visibleFields.map(' );
		expect( publicForm ).toContain( 'field.required &&' );
		expect( publicForm ).toContain( '! field.required &&' );
		expect( publicForm ).not.toContain( 'linkCollection' );
		expect( publicForm ).not.toContain( "label: 'Links'" );
	} );

	it( 'previews through the production booking form component', () => {
		expect( workspace ).toContain(
			"import { BookingInquiry } from '../../venue-booking-inquiry/src/view';"
		);
		expect( workspace ).toContain( '<BookingInquiry' );
		expect( workspace ).not.toContain( 'booking-embed=1' );
		expect( workspace ).toContain(
			"window.matchMedia( '(max-width: 1200px)' )"
		);
	} );
} );
