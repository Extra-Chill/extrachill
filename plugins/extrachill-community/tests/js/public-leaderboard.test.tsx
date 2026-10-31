import { createRoot } from '@wordpress/element';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@extrachill/components', () => {
	const Wrapper = ( { children }: { children: React.ReactNode } ) => <div>{ children }</div>;
	return { ActionRow: Wrapper, BlockShell: Wrapper, BlockShellHeader: Wrapper, BlockShellInner: Wrapper, Panel: Wrapper };
} );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );
import { Leaderboard } from '../../src/blocks/leaderboard/view';

test( 'anonymous initial page needs no discovery; pagination calls only public run', async () => {
	( globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean } ).IS_REACT_ACT_ENVIRONMENT = true;
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	const initial = { items: [], pagination: { total_pages: 2 } };
	( apiFetch as jest.Mock ).mockResolvedValue( initial );
	await act( async () => {
		root.render( <Leaderboard perPage={ 25 } spriteUrl="" initial={ initial as any } /> );
	} );
	expect( apiFetch ).not.toHaveBeenCalled();
	await act( async () => {
		( container.querySelectorAll( 'button' )[ 1 ] as HTMLButtonElement ).click();
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	const request = ( apiFetch as jest.Mock ).mock.calls[ 0 ][ 0 ];
	expect( request.method ).toBe( 'GET' );
	expect( request.path.split( '?' )[ 0 ] ).toBe( '/wp-abilities/v1/abilities/extrachill/users-leaderboard/run' );
	// Core's run controller only accepts bracketed query input; a JSON string 400s.
	const query = new URLSearchParams( request.path.split( '?' )[ 1 ] );
	expect( query.get( 'input[page]' ) ).toBe( '2' );
	expect( query.get( 'input[per_page]' ) ).toBe( '25' );
	expect( query.has( 'input' ) ).toBe( false );
	await act( async () => root.unmount() );
	container.remove();
} );
