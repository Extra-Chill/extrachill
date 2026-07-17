import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import { TermPicker } from '../../src/term-picker/TermPicker';
import type { TaxonomyConfig } from '../../src/term-picker/types';

function renderModalMarkup( autoOpen: boolean ) {
	document.body.innerHTML = `
		<a id="new-topic-modal-trigger" data-modal-mode="discussion" href="#new-post">Create Discussion</a>
		<div id="new-topic-modal-overlay"></div>
		<div id="new-topic-modal" data-auto-open="${ autoOpen }">
			<button type="button" class="new-topic-modal-close">Close</button>
			<h2 id="new-topic-modal-title">Create Discussion</h2>
			<p id="new-topic-modal-description"></p>
			<p><select id="bbp_forum_id"><option value="1">Music</option></select></p>
			<input id="bbp_topic_title" type="text" />
			<textarea id="bbp_topic_content"></textarea>
		</div>`;
}

describe( 'discussion composer continuation', () => {
	beforeAll( () => {
		const reactGlobal = globalThis as typeof globalThis & {
			IS_REACT_ACT_ENVIRONMENT: boolean;
		};
		reactGlobal.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		jest.resetModules();
	} );

	it( 'auto-opens the existing discussion modal when instructed', () => {
		renderModalMarkup( true );

		jest.isolateModules( () => {
			require( '../../inc/home/assets/js/new-topic-modal.js' );
		} );

		expect(
			document.getElementById( 'new-topic-modal' )?.classList
		).toContain( 'is-open' );
		expect(
			document.getElementById( 'new-topic-modal-overlay' )?.classList
		).toContain( 'is-open' );
		expect( document.body.classList ).toContain( 'new-topic-modal-open' );
		const modal = document.getElementById( 'new-topic-modal' );
		expect( modal?.ownerDocument.activeElement ).toBe(
			document.getElementById( 'bbp_topic_title' )
		);
		expect(
			document.getElementById( 'new-topic-modal-description' )
				?.textContent
		).toBe( 'Start a new topic in the community.' );
	} );

	it( 'does not auto-open the normal composer without continuation state', () => {
		renderModalMarkup( false );

		jest.isolateModules( () => {
			require( '../../inc/home/assets/js/new-topic-modal.js' );
		} );

		expect(
			document.getElementById( 'new-topic-modal' )?.classList
		).not.toContain( 'is-open' );
	} );

	it( 'renders a server-validated initial term through the existing picker', () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		const config: TaxonomyConfig = {
			taxonomy: 'artist',
			restBase: 'artist',
			label: 'Artist',
			placeholder: 'Search artists...',
			hierarchical: false,
			field: 'bbp_topic_artist',
			selected: [ { id: 42, name: 'Phish', parent: 0 } ],
		};

		act( () => {
			root.render( <TermPicker config={ config } /> );
		} );

		const selectedInput = container.querySelector< HTMLInputElement >(
			'input[name="bbp_topic_artist[]"]'
		);
		expect( selectedInput?.value ).toBe( '42' );
		expect( container.textContent ).toContain( 'Phish' );

		act( () => root.unmount() );
	} );
} );
