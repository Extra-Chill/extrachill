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
		intake: { fields: initialFields },
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

const addButton = ( container ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( button ) => button.textContent === 'Add a question'
	);

describe( 'booking form question editor', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'tells the venue which questions are always asked', async () => {
		const { container, root } = await renderIntake( [] );

		expect( container.textContent ).toContain( 'Requested date' );
		expect( container.textContent ).toContain( 'Artist or project name' );
		expect( container.textContent ).toContain(
			'What is your vision for the show?'
		);
		expect( container.textContent ).toContain( 'Add a question' );

		await act( async () => root.unmount() );
	} );

	it( 'shows every question inline with no disclosure to open', async () => {
		const { container, root } = await renderIntake(
			Array.from( { length: 4 }, ( unused, index ) => field( index + 1 ) )
		);

		expect(
			container.querySelectorAll( '.ec-booking-field' )
		).toHaveLength( 4 );
		expect( container.querySelectorAll( 'details' ) ).toHaveLength( 0 );
		expect( container.querySelectorAll( 'summary' ) ).toHaveLength( 0 );

		await act( async () => root.unmount() );
	} );

	it( 'offers only the four answer shapes a venue needs', async () => {
		const { container, root } = await renderIntake( [ field( 1 ) ] );
		const options = [
			...container.querySelector( '.ec-booking-field__type' ).options,
		].map( ( option ) => option.textContent );

		expect( options ).toEqual( [
			'Short answer',
			'Long answer',
			'Link',
			'Choose one',
		] );

		await act( async () => root.unmount() );
	} );

	it( 'preserves a saved answer shape the editor no longer offers', async () => {
		const { container, root } = await renderIntake( [
			field( 1, { type: 'url_list' } ),
		] );
		const select = container.querySelector( '.ec-booking-field__type' );

		expect( select.value ).toBe( 'url_list' );
		expect(
			[ ...select.options ].map( ( option ) => option.value )
		).toContain( 'url_list' );

		await act( async () => root.unmount() );
	} );

	it( 'adds an empty question and focuses it for typing', async () => {
		const { container, root } = await renderIntake( [ field( 1 ) ] );

		await click( addButton( container ) );
		const rows = container.querySelectorAll( '.ec-booking-field' );
		const added = rows[ rows.length - 1 ].querySelector(
			'.ec-booking-field__label'
		);

		expect( rows ).toHaveLength( 2 );
		expect( added.value ).toBe( '' );
		expect( added.placeholder ).toBe( 'Ask a question' );
		expect( document.activeElement ).toBe( added );

		await act( async () => root.unmount() );
	} );

	it( 'removes a question without confirmation ceremony', async () => {
		const { container, root } = await renderIntake( [
			field( 1 ),
			field( 2 ),
		] );

		await click(
			container.querySelector( 'button[aria-label="Remove Question 1"]' )
		);

		const remaining = [
			...container.querySelectorAll( '.ec-booking-field__label' ),
		].map( ( input ) => input.value );

		expect( remaining ).toEqual( [ 'Question 2' ] );

		await act( async () => root.unmount() );
	} );

	it( 'protects a saved question another question still depends on', async () => {
		const controller = field( 1 );
		const dependent = field( 2, {
			visible_when: { field: controller.key, value: 'Yes' },
		} );
		const { container, root } = await renderIntake( [
			controller,
			dependent,
		] );

		expect(
			container.querySelector( 'button[aria-label="Remove Question 1"]' )
		).toHaveProperty( 'disabled', true );
		expect( container.textContent ).toContain(
			'Another question depends on this answer, so it cannot be removed.'
		);

		await act( async () => root.unmount() );
	} );
} );
