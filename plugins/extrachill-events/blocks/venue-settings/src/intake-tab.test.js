/* global afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import { createRoot, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { IntakeTab } from './intake-tab';
import { hasValidFieldOrder } from './intake-field-order';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children, className } ) =>
		React.createElement( 'div', { className }, children );
	return {
		FieldGroup: ( { children, className, help, label } ) =>
			React.createElement(
				'div',
				{ className },
				React.createElement( 'span', null, label ),
				children,
				help && React.createElement( 'span', null, help )
			),
		Panel: Wrapper,
		PanelHeader: ( { description, title } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'h2', null, title ),
				React.createElement( 'p', null, description )
			),
	};
} );

const presentation = {
	artist_name_label: 'Artist or project name',
	contact_name_label: 'Contact name',
	contact_email_label: 'Contact email',
	contact_phone_label: 'Contact phone',
	message_label: 'Anything else?',
	message_help: 'Share details.',
};

const field = ( index, overrides = {} ) => ( {
	key: `field_${ index }`,
	label: `Question ${ index }`,
	type: index % 3 === 0 ? 'select' : 'text',
	required: index % 2 === 0,
	options: index % 3 === 0 ? [ 'Yes', 'No' ] : [],
	visible_when: null,
	...overrides,
} );

function Harness( { initialFields } ) {
	const [ config, setConfig ] = useState( {
		intake: { fields: initialFields, presentation },
	} );
	return <IntakeTab config={ config } setConfig={ setConfig } />;
}

async function renderIntake( fields ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () =>
		root.render( <Harness initialFields={ fields } /> )
	);
	return { container, root };
}

async function click( element ) {
	await act( async () => {
		element.click();
		await Promise.resolve();
	} );
}

describe( 'custom booking field disclosures', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'keeps the empty state clear and ready for its first field', async () => {
		const { container, root } = await renderIntake( [] );

		expect( container.textContent ).toContain( 'No custom fields added.' );
		expect(
			container.querySelectorAll( '.ec-booking-field' )
		).toHaveLength( 0 );
		expect( container.textContent ).toContain( 'Add custom field' );

		await act( async () => root.unmount() );
	} );

	it( 'keeps a 12-field form scannable and opens one editor at a time', async () => {
		const { container, root } = await renderIntake(
			Array.from( { length: 12 }, ( unused, index ) =>
				field( index + 1 )
			)
		);
		const disclosures = [
			...container.querySelectorAll( '.ec-booking-field' ),
		];
		const summaries = disclosures.map( ( item ) =>
			item.querySelector( 'summary' )
		);

		expect( disclosures ).toHaveLength( 12 );
		expect( disclosures.every( ( item ) => ! item.open ) ).toBe( true );
		expect( summaries[ 1 ].textContent ).toContain( 'Question 2' );
		expect( summaries[ 1 ].textContent ).toContain( 'Short text' );
		expect( summaries[ 1 ].textContent ).toContain( 'Required' );
		expect( summaries[ 2 ].textContent ).toContain( 'Multiple choice' );
		expect( summaries[ 2 ].textContent ).toContain( 'Optional' );

		await click( summaries[ 0 ] );
		expect( disclosures[ 0 ].open ).toBe( true );
		await click( summaries[ 1 ] );
		expect( disclosures.filter( ( item ) => item.open ) ).toEqual( [
			disclosures[ 1 ],
		] );

		await act( async () => root.unmount() );
	} );

	it( 'opens and focuses a newly added field', async () => {
		const { container, root } = await renderIntake( [ field( 1 ) ] );
		const addButton = [ ...container.querySelectorAll( 'button' ) ].find(
			( button ) => button.textContent === 'Add custom field'
		);

		await click( addButton );
		const disclosures = container.querySelectorAll( '.ec-booking-field' );
		const newDisclosure = disclosures[ disclosures.length - 1 ];
		const newLabel = newDisclosure.querySelector(
			'input[aria-label="Field 2 label"]'
		);

		expect( newDisclosure.open ).toBe( true );
		expect( document.activeElement ).toBe( newLabel );
		expect(
			newDisclosure.querySelector( 'summary' ).textContent
		).toContain( 'New field' );

		await act( async () => root.unmount() );
	} );

	it( 'retains accessible reorder controls and referenced-field removal rules', async () => {
		const controller = field( 1 );
		const dependent = field( 2, {
			visible_when: { field: controller.key, value: 'Yes' },
		} );
		const { container, root } = await renderIntake( [
			controller,
			dependent,
		] );

		await click( container.querySelector( 'summary' ) );
		expect(
			container.querySelector( 'button[aria-label="Move Question 1 up"]' )
		).toHaveProperty( 'disabled', true );
		expect(
			container.querySelector(
				'button[aria-label="Move Question 1 down"]'
			)
		).toHaveProperty( 'disabled', true );
		expect(
			[ ...container.querySelectorAll( 'button' ) ].find(
				( button ) => button.textContent === 'Remove'
			)
		).toHaveProperty( 'disabled', true );
		expect( container.textContent ).toContain(
			'This field controls another saved field and cannot be removed.'
		);

		await act( async () => root.unmount() );
	} );
} );

describe( 'booking custom field order', () => {
	it( 'allows independent fields in any order', () => {
		expect(
			hasValidFieldOrder( [
				{ key: 'draw', visible_when: null },
				{ key: 'genre', visible_when: null },
			] )
		).toBe( true );
	} );

	it( 'requires a controlling field to remain before its dependent field', () => {
		const controller = { key: 'event_type', visible_when: null };
		const dependent = {
			key: 'other_event',
			visible_when: { field: 'event_type', value: 'Other' },
		};
		expect( hasValidFieldOrder( [ controller, dependent ] ) ).toBe( true );
		expect( hasValidFieldOrder( [ dependent, controller ] ) ).toBe( false );
	} );
} );
