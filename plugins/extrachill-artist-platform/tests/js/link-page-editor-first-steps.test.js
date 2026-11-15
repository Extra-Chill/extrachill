/**
 * New-artist editor UX: a blank page offers "Add Link" directly (#1447) and
 * never shows a "Saved" state before anything was saved (#1446).
 */
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server.node';

let mockEditor = {};
jest.mock( '../../src/blocks/link-page-editor/context/EditorContext', () => ( {
	useEditor: () => mockEditor,
} ) );
jest.mock( '../../src/blocks/shared/components/DraggableList', () => ( { items, renderItem } ) =>
	( items || [] ).map( ( item, i ) => renderItem( item, i ) )
);
jest.mock( '@extrachill/components', () => {
	const Pass = ( { children, title, actions } ) => (
		<div>
			{ title }
			{ actions }
			{ children }
		</div>
	);
	return {
		ActionRow: Pass,
		BlockShell: Pass,
		BlockShellHeader: Pass,
		BlockShellInner: Pass,
		InlineStatus: Pass,
		Panel: Pass,
		ResponsiveTabs: ( { renderPanel } ) => renderPanel(),
	};
}, { virtual: true } );
jest.mock( '../../src/blocks/shared/components/ArtistSwitcher', () => () => null );
jest.mock( '../../src/blocks/link-page-editor/components/Preview', () => () => null );
jest.mock( '../../src/blocks/link-page-editor/components/shared/LinkPageUrl', () => () => null );
jest.mock( '../../src/blocks/link-page-editor/components/shared/QRCodeModal', () => () => null );
jest.mock( '../../src/blocks/link-page-editor/components/tabs/TabInfo', () => () => null );

const TabLinks = require( '../../src/blocks/link-page-editor/components/tabs/TabLinks' ).default;
const Editor = require( '../../src/blocks/link-page-editor/components/Editor' ).default;

const baseEditor = ( overrides = {} ) => ( {
	links: [],
	updateLinks: jest.fn(),
	settings: {},
	artistId: 1,
	activeTab: 'info',
	setActiveTab: jest.fn(),
	isLoading: false,
	isSaving: false,
	error: null,
	saveError: null,
	hasUnsavedChanges: false,
	userArtists: [ { id: 1, slug: 'band' } ],
	artist: { slug: 'band' },
	saveAll: jest.fn(),
	switchArtist: jest.fn(),
	previewData: { links: [], bio: '', profileImageUrl: '', socials: [] },
	...overrides,
} );

beforeAll( () => {
	window.ecLinkPageEditorConfig = { docsHelpUrl: '#', docsAdvancedHelpUrl: '#' };
} );

const render = ( Component ) => {
	const el = document.createElement( 'div' );
	el.innerHTML = renderToStaticMarkup( <Component /> );
	return el;
};

test( 'empty Links tab offers Add Link without a section first', () => {
	mockEditor = baseEditor();
	const el = render( TabLinks );
	expect( el.querySelector( '.ec-lpe-links-empty__add-link' ).textContent ).toBe( 'Add Link' );
} );

test( 'Links tab with sections hides the empty-state button', () => {
	mockEditor = baseEditor( { links: [ { id: 's1', section_title: '', links: [] } ] } );
	expect( render( TabLinks ).querySelector( '.ec-lpe-links-empty' ) ).toBeNull();
} );

test( 'blank page shows first steps and no Saved label', () => {
	mockEditor = baseEditor();
	const el = render( Editor );
	expect( el.querySelector( '.ec-editor__first-steps' ) ).not.toBeNull();
	expect( el.textContent ).not.toMatch( /\bSaved\b/ );
	expect( el.textContent ).toContain( 'Save changes' );
} );

test( 'a page with content and no edits still shows Saved', () => {
	mockEditor = baseEditor( {
		previewData: { links: [ { links: [ { link_text: 'x' } ] } ], bio: '', profileImageUrl: '', socials: [] },
	} );
	const el = render( Editor );
	expect( el.querySelector( '.ec-editor__first-steps' ) ).toBeNull();
	expect( el.textContent ).toContain( 'Saved' );
} );
