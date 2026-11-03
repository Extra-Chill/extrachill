import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import ScenePicker from '../../src/blocks/artist-manager/components/ScenePicker';
import { searchEventLocations } from '../../src/blocks/shared/api/client';

jest.mock( '../../src/blocks/shared/api/client', () => ( {
	searchEventLocations: jest.fn(),
} ) );
jest.mock( '@extrachill/components', () => ( {
	FieldGroup: ( { children } ) => <div>{ children }</div>,
	InlineStatus: ( { children } ) => <p>{ children }</p>,
} ) );

let container;
let root;
beforeEach( () => {
	global.IS_REACT_ACT_ENVIRONMENT = true;
	jest.useFakeTimers();
	container = document.createElement( 'div' );
	act( () => { root = createRoot( container ); } );
} );
afterEach( () => {
	act( () => root.unmount() );
	jest.useRealTimers();
	jest.clearAllMocks();
} );

const typeSearch = ( value ) => {
	act( () => {
		const input = container.querySelector( 'input' );
		Object.getOwnPropertyDescriptor( HTMLInputElement.prototype, 'value' ).set.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

test( 'uses canonical search, requires selection and returns the canonical slug and label', async () => {
	const onChange = jest.fn();
	searchEventLocations.mockResolvedValue( { locations: [ { slug: 'charleston', name: 'Charleston', hierarchy: { label: 'Charleston, SC' } } ] } );
	act( () => root.render( <ScenePicker slug="" label="" onChange={ onChange } /> ) );
	typeSearch( 'Charl' );
	expect( onChange ).toHaveBeenLastCalledWith( null, null, true );
	await act( async () => { jest.advanceTimersByTime( 300 ); } );
	expect( searchEventLocations ).toHaveBeenCalledWith( 'Charl' );
	expect( container.querySelector( 'button' ).textContent ).toBe( 'Charleston, SC' );
	act( () => container.querySelector( 'button' ).click() );
	expect( onChange ).toHaveBeenLastCalledWith( 'charleston', 'Charleston, SC', false );
} );

test( 'ignores stale search responses after switching artists', async () => {
	let resolve;
	searchEventLocations.mockReturnValue( new Promise( ( done ) => { resolve = done; } ) );
	act( () => root.render( <ScenePicker slug="" label="" onChange={ jest.fn() } /> ) );
	typeSearch( 'Charl' );
	await act( async () => { jest.advanceTimersByTime( 300 ); } );
	act( () => root.render( <ScenePicker slug="austin" label="Austin, TX" onChange={ jest.fn() } /> ) );
	await act( async () => resolve( { locations: [ { slug: 'charleston', name: 'Charleston' } ] } ) );
	expect( container.querySelector( 'button' ) ).toBeNull();
	expect( container.querySelector( 'input' ).value ).toBe( 'Austin, TX' );
} );
